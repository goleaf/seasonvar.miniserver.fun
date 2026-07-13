# Loaded Title Episode Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Исключить повторные previous/next SQL scans, вычисляя соседей из уже авторизованных серий активного сезона и используя существующий adjacent query только на реальной границе сезонов.

**Architecture:** `CatalogTitlePlayer` передаёт уже загруженные `$episodes` и `$seasons` в `CatalogTitlePlaybackQuery::episodeNavigation()`. Query service сохраняет hierarchy и release-lane ownership, выбирает локальных соседей без SQL и вызывает существующий `adjacentEpisode()` только если ordered season summaries доказывают наличие потенциального сезона с нужной стороны.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Eloquent, SQLite, PHPUnit 12.5, Laravel Pint.

## Global Constraints

- Работать только в существующей `main`; не создавать branch или worktree.
- Не менять primary-action selection, `episodesForSeason()`, `seasonSummaries()`, URL-state, player source или Blade.
- Не загружать все серии всех сезонов; переиспользовать только существующие render-local collections.
- Не добавлять dependency, migration, index, cache key, TTL, static mutable state или signed URL storage.
- Сохранять lane `(season.kind, episode.kind)`, canonical tuple-order и полную cross-season watchable boundary.
- Mismatched hierarchy или selected episode вне trusted active-season collection возвращает пустой DTO без SQL.
- Публичные Livewire properties и snapshot не меняются.
- Параллельные importer/search изменения не редактировать, не stage-ить и не включать в commits.
- Performance claim допустим только после одинаковых pre/post измерений без параллельного PHPUnit/load test.

## File Map

