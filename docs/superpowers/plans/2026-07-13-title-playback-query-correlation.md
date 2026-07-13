# Title Playback Query Correlation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить выбор первой и соседних доступных серий, удалив повторные parent-release подзапросы без ослабления publication, audience, availability, health или hierarchy checks.

**Architecture:** `CatalogTitlePlaybackQuery::watchableEpisodesForVisibleTitles()` продолжает авторизовывать внешние title/season/episode rows существующими scopes. Внутренний `licensed_media EXISTS` проверяет собственную доступность media и явно связывает её с внешними episode, season и title через три `whereColumn`, не повторяя `forAvailableReleases()` внутри уже проверенной hierarchy. Все остальные media queries и direct signed playback сохраняют полный parent-release scope.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, SQLite, PHPUnit 12.5, Laravel Pint.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Не менять importer, schema, migrations, cache keys, signed playback route, Blade, Livewire public state или frontend assets.
- Не добавлять dependency или новый индекс: измеренные index-варианты не устранили повторные correlated checks.
- Database остаётся source of truth; authorization, Eloquent models и signed playback URLs не кешируются.
- `availableMedia()`, `playbackMedia()`, eager loading media, season summaries и direct playback сохраняют `forAvailableReleases()`.
- Изменяется только media `EXISTS` внутри `watchableEpisodesForVisibleTitles()`.
- Параллельные незакоммиченные изменения импортера принадлежат другой работе: не редактировать, не stage-ить и не включать их в commits.
- Все performance claims должны опираться на одинаковые pre/post команды и literal command output.

## File Map

- Create: `tests/Feature/CatalogTitlePlaybackQueryTest.php` — focused semantic и query-shape regression для watchable episode boundary.
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php:94-111` — единственная production-code оптимизация media `EXISTS`.
- Modify: `docs/performance.md` — SQL/HTTP измерения до и после, query count и cache non-goal.
- Modify: `docs/MAINTENANCE_LOG.md` — operational evidence выполненных focused/full проверок.
- Modify: `CHANGELOG.md` — подтверждённый пользовательский эффект без неподтверждённого SLA.

---

### Task 1: Зафиксировать baseline, закрыть hierarchy regression и упростить media `EXISTS`

**Files:**
- Create: `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php:94-111`

**Interfaces:**
- Consumes: `CatalogTitlePlaybackQuery::watchableEpisodesForVisibleTitles(?User $user): Builder`, `firstWatchableEpisode(CatalogTitle $catalogTitle, ?User $user): ?Episode`, существующие `availableTo()`, `withPlaybackLocation()` и `withoutKnownFailures()` scopes.
- Produces: та же public API и тот же `Builder<Episode>`; media `EXISTS` дополнительно гарантирует `licensed_media.season_id = episodes.season_id` и больше не разворачивает вложенный `forAvailableReleases()`.

- [ ] **Step 1: Убедиться, что baseline измеряется на неизменённом production code**

Run:

    git diff -- app/Services/Catalog/CatalogTitlePlaybackQuery.php
    git branch --show-current

Expected: первый command не показывает diff, второй печатает `main`.

- [ ] **Step 2: Снять пять pre-change in-process профилей**

Run:

    rm -f /tmp/title-playback-pre-profile.jsonl
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
                "playback_samples_ms" => array_map(
                    static fn (array $query): float => round($query["time_ms"], 2),
                    $playback,
                ),
            ], JSON_THROW_ON_ERROR).PHP_EOL;
        ' | tee -a /tmp/title-playback-pre-profile.jsonl
    done

Expected: пять JSON lines, каждый со `status:200`, `query_count` не выше исходных `63` и тремя playback selection/navigation observations для страницы с текущим player state.

- [ ] **Step 3: Снять 20 pre-change localhost HTTP observations**

Run:

    rm -f /tmp/title-playback-pre-http.txt /tmp/title-playback-response.html /tmp/title-playback-server.log
    SESSION_DRIVER=array php artisan serve --host=127.0.0.1 --port=8014 >/tmp/title-playback-server.log 2>&1 &
    server_pid=$!
    trap 'kill "$server_pid" 2>/dev/null || true' EXIT
    for attempt in $(seq 1 40); do
        curl --silent --fail http://127.0.0.1:8014/up >/dev/null && break
        sleep 0.25
    done
    curl --silent --show-error --fail --output /tmp/title-playback-response.html http://127.0.0.1:8014/titles/veshhdok
    for run in $(seq 1 20); do
        curl --silent --show-error --output /tmp/title-playback-response.html \
            --write-out '%{http_code} %{time_total} %{size_download}\n' \
            http://127.0.0.1:8014/titles/veshhdok
    done | tee /tmp/title-playback-pre-http.txt
    kill "$server_pid"
    trap - EXIT
    php -r '
        $rows = array_map(
            static fn (string $line): array => preg_split("/\s+/", trim($line)),
            file("/tmp/title-playback-pre-http.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        $times = array_map(static fn (array $row): float => (float) $row[1] * 1000, $rows);
        sort($times);
        $percentile = static fn (array $values, float $ratio): float => $values[max(0, (int) ceil(count($values) * $ratio) - 1)];
        echo json_encode([
            "samples" => count($rows),
            "statuses" => array_count_values(array_column($rows, 0)),
            "mean_ms" => round(array_sum($times) / count($times), 1),
            "p50_ms" => round($percentile($times, 0.50), 1),
            "p95_ms" => round($percentile($times, 0.95), 1),
            "max_ms" => round(max($times), 1),
            "bytes" => array_values(array_unique(array_map(static fn (array $row): int => (int) $row[2], $rows))),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    '

Expected: `samples:20`, только HTTP `200`, один стабильный response-size и числовые mean/p50/p95/max. Сохранить literal output для сравнения после изменения.

- [ ] **Step 4: Создать focused regression test**

Create `tests/Feature/CatalogTitlePlaybackQueryTest.php`:

    <?php

    declare(strict_types=1);

    namespace Tests\Feature;

    use App\Models\CatalogTitle;
    use App\Models\Episode;
    use App\Models\LicensedMedia;
    use App\Models\Season;
    use App\Services\Catalog\CatalogTitlePlaybackQuery;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Tests\TestCase;

    class CatalogTitlePlaybackQueryTest extends TestCase
    {
        use RefreshDatabase;

        public function test_watchable_episode_requires_media_to_match_the_complete_release_hierarchy(): void
        {
            [$title, $season, $episode] = $this->releaseHierarchy();
            $otherTitle = CatalogTitle::factory()->create();
            $otherSeason = Season::factory()->create(['catalog_title_id' => $otherTitle->id]);
            $media = $this->publishedMedia($title, $otherSeason, $episode);
            $playback = app(CatalogTitlePlaybackQuery::class);

            $this->assertNull($playback->firstWatchableEpisode($title, null));

            $media->update([
                'catalog_title_id' => $otherTitle->id,
                'season_id' => $season->id,
            ]);

            $this->assertNull($playback->firstWatchableEpisode($title, null));

            $media->update(['catalog_title_id' => $title->id]);

            $this->assertSame($episode->id, $playback->firstWatchableEpisode($title, null)?->id);
        }

        public function test_watchable_episode_preserves_release_media_location_and_health_boundaries(): void
        {
            $mutations = [
                'hidden title' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $title->update(['publication_status' => 'hidden']),
                'hidden season' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $season->update(['publication_status' => 'hidden']),
                'hidden episode' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $episode->update(['publication_status' => 'hidden']),
                'draft media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['status' => 'draft']),
                'future media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['available_from' => now()->addMinute()]),
                'expired media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['available_until' => now()->subMinute()]),
                'failed media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['health_status' => 'unavailable']),
                'source-less media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['path' => '', 'playback_url' => null]),
                'deleted media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->delete(),
            ];
            $playback = app(CatalogTitlePlaybackQuery::class);

            foreach ($mutations as $case => $mutate) {
                [$title, $season, $episode, $media] = $this->playableHierarchy();

                $this->assertTrue($mutate($title, $season, $episode, $media), $case);
                $this->assertNull($playback->firstWatchableEpisode($title, null), $case);
            }
        }

        public function test_watchable_query_uses_direct_media_hierarchy_correlations_without_nested_release_checks(): void
        {
            $sql = str(app(CatalogTitlePlaybackQuery::class)->watchableEpisodesForVisibleTitles(null)->toSql())
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();

            $this->assertStringContainsString('licensed_media.episode_id = episodes.id', $sql);
            $this->assertStringContainsString('licensed_media.season_id = episodes.season_id', $sql);
            $this->assertStringContainsString('licensed_media.catalog_title_id = seasons.catalog_title_id', $sql);
            $this->assertStringNotContainsString('season_id is null or exists (select * from seasons where licensed_media.season_id = seasons.id', $sql);
            $this->assertStringNotContainsString('episode_id is null or exists (select * from episodes where licensed_media.episode_id = episodes.id', $sql);
        }

        /** @return array{CatalogTitle, Season, Episode} */
        private function releaseHierarchy(): array
        {
            $title = CatalogTitle::factory()->create();
            $season = Season::factory()->create(['catalog_title_id' => $title->id]);
            $episode = Episode::factory()->create(['season_id' => $season->id]);

            return [$title, $season, $episode];
        }

        /** @return array{CatalogTitle, Season, Episode, LicensedMedia} */
        private function playableHierarchy(): array
        {
            [$title, $season, $episode] = $this->releaseHierarchy();

            return [$title, $season, $episode, $this->publishedMedia($title, $season, $episode)];
        }

        private function publishedMedia(CatalogTitle $title, Season $season, Episode $episode): LicensedMedia
        {
            return LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'storage_disk' => 'external_playlist',
                'path' => 'https://data00-cdn.11cdn.org/title-playback-query.m3u8',
                'playback_url' => 'https://data00-cdn.11cdn.org/title-playback-query.m3u8',
                'status' => 'published',
                'published_at' => now()->subMinute(),
                'check_status' => 'available',
                'health_status' => 'active',
            ]);
        }
    }

- [ ] **Step 5: Запустить новый test и подтвердить правильный RED**

Run:

    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php

Expected: FAIL в hierarchy test, потому что media чужого сезона ошибочно делает episode watchable; FAIL в query-shape test, потому что отсутствует `licensed_media.season_id = episodes.season_id` и присутствуют вложенные release `EXISTS`. Availability-boundary test должен проходить.

- [ ] **Step 6: Реализовать минимальное изменение media `EXISTS`**

In `app/Services/Catalog/CatalogTitlePlaybackQuery.php`, replace only the `$availableMedia` chain inside `watchableEpisodesForVisibleTitles()` with:

    $availableMedia = LicensedMedia::query()
        ->availableTo($user)
        ->withPlaybackLocation()
        ->withoutKnownFailures()
        ->whereColumn($media->qualifyColumn('episode_id'), $episode->qualifyColumn('id'))
        ->whereColumn($media->qualifyColumn('season_id'), $episode->qualifyColumn('season_id'))
        ->whereColumn($media->qualifyColumn('catalog_title_id'), $season->qualifyColumn('catalog_title_id'))
        ->selectRaw('1');

Do not modify any other `forAvailableReleases()` call.

- [ ] **Step 7: Запустить focused regression tests**

Run:

    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
    php artisan test tests/Feature/CatalogPageTest.php --filter=test_catalog_title_player_navigates_only_accessible_episodes_inside_the_current_release_lane
    php artisan test tests/Feature/SecurityHardeningTest.php --filter=test_playback_source_rechecks_parent_and_media_availability_on_direct_access

Expected: all commands PASS. Новый class подтверждает hierarchy/query shape; существующие tests подтверждают stable navigation lane и независимый direct signed playback recheck.

- [ ] **Step 8: Отформатировать и проверить diff**

Run:

    ./vendor/bin/pint --dirty --format agent
    git diff --check -- app/Services/Catalog/CatalogTitlePlaybackQuery.php tests/Feature/CatalogTitlePlaybackQueryTest.php
    git diff -- app/Services/Catalog/CatalogTitlePlaybackQuery.php tests/Feature/CatalogTitlePlaybackQueryTest.php

Expected: Pint succeeds, `diff --check` пуст, production diff содержит удаление одного scope и добавление одной `season_id` correlation.

- [ ] **Step 9: Закоммитить только query fix и test**

Run:

    git add -- app/Services/Catalog/CatalogTitlePlaybackQuery.php tests/Feature/CatalogTitlePlaybackQueryTest.php
    git diff --cached --name-only
    SEASONVAR_SKIP_GIT_GUARD=1 git commit --only \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        -m "perf: simplify title playback episode lookup"

Expected: committed paths — ровно service и focused test. Явный guard bypass допустим только из-за unrelated concurrent importer files; `--only` не позволяет включить их в commit.

---


### Task 2: Доказать ускорение, обновить документацию и выполнить полную проверку

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: literal outputs `/tmp/title-playback-pre-profile.jsonl`, `/tmp/title-playback-pre-http.txt`, identical post-change benchmark commands и commit из Task 1.
- Produces: reproducible before/after evidence, synchronized operational documentation и verified main-branch commits.

- [ ] **Step 1: Повторить пять in-process профилей после изменения**

Run:

    rm -f /tmp/title-playback-post-profile.jsonl
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
                "playback_samples_ms" => array_map(
                    static fn (array $query): float => round($query["time_ms"], 2),
                    $playback,
                ),
            ], JSON_THROW_ON_ERROR).PHP_EOL;
        ' | tee -a /tmp/title-playback-post-profile.jsonl
    done

Expected: пять HTTP `200`; query count не выше pre-change и исходных `63`; `playback_query_count` не меняется; median `playback_sql_ms` снижается минимум на `20%`.

- [ ] **Step 2: Повторить 20 localhost HTTP observations после изменения**

Run:

    rm -f /tmp/title-playback-post-http.txt /tmp/title-playback-response.html /tmp/title-playback-server.log
    SESSION_DRIVER=array php artisan serve --host=127.0.0.1 --port=8014 >/tmp/title-playback-server.log 2>&1 &
    server_pid=$!
    trap 'kill "$server_pid" 2>/dev/null || true' EXIT
    for attempt in $(seq 1 40); do
        curl --silent --fail http://127.0.0.1:8014/up >/dev/null && break
        sleep 0.25
    done
    curl --silent --show-error --fail --output /tmp/title-playback-response.html http://127.0.0.1:8014/titles/veshhdok
    for run in $(seq 1 20); do
        curl --silent --show-error --output /tmp/title-playback-response.html \
            --write-out '%{http_code} %{time_total} %{size_download}\n' \
            http://127.0.0.1:8014/titles/veshhdok
    done | tee /tmp/title-playback-post-http.txt
    kill "$server_pid"
    trap - EXIT
    php -r '
        $rows = array_map(
            static fn (string $line): array => preg_split("/\s+/", trim($line)),
            file("/tmp/title-playback-post-http.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        $times = array_map(static fn (array $row): float => (float) $row[1] * 1000, $rows);
        sort($times);
        $percentile = static fn (array $values, float $ratio): float => $values[max(0, (int) ceil(count($values) * $ratio) - 1)];
        echo json_encode([
            "samples" => count($rows),
            "statuses" => array_count_values(array_column($rows, 0)),
            "mean_ms" => round(array_sum($times) / count($times), 1),
            "p50_ms" => round($percentile($times, 0.50), 1),
            "p95_ms" => round($percentile($times, 0.95), 1),
            "max_ms" => round(max($times), 1),
            "bytes" => array_values(array_unique(array_map(static fn (array $row): int => (int) $row[2], $rows))),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    '

Expected: `20/20` HTTP `200`, response-size не увеличивается из-за query change, p95 не хуже pre-change более чем на `5%`. Ускорение p50/p95 заявлять только при воспроизводимой разнице вне шума.

- [ ] **Step 3: Сравнить медианы profile evidence машинно**

Run:

    php -r '
        $read = static function (string $path): array {
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
        $pre = $read("/tmp/title-playback-pre-profile.jsonl");
        $post = $read("/tmp/title-playback-post-profile.jsonl");
        echo json_encode([
            "pre" => $pre,
            "post" => $post,
            "playback_sql_reduction_percent" => round((1 - $post["playback_sql_ms"] / $pre["playback_sql_ms"]) * 100, 1),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    '

Expected: `playback_sql_reduction_percent >= 20`, `post.query_count <= pre.query_count`, одинаковый payload size.

- [ ] **Step 4: Проверить новый query plan**

Run:

    php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $query = $app->make(App\Services\Catalog\CatalogTitlePlaybackQuery::class)
            ->watchableEpisodesForVisibleTitles(null);
        $rows = Illuminate\Support\Facades\DB::select(
            "EXPLAIN QUERY PLAN ".$query->toSql(),
            $query->getBindings(),
        );
        echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    '

Expected: один прямой correlated search по `licensed_media`; вложенных media→season и media→episode→season release scans нет. Migration/index не добавляется.

- [ ] **Step 5: Обновить три documentation owner файла literal измерениями**

Use `apply_patch` и exact numbers из Steps 1–4:

- В `docs/performance.md` под `## Измерения и планы SQLite` добавить один bullet: pre/post profile medians, reduction percent, unchanged query count/payload, 20-request HTTP pre/post mean/p50/p95/max и факт отсутствия shared authorization/signed-URL cache.
- В начало `docs/MAINTENANCE_LOG.md` добавить один bullet от `13.07.2026`: redundant parent-release checks заменены тремя direct media hierarchy correlations; указать focused/full test counts и measured SQL reduction.
- В `CHANGELOG.md` внутри `## 2026-07-13` добавить один bullet: first/previous/next episode selection переиспользует уже авторизованную hierarchy и отклоняет mismatched media season/title references.