- Modify: `tests/Feature/CatalogTitlePlaybackQueryTest.php` — zero-query local lane, one-query cross-season fallback и invalid-collection regressions.
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php:201-225` — collection-aware navigation orchestration.
- Modify: `app/Livewire/CatalogTitlePlayer.php:318-320` — передача существующих `$episodes` и `$seasons`.
- Modify: `docs/performance.md` — literal pre/post query, SQL и HTTP evidence.
- Modify: `docs/MAINTENANCE_LOG.md` — выполненные проверки и измеренный результат.
- Modify: `CHANGELOG.md` — подтверждённое сокращение повторных playback lookups.

---

### Task 1: Реализовать bounded navigation по активному сезону test-first

**Files:**
- Modify: `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php:201-225`
- Modify: `app/Livewire/CatalogTitlePlayer.php:318-320`

**Interfaces:**
- Consumes: ordered `Collection<int, Episode> $activeSeasonEpisodes`, ordered `Collection<int, Season> $seasonSummaries`, existing `adjacentEpisode(CatalogTitle, ?User, Episode, bool): ?Episode`.
- Produces:

    public function episodeNavigation(
        CatalogTitle $catalogTitle,
        Season $season,
        ?User $user,
        Episode $episode,
        Collection $activeSeasonEpisodes,
        Collection $seasonSummaries,
    ): CatalogEpisodeNavigation

- [ ] **Step 1: Добавить focused imports и test helper**

In `tests/Feature/CatalogTitlePlaybackQueryTest.php`, add:

    use App\Enums\ReleaseKind;
    use Illuminate\Support\Facades\DB;

Before the final class brace, add:

    private function publishedEpisode(
        CatalogTitle $title,
        Season $season,
        int $number,
        int $sortOrder,
        ReleaseKind $kind = ReleaseKind::Regular,
    ): Episode {
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $number,
            'kind' => $kind,
            'sort_order' => $sortOrder,
        ]);

        $this->publishedMedia($title, $season, $episode);

        return $episode;
    }

- [ ] **Step 2: Написать failing zero-query local-navigation test**

Add to `CatalogTitlePlaybackQueryTest`:

    public function test_episode_navigation_uses_the_loaded_season_lane_without_database_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $first = $this->publishedEpisode($title, $season, 1, 1);
        $middle = $this->publishedEpisode($title, $season, 2, 2);
        $last = $this->publishedEpisode($title, $season, 3, 3);
        $special = $this->publishedEpisode($title, $season, 1, 1, ReleaseKind::Special);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $episodes = $playback->episodesForSeason($title, $season, null);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $firstNavigation = $playback->episodeNavigation($title, $season, null, $first, $episodes, $seasons);
        $middleNavigation = $playback->episodeNavigation($title, $season, null, $middle, $episodes, $seasons);
        $lastNavigation = $playback->episodeNavigation($title, $season, null, $last, $episodes, $seasons);
        $specialNavigation = $playback->episodeNavigation($title, $season, null, $special, $episodes, $seasons);
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertNull($firstNavigation->previous);
        $this->assertSame($middle->id, $firstNavigation->next?->id);
        $this->assertSame($first->id, $middleNavigation->previous?->id);
        $this->assertSame($last->id, $middleNavigation->next?->id);
        $this->assertSame($middle->id, $lastNavigation->previous?->id);
        $this->assertNull($lastNavigation->next);
        $this->assertNull($specialNavigation->previous);
        $this->assertNull($specialNavigation->next);
        $this->assertSame(0, $queryCount);
    }

- [ ] **Step 3: Написать failing one-query cross-season test**

Add:

    public function test_episode_navigation_queries_only_the_crossed_season_boundary(): void
    {
        $title = CatalogTitle::factory()->create();
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $first = $this->publishedEpisode($title, $firstSeason, 1, 1);
        $last = $this->publishedEpisode($title, $firstSeason, 2, 2);
        $next = $this->publishedEpisode($title, $secondSeason, 1, 1);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $firstSeasonEpisodes = $playback->episodesForSeason($title, $firstSeason, null);
        $secondSeasonEpisodes = $playback->episodesForSeason($title, $secondSeason, null);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $forward = $playback->episodeNavigation(
            $title,
            $firstSeason,
            null,
            $last,
            $firstSeasonEpisodes,
            $seasons,
        );
        $forwardQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();

        $backward = $playback->episodeNavigation(
            $title,
            $secondSeason,
            null,
            $next,
            $secondSeasonEpisodes,
            $seasons,
        );
        $backwardQueryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($first->id, $forward->previous?->id);
        $this->assertSame($next->id, $forward->next?->id);
        $this->assertSame($last->id, $backward->previous?->id);
        $this->assertNull($backward->next);
        $this->assertSame(1, $forwardQueryCount);
        $this->assertSame(1, $backwardQueryCount);
    }

- [ ] **Step 4: Написать failing invalid-collection test**

Add:

    public function test_episode_navigation_rejects_an_episode_outside_the_loaded_season_without_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $loadedEpisode = $this->publishedEpisode($title, $season, 1, 1);
        $outsideEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $episodes = collect([$loadedEpisode]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $navigation = $playback->episodeNavigation(
            $title,
            $season,
            null,
            $outsideEpisode,
            $episodes,
            $seasons,
        );
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertNull($navigation->previous);
        $this->assertNull($navigation->next);
        $this->assertSame(0, $queryCount);
    }

- [ ] **Step 5: Запустить tests и подтвердить RED**

Run:

    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php

Expected: три новых tests FAIL. Текущий method игнорирует extra collections, поэтому local test выполняет repeated SQL, cross-season query counts превышают `1`, а episode вне collection получает database-derived navigation.

- [ ] **Step 6: Реализовать collection-aware navigation**

Replace `episodeNavigation()` in `app/Services/Catalog/CatalogTitlePlaybackQuery.php` with:

    /**
     * @param  Collection<int, Episode>  $activeSeasonEpisodes
     * @param  Collection<int, Season>  $seasonSummaries
     */
    public function episodeNavigation(
        CatalogTitle $catalogTitle,
        Season $season,
        ?User $user,
        Episode $episode,
        Collection $activeSeasonEpisodes,
        Collection $seasonSummaries,
    ): CatalogEpisodeNavigation {
        if ((int) $season->catalog_title_id !== $catalogTitle->id
            || (int) $episode->season_id !== $season->id
            || $activeSeasonEpisodes->contains(
                fn (Episode $candidate): bool => (int) $candidate->season_id !== $season->id,
            )) {
            return new CatalogEpisodeNavigation;
        }

        $episodeKind = $this->releaseKindValue($episode->kind);
        $seasonKind = $this->releaseKindValue($season->kind);

        if ($episodeKind === null || $seasonKind === null) {
            return new CatalogEpisodeNavigation;
        }

        $episodeLane = $activeSeasonEpisodes
            ->filter(
                fn (Episode $candidate): bool => $this->releaseKindValue($candidate->kind) === $episodeKind,
            )
            ->values();
        $episodeIndex = $episodeLane->search(
            fn (Episode $candidate): bool => $candidate->id === $episode->id,
        );

        if ($episodeIndex === false) {
            return new CatalogEpisodeNavigation;
        }

        $previous = $episodeIndex > 0 ? $episodeLane->get($episodeIndex - 1) : null;
        $next = $episodeIndex < $episodeLane->count() - 1 ? $episodeLane->get($episodeIndex + 1) : null;
        $seasonLane = $seasonSummaries
            ->filter(
                fn (Season $candidate): bool => (int) $candidate->catalog_title_id === $catalogTitle->id
                    && $this->releaseKindValue($candidate->kind) === $seasonKind
                    && (int) $candidate->getAttribute('available_episodes_count') > 0,
            )
            ->values();
        $seasonIndex = $seasonLane->search(
            fn (Season $candidate): bool => $candidate->id === $season->id,
        );

        if ($seasonIndex === false) {
            return new CatalogEpisodeNavigation;
        }

        $current = clone $episode;
        $current->setAttribute('season_order_kind', $seasonKind);
        $current->setAttribute('season_order_sort', $season->sort_order);
        $current->setAttribute('season_order_number', $season->number);
        $current->setAttribute('season_order_id', $season->id);

        if ($previous === null && $seasonIndex > 0) {
            $previous = $this->adjacentEpisode($catalogTitle, $user, $current, false);
        }

        if ($next === null && $seasonIndex < $seasonLane->count() - 1) {
            $next = $this->adjacentEpisode($catalogTitle, $user, $current, true);
        }

        return new CatalogEpisodeNavigation(previous: $previous, next: $next);
    }

- [ ] **Step 7: Передать существующие collections из Livewire**

In `app/Livewire/CatalogTitlePlayer.php`, replace the navigation call with:

    $episodeNavigation = $selectedEpisode !== null && $activeSeason !== null
        ? $this->playback->episodeNavigation(
            $title,
            $activeSeason,
            $user,
            $selectedEpisode,
            $episodes,
            $seasons,
        )
        : new CatalogEpisodeNavigation;

No public property or render data key changes.

- [ ] **Step 8: Запустить GREEN и existing regressions**

Run:

    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
    php artisan test tests/Feature/CatalogPageTest.php --filter=test_catalog_title_player_navigates_only_accessible_episodes_inside_the_current_release_lane
    php artisan test tests/Feature/SecurityHardeningTest.php --filter=test_playback_source_rechecks_parent_and_media_availability_on_direct_access

Expected: all PASS. Local navigation reports `0` query, each real cross-season direction `1`, existing lane/URL and signed-route behavior unchanged.

- [ ] **Step 9: Форматировать только owned PHP files и commit**

Run:

    ./vendor/bin/pint \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        --format agent
    git diff --check -- \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php
    git add -- \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php
    SEASONVAR_SKIP_GIT_GUARD=1 SEASONVAR_SKIP_DOCS_HOOK=1 git commit --only \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        -m "perf: reuse loaded episode navigation"

Expected: commit contains exactly three declared files; staged importer files remain unchanged in index.

---

### Task 2: Проверить query reduction, синхронизировать docs и завершить verification

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: stored baseline `/tmp/title-playback-pre-profile.jsonl`, `/tmp/title-playback-pre-http.txt`, Task 1 implementation.
- Produces: literal post-change evidence, documentation commit, pushed existing `main`.

- [ ] **Step 1: Проверить отсутствие параллельного PHPUnit/load-test**

Run:

    pgrep -af 'phpunit|artisan test' || true
    uptime

Expected: process search empty; load average recorded before benchmark. If a process is active, defer only the benchmark, continue non-load verification and check once more later. Do not wait in an unbounded loop and do not stop user-owned processes.

- [ ] **Step 2: Снять пять post-change in-process профилей**

Run:

    rm -f /tmp/title-playback-loaded-post-profile.jsonl
    for run in 1 2 3 4 5; do
        PROFILE_RUN="$run" php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config()->set("session.driver", "array");
            $queries = [];
            Illuminate\Support\Facades\DB::listen(
                function (Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
                    $queries[] = ["sql" => $query->sql, "time_ms" => (float) $query->time];
                },
            );
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Illuminate\Http\Request::create("http://localhost/titles/veshhdok", "GET");
            $started = hrtime(true);
            $response = $kernel->handle($request);
            $wallMs = (hrtime(true) - $started) / 1_000_000;
            $kernel->terminate($request, $response);
            $playback = array_values(array_filter(
                $queries,
                static fn (array $query): bool => str_contains($query["sql"], "from \"episodes\" inner join \"seasons\"")
                    && str_contains($query["sql"], "exists (select 1 from \"licensed_media\""),
            ));
            echo json_encode([
                "run" => (int) getenv("PROFILE_RUN"),
                "status" => $response->getStatusCode(),
                "bytes" => strlen((string) $response->getContent()),
                "wall_ms" => round($wallMs, 2),
                "query_count" => count($queries),
                "sql_ms" => round(array_sum(array_column($queries, "time_ms")), 2),
                "playback_query_count" => count($playback),
                "playback_sql_ms" => round(array_sum(array_column($playback, "time_ms")), 2),
            ], JSON_THROW_ON_ERROR).PHP_EOL;
        ' | tee -a /tmp/title-playback-loaded-post-profile.jsonl
    done

Expected: five status `200`, `query_count <= 58`, one correlated playback query, response `881900` bytes unless unrelated concurrent UI changes intentionally changed markup.

- [ ] **Step 3: Снять 20 localhost HTTP observations**

Run:

    rm -f /tmp/title-playback-loaded-post-http.txt /tmp/title-playback-response.html /tmp/title-playback-server.log
    SESSION_DRIVER=array php artisan serve --host=127.0.0.1 --port=8014 >/tmp/title-playback-server.log 2>&1 &
    server_pid=$!
    cleanup() { kill "$server_pid" 2>/dev/null || true; }
    trap cleanup EXIT
    for attempt in $(seq 1 40); do
        curl --silent --fail http://127.0.0.1:8014/up >/dev/null && break
        sleep 0.25
    done
    curl --silent --show-error --fail --output /tmp/title-playback-response.html http://127.0.0.1:8014/titles/veshhdok
    for run in $(seq 1 20); do
        curl --silent --show-error --output /tmp/title-playback-response.html \
            --write-out '%{http_code} %{time_total} %{size_download}\n' \
            http://127.0.0.1:8014/titles/veshhdok
    done | tee /tmp/title-playback-loaded-post-http.txt
    cleanup
    trap - EXIT

Expected: `20/20` HTTP 200 and stable payload.

- [ ] **Step 4: Рассчитать literal pre/post evidence**

Run:

    php -r '
        $readProfiles = static function (string $path): array {
            $rows = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $median = static function (array $values): float {
                sort($values);
                return $values[intdiv(count($values), 2)];
            };
            return [
                "wall_ms" => $median(array_column($rows, "wall_ms")),
                "sql_ms" => $median(array_column($rows, "sql_ms")),
                "playback_sql_ms" => $median(array_column($rows, "playback_sql_ms")),
                "query_count" => max(array_column($rows, "query_count")),
                "bytes" => array_values(array_unique(array_column($rows, "bytes"))),
            ];
        };
        $readHttp = static function (string $path): array {
            $rows = array_map(
                static fn (string $line): array => preg_split("/\s+/", trim($line)),
                file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $times = array_map(static fn (array $row): float => (float) $row[1] * 1000, $rows);
            sort($times);
            $percentile = static fn (array $values, float $ratio): float => $values[max(0, (int) ceil(count($values) * $ratio) - 1)];
            return [
                "samples" => count($rows),
                "statuses" => array_count_values(array_column($rows, 0)),
                "mean_ms" => round(array_sum($times) / count($times), 1),
                "p50_ms" => round($percentile($times, 0.50), 1),
                "p95_ms" => round($percentile($times, 0.95), 1),
                "max_ms" => round(max($times), 1),
                "bytes" => array_values(array_unique(array_map(static fn (array $row): int => (int) $row[2], $rows))),
            ];
        };
        $pre = $readProfiles("/tmp/title-playback-pre-profile.jsonl");
        $post = $readProfiles("/tmp/title-playback-loaded-post-profile.jsonl");
        echo json_encode([
            "profiles" => [
                "pre" => $pre,
                "post" => $post,
                "playback_sql_reduction_percent" => round((1 - $post["playback_sql_ms"] / $pre["playback_sql_ms"]) * 100, 1),
            ],
            "http" => [
                "pre" => $readHttp("/tmp/title-playback-pre-http.txt"),
                "post" => $readHttp("/tmp/title-playback-loaded-post-http.txt"),
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    '

Expected: profile reduction at least `20%`, post query count at most `58`, HTTP p95 not more than `5%` above pre, payload unchanged unless separately explained.

- [ ] **Step 5: Обновить documentation owners exact output**

Use `apply_patch`:

- `docs/performance.md`: add one bullet under SQLite measurements with literal pre/post profile medians, query count, playback SQL reduction, HTTP mean/p50/p95/max and payload; state that reuse is render-local and no authorization/signed-URL cache exists.
- `docs/MAINTENANCE_LOG.md`: prepend one 13.07.2026 bullet with zero-query local navigation, one-query cross-season fallback, focused/full test counts and measured reduction.
- `CHANGELOG.md`: add one 2026-07-13 bullet that previous/next reuses the authorized active-season collection and preserves database fallback across seasons.

If reduction is below `20%`, do not add a speed claim; return to profiling primary-action path.

- [ ] **Step 6: Выполнить focused и полный verification**

Run after concurrent importer files are syntactically complete:

    ./vendor/bin/pint \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        --format agent
    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php tests/Feature/CatalogPageTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/CatalogBladeComponentTest.php
    composer validate --strict
    php artisan test
    npm run build
    php artisan route:list --except-vendor
    php artisan config:show database
    php artisan app:health
    RUN_CACHE_INFRASTRUCTURE_TESTS=true php artisan test tests/Feature/CacheInfrastructureIntegrationTest.php
    php artisan cache:warm-catalog
    php artisan project:docs-refresh

Expected: no failures; build/route/config/health/cache integration/warm/docs commands exit 0. Horizon may remain explicitly `not_configured`.

- [ ] **Step 7: Выполнить directly-related audit**

Run:

    rg -n "episodeNavigation|adjacentEpisode|whereColumn" \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php
    rg -n "@php|@endphp|<\?php|<\?=" resources/views --glob '*.blade.php' || true
    rg -n "Cache::flush|Redis::.*keys|->keys\(" app routes config tests || true
    rg -n "TO[D]O|FIX[M]E|dd\(|dump\(|var_dump\(" \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        app/Livewire/CatalogTitlePlayer.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md || true
    git diff --check

Expected: no forbidden Blade PHP, destructive cache/key scan, debug code, placeholder or whitespace error.

- [ ] **Step 8: Commit docs, push existing main and inspect final state**

Run:

    git add -- docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md
    SEASONVAR_SKIP_GIT_GUARD=1 SEASONVAR_SKIP_DOCS_HOOK=1 git commit --only \
        docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md \
        -m "docs: record loaded episode navigation benchmark"
    git log --oneline --decorate -10
    git push origin main
    git status --short --branch

Expected: docs commit includes only three owners; push fast-forwards existing `origin/main`. Working tree is declared clean only when concurrent importer/search work has committed its own paths; those changes are never removed by this task.