Записывать literal timings с точностью до одной десятой миллисекунды. Если machine comparison ниже `20%`, не писать percentage claim: остановить documentation step и вернуться к профилированию.

- [ ] **Step 6: Запустить formatter и связанные suites**

Run:

    ./vendor/bin/pint --dirty --format agent
    php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php tests/Feature/CatalogPageTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/CatalogBladeComponentTest.php

Expected: Pint succeeds; все selected tests PASS без failures.

- [ ] **Step 7: Запустить полный supported verification suite**

Run только после того, как concurrent importer work достиг syntactically complete состояния:

    composer validate --strict
    php artisan test
    npm run build
    php artisan route:list --except-vendor
    php artisan config:show database
    php artisan app:health
    RUN_CACHE_INFRASTRUCTURE_TESTS=true php artisan test tests/Feature/CacheInfrastructureIntegrationTest.php
    php artisan cache:warm-catalog
    php artisan project:docs-refresh

Expected:

- Composer metadata valid.
- Complete PHP suite has zero failures.
- Vite production build succeeds.
- Route/config inspection exits `0`.
- Readiness reports required database/Redis/Memcached/worker/warming checks healthy; Horizon may be explicitly `not_configured`.
- Real Redis/Memcached integration suite passes.
- Cache warmer succeeds.
- Docs refresh сообщает актуальные managed blocks или обновляет только owned managed files.

- [ ] **Step 8: Выполнить directly-related audit**

Run:

    rg -n "forAvailableReleases|whereColumn" app/Services/Catalog/CatalogTitlePlaybackQuery.php
    rg -n "@php|@endphp|<\?php|<\?=" resources/views --glob '*.blade.php' || true
    rg -n "Cache::flush|Redis::.*keys|->keys\(" app routes config tests || true
    rg -n "TO[D]O|FIX[M]E|dd\(|dump\(|var_dump\(" \
        app/Services/Catalog/CatalogTitlePlaybackQuery.php \
        tests/Feature/CatalogTitlePlaybackQueryTest.php \
        docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md || true
    git diff --check

Expected: optimized method содержит intended correlations; Blade audit не находит forbidden PHP; directly related destructive cache/key scans, debug и placeholders отсутствуют; `git diff --check` пуст.

- [ ] **Step 9: Закоммитить только синхронизированную документацию**

Run:

    git add -- docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md
    git diff --cached --name-only
    SEASONVAR_SKIP_GIT_GUARD=1 git commit --only \
        docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md \
        -m "docs: record title playback query benchmark"

Expected: commit включает только три declared documentation owners. Managed documentation, дополнительно изменённая `project:docs-refresh`, должна быть reviewed и committed своей concurrent task, а не смешана здесь.

- [ ] **Step 10: Проверить историю, push и финальное состояние**

Run:

    git status --short --branch
    git log --oneline --decorate -8
    git show --stat --oneline HEAD~1
    git show --stat --oneline HEAD
    git push origin main
    git status --short --branch

Expected: playback code/test commit и benchmark-doc commit содержат только declared files; push обновляет существующую `origin/main`. Final tree объявляется clean только после commit всех concurrent user-owned файлов; иначе эти paths перечисляются как external blocker и не удаляются.

