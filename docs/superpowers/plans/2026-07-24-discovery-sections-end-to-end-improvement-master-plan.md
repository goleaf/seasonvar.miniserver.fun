# Discovery Sections End-to-End Improvement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan one finite change set at a time. `superpowers:subagent-driven-development` is allowed only after a new explicit user request permitting sub-agents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Довести до предсказуемой, полной и быстрой работы девять страниц `/discover/*`: исправить refresh, cold-start, pagination и пустые data paths, убрать многосекундные visitor queries, сохранить приватность персонализации и дать каждому режиму проверяемый end-to-end contract.

**Architecture:** Публичные режимы читают bounded scalar candidate pools из существующего recommendation cache, а тяжёлые popularity/trending aggregates — из additive derived projection с authoritative fallback. `CatalogRecommendationService` остаётся единственным orchestration owner; `CatalogPublicDiscoveryQuery` — владельцем публичного ranking; release calendar и featured editorial collections не дублируются. Livewire выполняет один discover на interaction, дополнительные facets загружает по необходимости, а embedded collection explorer сначала пагинирует IDs и только затем считает summary для одной страницы.

**Tech Stack:** PHP `8.5.8`, Laravel `13.21.1`, Laravel Boost `2.4.13`, Livewire `4.3.3`, SQLite, существующий tiered cache и scheduler, PHPUnit `12.5.31`, Pint `1.29.3`, Node.js `26.4.0`, Vite `8.1.4`, Tailwind CSS `4.3.2`, Playwright.

**Plan status:** `approved_for_inline_execution`; это planning-only change set. Код, схема, данные, cache, queue, scheduler, environment и production services этим документом не изменяются.

**Execution status 26.07.2026:** Tasks 1, 3 и 4 и discovery-only часть
Task 2 реализованы и проверены локально в system Task 40. Пять ожидаемых RED
отказов стали GREEN; route, privacy, query-count и documentation gates прошли.
Task 7 two-phase ID pagination реализована в system Task 51, а Task 59
завершила вторую фазу: bounded correlated counts заменены одним grouped
aggregate по exact page IDs; collection presentation остаётся полностью
text-only.
Tasks 5–6 и 8+ и production activation не начинались.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree/PR и не обходить Git guards.
- До каждого change set заново читать `AGENTS.md`, `docs/requirements/index.md`, все применимые canonical requirements и этот план; немедленно обновлять план при новом evidence.
- Не stage/reset/stash/delete чужие importer/player/collection/system изменения общего checkout.
- Сохранить маршруты `discover.index` и `localized.discover.index`, значения `CatalogRecommendationType`, URL filters, full-page Livewire owner, API shape, canonical/noindex rules и русско-английский parity.
- Сохранить server-side visibility/watchability, premium/region/legal restrictions, current-user feedback, repeat suppression и private `no-store`.
- Не класть `user_id`, seed, recent-title IDs, персональный профиль или результаты авторизованного пользователя в shared cache.
- Не вводить второй recommender, release calendar, collection model, cache store, permission boundary или обязательную queue dependency.
- Derived projection не является access truth: окончательная загрузка тайтлов всегда повторно применяет текущую visibility/watchability boundary.
- Миграции только additive и reversible. Backfill не выполняется внутри migration и не активируется до readiness check.
- `php artisan seasonvar:import` остаётся единственной публичной командой Seasonvar. Внутренняя maintenance-команда discovery projection не получает import semantics.
- Никаких fake dates, fake editorial content, provider HTTP, видео-download или production DML в рамках code rollout.
- `top_rated` и `recently_updated` не переписывать без нового измеренного bottleneck: baseline уже быстрый.
- Любая performance-цель ниже проверяется серией замеров на фиксированном snapshot и не объявляется внешним SLA.

---

## 1. Проверенный baseline — 24.07.2026

### 1.1 Production-size snapshot

| Объект | Количество |
| --- | ---: |
| `catalog_titles` | 32 970 |
| `episode_view_progress` | 1,60 млн |
| `catalog_title_user_states` | 1,65 млн |
| `catalog_title_reviews` | 1,72 млн |
| `comments` | 3,71 млн |
| `catalog_collection_items` | 3,29 млн |
| `episodes` | 728 848 |

SQLite-файл имеет размер около `27 GiB`. Все проверки были read-only.

### 1.2 Повторные raw candidate timings

| Режим | Время | Строк |
| --- | ---: | ---: |
| `trending` | `2.57–2.63 s` | 1 |
| `popular` | `3.85–3.93 s` | 180 |
| `top_rated` | `~8 ms` | 180 |
| `recently_added` | `267–273 ms` | 180 |
| `recently_updated` | `56–58 ms` | 180 |
| `upcoming` | `~1.3 ms` | 0 |
| `editorial` | `~1.7 ms` | 0 |
| `random` | `237–239 ms` | 25 |

Первый page query embedded popular collection explorer занимает около `4.06 s` и выполняет correlated summary до `LIMIT`.

### 1.3 Подтверждённые функциональные дефекты

- Guest `personalized` cold-start возвращает одну недельную trending-строку и не дополняет страницу из `popular`; после refresh появляется 24 строки.
- `refreshRecommendations()` делает discover до смены seed, затем Livewire render делает второй discover уже после смены seed.
- Stable public refresh с seed обходит shared cache, хотя ranking `popular`, `trending`, `top_rated`, `recently_added`, `recently_updated`, `upcoming` и `editorial` не зависит от seed.
- `random` выдаёт 25 кандидатов при page size 24 и ошибочно разрешает page 2, на которой остаётся одна строка.
- Все episodes имеют `released_at = null`; canonical `release_schedule_entries` содержит 8 258 public rows, но нет будущих. При этом есть 747 незавершённых сезонов с `episodes_released < episodes_total`.
- Есть 54 approved/public editorial collections и 91 уникальный title, но нет ни одной `is_featured=true`.
- Authenticated legacy personalized возвращает 24 строки за `289–363 ms`, но выполняет 57 SQL queries.
- `personalized_v2` выключен; последние пять shadow builds имеют `failed` из-за stale heartbeat. Включать rollout до repair/quality gate запрещено.
- Все девять live routes возвращают `200`, не имеют horizontal overflow, broken images, first-party console/network errors или Axe violations на desktop/mobile. Это сохраняемый baseline, а не доказательство готовности содержимого.

### 1.4 Решения, которые не пересматриваются без нового evidence

1. **Hybrid read path:** cache для bounded public IDs + derived projection только для дорогих aggregates + authoritative fallback.
2. **One interaction — one discover:** action и последующий render используют один request-scoped result.
3. **Cold-start заполняется несколькими sources:** editorial → trending выбранного периода → month trending → popular, с дедупликацией и сохранением фактической причины.
4. **Editorial остаётся featured-only:** произвольные public/unfeatured collections не показываются. Нужны readiness gate и реальная feature activation по существующему editorial master plan.
5. **Upcoming не выдумывает дату:** сначала canonical future schedule; затем незавершённые сезоны с честной причиной «дата не объявлена».
6. **Random — cursorless single refresh set:** page 2 для random не существует; кнопка refresh создаёт новый seed.
7. **Facets progressive:** genre/country доступны сразу; тяжёлые groups загружаются только при раскрытии или активном URL filter.

---

## 2. Acceptance gates

На фиксированном production-size snapshot после прогрева соединения:

- `popular` и `trending` projection candidate query: median `<=150 ms`, p95 `<=300 ms`, не более 4 SQL statements до title loading.
- Guest warm request для каждого deterministic public mode: median server time `<=250 ms`, p95 `<=500 ms`.
- Cold cache request: p95 `<=1 s`; если projection не готова, страница остаётся корректной через fallback, а degraded path фиксируется без утечки данных.
- `personalized` legacy: не более 25 SQL statements и median `<=250 ms` на representative profile; v2 имеет отдельный build/quality gate.
- Embedded collection explorer first page: median `<=250 ms`, не более 8 SQL statements.
- Refresh: ровно один вызов `CatalogRecommendationService::discover()`, median Livewire response `<=750 ms` для warm public mode.
- Страница содержит 24 уникальных rows, когда столько eligible данных существует; `hasMore` истинно только при наличии полного следующего page.
- Все девять routes проходят guest desktop/mobile; personalized дополнительно проходит authenticated desktop/mobile.
- Ноль first-party browser errors, broken images, duplicate title links, horizontal overflow и automated accessibility violations.

Команды замера обязаны сохранить raw JSON в ignored `output/`, указать dataset date, пять warm и пять cold samples и не называть результат SLA.

---

## 3. Dependency order

| Фаза | Tasks | Gate |
| --- | --- | --- |
| 0. Contracts и RED baseline | 1–2 | Канонические правила обновлены; дефекты воспроизводятся тестами |
| 1. Correctness до оптимизации | 3–4 | Один discover; полный cold-start; корректные refresh/pagination/reasons |
| 2. Query architecture | 5–7 | Projection готова и обратима; popular/trending/collections budgets зелёные |
| 3. Empty data paths | 8–9 | Upcoming честно наполнен; editorial canary readiness подтверждён |
| 4. Personalization и UI | 10–11 | Query budget, facets и mode-specific controls зелёные |
| 5. Production acceptance | 12–13 | Полная verification matrix, staged rollout, rollback и docs завершены |

---

## 4. Task 1 — обновить canonical contracts до кода

**Priority:** P0.

**Files:**

- Modify: `docs/architecture.md`
- Modify: `docs/caching.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`
- Modify: `docs/release-calendar.md`
- Modify: `docs/superpowers/specs/2026-07-16-recommendation-personalization-exploration-design.md`
- Modify: `docs/plans/current-task-plan.md`

**Required contract text:**

```text
Deterministic guest discovery stores a canonical bounded scalar candidate pool.
Session recent-title exclusions are applied after cache lookup and never become a
shared cache dimension. Personalized, authenticated and random results remain
private or uncached. Discovery metrics are derivative ranking data and never
replace current visibility/watchability checks.
```

- [ ] Record the hybrid read path, cache privacy boundary, projection fallback, refresh semantics, random single-page contract, upcoming fallback and featured-only editorial gate in the canonical owner files.
- [ ] Record cross-feature consumers: catalog `sort=popularity`, embedded collections, release calendar, SEO, sitemap, importer invalidation, feedback and personalized builds.
- [ ] Record rollback: disable projection reads, revert to authoritative query, keep additive rows, restore previous cache key version.
- [ ] Run `php artisan project:docs-refresh --check`.

Expected: exit `0`; no managed block changed manually.

---

## 5. Task 2 — зафиксировать RED regression и query budgets

**Priority:** P0.

**Files:**

- Create: `tests/Feature/CatalogDiscoveryInteractionTest.php`
- Create: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Modify: `tests/Feature/CatalogRecommendationPrivacyTest.php`
- Modify: `tests/Feature/CatalogRecommendationTitleLoaderQueryTest.php`

**Required RED contracts:**

```php
public function test_guest_personalized_cold_start_fills_the_page_from_multiple_public_sources(): void
{
    $result = app(CatalogRecommendationService::class)->discover($this->guestPersonalizedContext());

    $this->assertCount(24, $result->items);
    $this->assertSame($result->items->count(), $result->items->pluck('title.id')->unique()->count());
}

public function test_refresh_resolves_recommendations_once_and_records_the_new_result(): void
{
    $service = $this->mock(CatalogRecommendationService::class);
    $service->shouldReceive('discover')->twice()->andReturn(
        $this->resultWithTitleIds(range(1, 24)),
        $this->resultWithTitleIds(range(25, 48)),
    );
    $service->shouldReceive('rememberShown')->once();

    Livewire::test(CatalogDiscoveryPage::class, ['type' => 'popular'])
        ->call('refreshRecommendations')
        ->assertHasNoErrors();
}

public function test_random_never_exposes_a_second_page(): void
{
    Livewire::test(CatalogDiscoveryPage::class, ['type' => 'random'])
        ->assertViewHas('result', fn (CatalogRecommendationResult $result): bool => ! $result->hasMore);
}
```

- [ ] Add deterministic factories for 1 weekly trending title, 30 popular titles, incomplete seasons, future schedule rows, readiness-passing featured collection and hidden/inaccessible titles.
- [ ] Count SQL through `DB::listen()` without asserting wall-clock time in PHPUnit.
- [ ] Verify RED:

Run:

```bash
php artisan test \
  tests/Feature/CatalogDiscoveryInteractionTest.php \
  tests/Feature/CatalogDiscoveryQueryBudgetTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php
```

Expected: failures specifically identify one-row cold-start, double discover, random page 2 and current collection query budget.

---

## 6. Task 3 — один discover на refresh и корректная public cache boundary

**Priority:** P0.

**Files:**

- Modify: `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/Services/Catalog/CatalogRecommendationCache.php`
- Modify: `app/Services/Catalog/CatalogRecommendationExclusionService.php`
- Modify: `tests/Feature/CatalogDiscoveryInteractionTest.php`
- Modify: `tests/Feature/CatalogRecommendationPrivacyTest.php`
- Modify: `config/recommendations.php`

**Request-scoped result contract:**

```php
protected ?CatalogRecommendationResult $resolvedResult = null;

protected bool $resolvedResultPrepared = false;

public function refreshRecommendations(): void
{
    $this->seed = bin2hex(random_bytes(16));
    $this->page = 1;
    $this->notice = null;
    $this->resetErrorBag();
    $this->resolvedResultPrepared = true;

    try {
        $this->resolvedResult = $this->recommendations->discover($this->context());
        $this->recommendations->rememberShown($this->resolvedResult, $this->user());
    } catch (Throwable $exception) {
        report($exception);
        $this->resolvedResult = $this->emptyResult($this->selectedType());
        $this->addError('recommendations', __('recommendations.page.error'));
    }
}
```

`render()` consumes `$this->resolvedResult` when `$resolvedResultPrepared=true`; otherwise it calls discover once. This separate boolean prevents a failed refresh from silently retrying the expensive query during the same interaction. `emptyResult()` centralizes the existing empty DTO construction. Exception handling stays Russian and does not serialize the DTO into public Livewire state.

**Cache contract:**

```php
if ($context->user !== null
    || $context->type === CatalogRecommendationType::Personalized
    || $context->type === CatalogRecommendationType::Random) {
    return $rebuild();
}
```

For deterministic guest modes, cache rebuild receives only canonical exclusions (`currentTitleId`, explicit route exclusions and server visibility). Session recent IDs are removed from the returned bounded pool in memory. Cache namespace changes from `discovery-ids-v2` to `discovery-ids-v3`; old entries expire naturally, no global flush.

- [ ] Make the focused tests GREEN.
- [ ] Add a cache HIT test proving that two guest sessions use one rebuild but receive their own recent-ID filtering.
- [ ] Add privacy tests proving that authenticated, personalized and random calls do not read/write the shared result.
- [ ] Run:

```bash
php artisan test tests/Feature/CatalogDiscoveryInteractionTest.php tests/Feature/CatalogRecommendationPrivacyTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: all focused tests pass; Pint exits `0`.

**Rollback:** restore v2 cache namespace and previous action flow. No stored private data or schema is involved.

---

## 7. Task 4 — полный cold-start, честный trending fallback и random pagination

**Priority:** P0.

**Files:**

- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Modify: `app/DTOs/CatalogRecommendationContext.php`
- Modify: `app/DTOs/CatalogRecommendationResult.php`
- Modify: `app/Enums/CatalogRecommendationReason.php`
- Modify: `app/Enums/CatalogRecommendationSource.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`
- Modify: `tests/Feature/CatalogDiscoveryInteractionTest.php`
- Modify: `tests/Feature/CatalogRecommendationPrivacyTest.php`

**Cold-start algorithm:**

```php
$through = min(
    max(24, (int) config('recommendations.candidate_limit', 180)),
    ($context->boundedPage() * $context->boundedPerPage()) + 1,
);
$rows = [];
$sources = [
    [$context->withType(CatalogRecommendationType::Editorial), CatalogRecommendationType::Editorial],
    [$context->withType(CatalogRecommendationType::Trending), CatalogRecommendationType::Trending],
    [$context->withTypeAndPeriod(CatalogRecommendationType::Trending, CatalogPopularityPeriod::Month), CatalogRecommendationType::Trending],
    [$context->withType(CatalogRecommendationType::Popular), CatalogRecommendationType::Popular],
];

foreach ($sources as [$fallbackContext]) {
    foreach ($this->public->candidates($fallbackContext, $excludedIds) as $row) {
        $rows[$row['id']] ??= $row;
        if (count($rows) >= $through) {
            break 2;
        }
    }
}
```

Добавить immutable copy methods в `CatalogRecommendationContext`, чтобы не размножать конструктор и не терять filters/locale/rating source. Month fallback сохраняет фактический source/reason и получает отдельный локализованный explanation parameter; он не выдаётся за weekly activity.

```php
public function withType(CatalogRecommendationType $type): self
{
    return new self(
        type: $type,
        user: $this->user,
        locale: $this->locale,
        currentTitleId: $this->currentTitleId,
        excludedTitleIds: $this->excludedTitleIds,
        filters: $this->filters,
        period: $this->period,
        ratingSource: $this->ratingSource,
        page: 1,
        perPage: $this->perPage,
        seed: null,
    );
}

public function withTypeAndPeriod(
    CatalogRecommendationType $type,
    CatalogPopularityPeriod $period,
): self {
    $context = $this->withType($type);

    return new self(
        type: $context->type,
        user: $context->user,
        locale: $context->locale,
        currentTitleId: $context->currentTitleId,
        excludedTitleIds: $context->excludedTitleIds,
        filters: $context->filters,
        period: $period,
        ratingSource: $context->ratingSource,
        page: $context->page,
        perPage: $context->perPage,
        seed: $context->seed,
    );
}
```

Random получает ровно `boundedPerPage()` rows, `page` принудительно нормализуется к 1, `hasMore=false`; новая выборка выполняется только новым seed.

- [ ] RED: weekly source содержит 1 row, popular — 30; result содержит 24 unique rows и обе причины.
- [ ] RED: второй month fallback не дублирует weekly title.
- [ ] RED: random ignores `?page=2`, does not show next-page control and changes IDs after refresh.
- [ ] GREEN and run:

```bash
php artisan test --filter=CatalogDiscoveryInteractionTest
php artisan test --filter=CatalogRecommendationPrivacyTest
./vendor/bin/pint --dirty --format agent
```

---

## 8. Task 5 — additive discovery metrics projection

**Priority:** P0 performance.

**Files:**

- Create: `database/migrations/2026_07_24_235910_create_catalog_discovery_metric_projection.php`
- Create: `app/Models/CatalogDiscoveryMetric.php`
- Create: `app/Models/CatalogDiscoveryProjectionState.php`
- Create: `app/Services/Catalog/CatalogDiscoveryMetricProjector.php`
- Create: `app/Services/Catalog/CatalogDiscoveryProjectionReadiness.php`
- Create: `app/Console/Commands/RebuildCatalogDiscoveryMetrics.php`
- Create: `tests/Feature/CatalogDiscoveryMetricProjectionTest.php`
- Modify: `config/recommendations.php`
- Modify: `.env.example`

**Schema:**

```php
Schema::create('catalog_discovery_metrics', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('watchlist_count')->default(0);
    $table->unsignedBigInteger('meaningful_watcher_count')->default(0);
    $table->unsignedBigInteger('published_review_count')->default(0);
    $table->unsignedBigInteger('kinopoisk_votes')->default(0);
    $table->unsignedBigInteger('imdb_votes')->default(0);
    $table->unsignedBigInteger('popularity_kinopoisk_score')->default(0);
    $table->unsignedBigInteger('popularity_imdb_score')->default(0);
    $table->unsignedBigInteger('trending_week_score')->default(0);
    $table->unsignedBigInteger('trending_week_watchers')->default(0);
    $table->unsignedBigInteger('trending_month_score')->default(0);
    $table->unsignedBigInteger('trending_month_watchers')->default(0);
    $table->unsignedInteger('projection_version');
    $table->timestamp('calculated_at');
    $table->timestamps();

    $table->unique(['projection_version', 'catalog_title_id'], 'discovery_metrics_version_title_unique');
    $table->index(['projection_version', 'popularity_kinopoisk_score', 'catalog_title_id'], 'discovery_metrics_popularity_kp_idx');
    $table->index(['projection_version', 'popularity_imdb_score', 'catalog_title_id'], 'discovery_metrics_popularity_imdb_idx');
    $table->index(['projection_version', 'trending_week_score', 'catalog_title_id'], 'discovery_metrics_trending_week_idx');
    $table->index(['projection_version', 'trending_month_score', 'catalog_title_id'], 'discovery_metrics_trending_month_idx');
});

Schema::create('catalog_discovery_projection_states', function (Blueprint $table): void {
    $table->id();
    $table->string('projection', 64)->unique();
    $table->unsignedInteger('version');
    $table->string('status', 24);
    $table->unsignedBigInteger('expected_count')->default(0);
    $table->unsignedBigInteger('projected_count')->default(0);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->string('failure_code', 64)->nullable();
    $table->timestamps();
});
```

`failure_code` содержит только allowlisted code, не exception body/path/query. Migration `down()` удаляет state перед metrics. Backfill отсутствует в migration.

**Command contract:**

```php
protected $signature = 'catalog:discovery-metrics-rebuild
    {--chunk=500 : Размер bounded upsert}
    {--version= : Явная версия будущей проекции}
    {--activate : Активировать только после полного readiness check}';
```

Projector выполняет по одному grouped aggregate на signal table, stream/chunk merge по `catalog_title_id`, bulk `upsert` в новую `projection_version`, затем проверяет count/duplicates/nulls. Активация state происходит в короткой transaction только после полного build; предыдущая ready version остаётся читаемой до активации и удаляется отдельным bounded prune после canary. Никакого per-title N+1.

Config:

```php
'discovery_metrics' => [
    'enabled' => filter_var(env('RECOMMENDATIONS_DISCOVERY_METRICS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'version' => 1,
    'maximum_stale_minutes' => 90,
],
```

- [ ] RED schema/model/readiness tests.
- [ ] RED aggregate parity test: projection order equals authoritative `CatalogPopularityQuery` and current trending query on the same fixtures.
- [ ] RED interruption test: failed build never changes ready version.
- [ ] GREEN and run:

```bash
php artisan test tests/Feature/CatalogDiscoveryMetricProjectionTest.php
php artisan migrate:status
./vendor/bin/pint --dirty --format agent
```

Expected: migration tests pass; local migration status is read-only. Do not run production migration/backfill in this task.

**Rollback:** set `RECOMMENDATIONS_DISCOVERY_METRICS_ENABLED=false`; requests use authoritative query. Drop tables only in an separately approved rollback window.

---

## 9. Task 6 — projection read path, invalidation и bounded reconciliation

**Priority:** P0 performance/reliability.

**Files:**

- Create: `app/Services/Catalog/CatalogDiscoveryMetricDirtyTracker.php`
- Create: `database/migrations/2026_07_24_235911_create_catalog_discovery_metric_dirty_titles.php`
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Modify: `app/Services/Catalog/CatalogPopularityQuery.php`
- Modify: `app/Services/Catalog/CatalogRecommendationCacheInvalidator.php`
- Modify: applicable watchlist/progress/review/comment/import mutation owners found by repository-wide search
- Modify: `routes/console.php`
- Modify: `tests/Feature/CatalogDiscoveryMetricProjectionTest.php`
- Modify: `tests/Feature/CatalogPopularityQueryTest.php`

**Dirty ledger:**

```php
Schema::create('catalog_discovery_metric_dirty_titles', function (Blueprint $table): void {
    $table->foreignId('catalog_title_id')->primary()->constrained()->cascadeOnDelete();
    $table->string('reason', 48);
    $table->timestamp('marked_at');
});
```

Не переиспользовать `catalog_recommendation_dirty_titles`: у similarity build другой consumer lifecycle, и consumption одной системой не должно терять работу другой.

Read path:

```php
if ($this->projectionReadiness->canRead()) {
    return $this->metrics->rankedIds($context, $excludedIds, $this->candidateLimit());
}

return $this->authoritativeCandidates($context, $excludedIds);
```

- `CatalogPopularityQuery::apply()` сохраняется для `/titles?sort=popularity`, использует ту же active projection при readiness и тот же authoritative fallback, но перестаёт безусловно добавлять `catalog_titles.*`: caller выбирает projection columns явно.
- Mutation owners после commit делают durable `upsert` title IDs в dirty ledger и targeted recommendation invalidation.
- `Schedule::command('catalog:discovery-metrics-rebuild --dirty')->everyMinute()->withoutOverlapping()` обрабатывает bounded dirty rows.
- Полный rolling rebuild запускается отдельно по расписанию не чаще одного раза в час, чтобы события, вышедшие из 7/30-day window, исчезали без visitor query.
- При scheduler/queue outage dirty rows сохраняются, readiness стареет, request автоматически возвращается к authoritative path.

- [ ] Test no dirty marker is lost on duplicate events.
- [ ] Test failed refresh keeps dirty row.
- [ ] Test projection ranking and current visibility/title loader hide inaccessible titles.
- [ ] Test missing/stale table uses authoritative fallback and does not produce 500.
- [ ] Repository-wide search and document every mutation owner before editing.
- [ ] Run:

```bash
php artisan test tests/Feature/CatalogDiscoveryMetricProjectionTest.php tests/Feature/CatalogPopularityQueryTest.php
php artisan schedule:list
./vendor/bin/pint --dirty --format agent
```

---

## 10. Task 7 — two-phase embedded collection explorer

**Priority:** P0 performance.

**Execution status 26.07.2026:** `completed`. ID-first pagination реализована
Task 51; Task 59 заменила оставшиеся correlated membership counts одним
grouped aggregate по exact page IDs после подтверждённого clean ownership
`CatalogCollectionQuery.php`.

**Files:**

- Create: `app/Services/Collections/CatalogCollectionSummaryLoader.php`
- Modify after ownership gate: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Create: `tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

**Query contract:**

```php
public function publicDirectory(
    CatalogCollectionSort $sort,
    string $search,
    string $locale,
    int $perPage = 12,
): LengthAwarePaginator;

public function hydratePage(
    LengthAwarePaginator $idPage,
    ?User $viewer,
): LengthAwarePaginator;
```

Phase 1 paginates only eligible collection IDs and deterministic sort columns. Phase 2 makes bounded queries for those IDs:

1. collection rows/translations/owner/category/source relations;
2. один bounded grouped aggregate total + guest-visible count using canonical
   title visibility;
3. restore page ID order in memory.

Collection cover/poster fallback отсутствует по постоянному text-only
контракту. Correlated membership subqueries must not execute before или после
`LIMIT`; grouped aggregate ограничивается exact current-page IDs.

- [x] RED/GREEN: bounded fixtures и production-size snapshot подтверждают,
  что summary query получает только IDs текущей страницы.
- [x] Сохранены search, sort, locale, public/approved/deleted rules, exact
  paginator metadata и deterministic page order.
- [x] GREEN:

```bash
php artisan test tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php tests/Feature/UnifiedDiscoveryCollectionsTest.php
./vendor/bin/pint --dirty --format agent
```

Task 51 already satisfies ID-first pagination/category/order. Task 59 owns the
remaining grouped-summary RED/GREEN and production-size read-only evidence.
Task 59 прошла 71 collection tests / 457 assertions, adjacent discovery
21 / 96, PHPStan нового loader, Pint, docs check, Vite build и Desktop/Mobile
Playwright. На неизменном snapshot первый read стал `138,83 ms` вместо
`211,38 ms`, grouped summary `111,48 ms` вместо `180,78 ms`; warm median
полного directory около `117 ms` при 6 statements. Это локальное evidence, не
SLA.

---

## 11. Task 8 — рабочий upcoming через release calendar и truthful fallback

**Priority:** P1 correctness/content.

**Files:**

- Create: `app/Services/Catalog/CatalogUpcomingDiscoveryQuery.php`
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Modify: `app/Enums/CatalogRecommendationReason.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`
- Create: `tests/Feature/CatalogUpcomingDiscoveryQueryTest.php`
- Modify: `tests/Feature/ReleaseCalendarDefaultViewTest.php`
- Modify: `docs/release-calendar.md`

**Result order:**

```php
public function candidates(CatalogRecommendationContext $context, array $excludedIds): array
{
    return collect($this->confirmedFuture($context, $excludedIds))
        ->concat($this->incompleteSeasonsWithoutDate($context, $excludedIds))
        ->unique('id')
        ->take($this->candidateLimit())
        ->values()
        ->all();
}
```

- Confirmed future использует `ReleaseScheduleVisibility`, public `release_schedule_entries`, non-terminal statuses и canonical date precision.
- Fallback выбирает только published/visible titles с сезоном, где `episodes_total > episodes_released`; он не создаёт schedule row и не подставляет дату.
- Reasons различают `upcoming_confirmed` и `upcoming_date_unknown`. UI для второго показывает «Ожидаются новые серии · дата пока не объявлена».
- Authenticated relevance может менять порядок только после access filtering; user state не попадает в shared guest cache.

- [ ] RED: confirmed row precedes incomplete/no-date row.
- [ ] RED: released/cancelled/private/hidden entries are absent.
- [ ] RED: no episode `released_at` is required for incomplete fallback.
- [ ] GREEN:

```bash
php artisan test tests/Feature/CatalogUpcomingDiscoveryQueryTest.php tests/Feature/ReleaseCalendarDefaultViewTest.php
./vendor/bin/pint --dirty --format agent
```

---

## 12. Task 9 — editorial readiness и реальный featured canary

**Priority:** P1 content/integration.

**Canonical dependency:** выполнить Tasks 8–9 и 15 из [`2026-07-24-editorial-collections-improvement-master-plan.md`](2026-07-24-editorial-collections-improvement-master-plan.md). Этот план не дублирует curation/admin/matcher implementation.

**Files in this discovery change set:**

- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Create: `tests/Feature/CatalogEditorialDiscoveryTest.php`
- Modify: `docs/catalog-search.md`

**Readiness contract:**

- approved + public + published + featured;
- local editorial collection: минимум 12 guest-visible playable titles;
- source-managed collection: минимум 4 guest-visible playable titles;
- unavailable guest-visible items: 0;
- readiness failure excludes collection and emits only allowlisted operational reason outside public response.

Unrestricted fallback to 54 unfeatured collections is rejected: он нарушает canonical featured-only editorial architecture и выдаёт тонкие/непроверенные списки за готовый editorial result.

- [ ] RED: thin featured collection does not enter discovery.
- [ ] RED: readiness-passing featured collection preserves manual item order and produces unique eligible IDs.
- [ ] GREEN discovery tests.
- [ ] Separately, through existing authorized collection UI/service, activate one real readiness-passing collection as production canary only after explicit operator gate.
- [ ] Verify cache invalidation, SEO change from empty noindex to non-empty canonical behavior and rollback by removing only `is_featured`.

No production content mutation belongs to the code commit.

---

## 13. Task 10 — personalized query consolidation и v2 recovery gate

**Priority:** P1 performance/quality.

**Files:**

- Create: `app/DTOs/CatalogPersonalizedSignalSnapshot.php`
- Create: `app/Services/Catalog/CatalogPersonalizedSignalSnapshotQuery.php`
- Modify: `app/Services/Catalog/CatalogPersonalizedRecommendationQuery.php`
- Modify: `tests/Feature/CatalogPersonalizedRecommendationQueryTest.php`
- Modify: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`
- Reference/update: `docs/superpowers/plans/2026-07-16-recommendation-similarity-v6.md`

**Snapshot boundary:**

```php
final readonly class CatalogPersonalizedSignalSnapshot
{
    public function __construct(
        public array $sourceTitleIds,
        public array $ratings,
        public array $watchStates,
        public array $collectionIds,
        public array $personalTagIds,
        public array $feedback,
    ) {}
}
```

`CatalogPersonalizedSignalSnapshotQuery::forUser(User $user, int $historyLimit): CatalogPersonalizedSignalSnapshot` loads each bounded signal family once, then candidate scoring reuses the in-memory maps. No query occurs inside candidate/title loops.

- [ ] Characterize all 57 current statements and map each to a signal owner before changing code.
- [ ] RED query-budget test `<=25` with the same result order as legacy fixtures.
- [ ] Preserve negative feedback, history/watchlist demotions, source-title exclusions, owner-only access and current confidence behavior.
- [ ] Repair stale-heartbeat shadow build only in the existing similarity-v6 plan; do not create a second build pipeline.
- [ ] Keep `RECOMMENDATIONS_PERSONALIZED_V2_ENABLED=false` and rollout `0` until one accepted build, golden coverage and offline quality comparison pass.
- [ ] Run:

```bash
php artisan test tests/Feature/CatalogPersonalizedRecommendationQueryTest.php tests/Feature/CatalogDiscoveryQueryBudgetTest.php
./vendor/bin/pint --dirty --format agent
```

---

## 14. Task 11 — progressive facets и mode-specific UI

**Priority:** P1 UX/performance.

**Files:**

- Modify: `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`
- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`
- Create: `tests/Feature/CatalogDiscoveryFacetLoadingTest.php`
- Modify: `tests/Feature/CatalogDiscoveryInteractionTest.php`
- Create: `tests/browser/discovery-sections.spec.js`

**State contract:**

```php
#[Locked]
public bool $advancedFiltersLoaded = false;

public function loadAdvancedFilters(): void
{
    $this->advancedFiltersLoaded = true;
}
```

- Initial request loads `genre` and `country`.
- `tag`, `actor`, `director`, `translation`, `studio` load when the advanced panel opens or when any corresponding URL filter is already active.
- `trending` visibly exposes period; `top_rated` exposes rating source; `personalized` and `random` expose refresh; deterministic pages do not pretend refresh changes ranking unless repeat suppression can supply a full alternative set.
- Next/previous controls use actual `hasMore`; random hides both.
- Empty states distinguish no data, filters removed all data and temporary error.
- No inline business JavaScript, external assets or duplicate components. Mobile layout follows `docs/UI_STANDARDS.md`.

- [ ] RED query test proves initial render does not query five advanced groups.
- [ ] RED browser tests cover open/restore URL filters, refresh, pagination, empty state and keyboard/focus behavior.
- [ ] GREEN:

```bash
php artisan test tests/Feature/CatalogDiscoveryFacetLoadingTest.php tests/Feature/CatalogDiscoveryInteractionTest.php
npm run build
npx playwright test tests/browser/discovery-sections.spec.js --project=\"Desktop Chromium\" --project=\"Mobile Chromium\"
./vendor/bin/pint --dirty --format agent
```

---

## 15. Task 12 — сохранить быстрые режимы, SEO и failure behavior

**Priority:** P1 regression hardening.

**Files:**

- Modify: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`
- Modify: `tests/Feature/PublicCacheRouteSafetyTest.php`
- Modify: applicable SEO/sitemap discovery tests found by repository-wide search
- Modify: `docs/caching.md`
- Modify: `docs/performance.md`

- [ ] Lock `top_rated` candidate query to existing indexed provider path; no projection dependency.
- [ ] Lock `recently_updated` to bounded event windows and unique order; no rewrite unless measured regression exceeds gate.
- [ ] Cover `recently_added` index/order and ensure projection changes do not alter it.
- [ ] Cache outage test: authoritative result or truthful empty/error response, never private leakage or 500.
- [ ] Projection stale/missing test: authoritative fallback and one allowlisted diagnostic.
- [ ] Preserve `noindex,follow` for personalized/random and empty upcoming/editorial; re-evaluate only when real stable content exists.
- [ ] Preserve canonical without stateful filter/collection query parameters and existing sitemap inclusion rules.

Run:

```bash
php artisan test \
  tests/Feature/CatalogDiscoveryQueryBudgetTest.php \
  tests/Feature/PublicCacheRouteSafetyTest.php \
  --filter=Discovery
```

---

## 16. Task 13 — benchmark, staged rollout, documentation и delivery

**Priority:** P0 completion.

**Files:**

- Create: `docs/audits/discovery-sections-performance-report.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/release-calendar.md`
- Modify: `docs/architecture.md`
- Modify: `docs/development.md` only if a new verification command is introduced
- Modify: `docs/README.md` only through the documentation owner map
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: this master plan

### 16.1 Local verification order

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogDiscoveryInteractionTest.php
php artisan test tests/Feature/CatalogDiscoveryQueryBudgetTest.php
php artisan test tests/Feature/CatalogDiscoveryMetricProjectionTest.php
php artisan test tests/Feature/CatalogUpcomingDiscoveryQueryTest.php
php artisan test tests/Feature/CatalogEditorialDiscoveryTest.php
php artisan test tests/Feature/CatalogPersonalizedRecommendationQueryTest.php
php artisan test tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php
php artisan test
npm run build
npx playwright test tests/browser/discovery-sections.spec.js
php artisan project:docs-refresh --check
```

### 16.2 Production rollout order

1. Deploy additive schema/code with projection reads disabled.
2. Run migration only after backup/free-space/writer-impact preflight.
3. Run projection backfill without `--activate`; capture count, duration, peak memory and failure code.
4. Compare projection/authoritative top IDs for `kinopoisk`, `imdb`, week and month contexts.
5. Activate projection version; warm targeted public cache keys without global flush.
6. Canary public reads; verify error rate, query count, latency, cache hit and fallback.
7. Enable bounded dirty reconciliation and hourly rolling rebuild.
8. Activate one readiness-passing editorial canary through existing authorized workflow.
9. Keep personalized v2 disabled until its independent build/quality gate.

### 16.3 Rollback

- Disable projection reads; authoritative queries resume.
- Disable dirty/full rebuild schedule; durable dirty rows remain.
- Revert cache namespace to prior code version; do not flush globally.
- Unfeature editorial canary; collection and memberships remain.
- Revert UI progressive loading only if it breaks state restoration.
- Do not delete projection tables during emergency rollback.

### 16.4 Final evidence

- [ ] Save five cold/five warm samples per route, raw query counts and `EXPLAIN QUERY PLAN`.
- [ ] Verify all nine guest routes in Russian/default locale on desktop/mobile.
- [ ] Verify authenticated personalized on desktop/mobile.
- [ ] Search repository for old popularity aggregates, duplicate discovery routes/services, stale cache keys, direct release-date logic, unguarded unfeatured editorial queries, queries in Blade and dead pagination controls.
- [ ] Re-read all applicable canonical requirements and mark every matrix row `completed`, `already_compliant`, `not_applicable` or `unresolved`.
- [ ] Update `README.md` visitor history only after behavior is actually released.
- [ ] Before commit: verify `git status --short --branch`, exact manifest and `main`.
- [ ] Commit only owned files, then push configured remote. External rejection is `unresolved`, never success.

---

## 17. Task-specific requirement-compliance matrix

| Requirement | Planning status | Implementation evidence required |
| --- | --- | --- |
| Root/index/canonical docs read | `completed` | Fresh reread before every change set |
| Existing implementation inspected | `completed` | Route → Livewire → service → cache/query → loader → Blade call graph |
| Installed versions verified | `completed` | Runtime/package inventory in plan header |
| Official Laravel/Livewire guidance | `completed` | Boost documentation used for query/cache/computed/lazy decisions |
| Authentication/privacy | `planned` | Guest/auth cache isolation and owner-only tests |
| Authorization | `already_compliant` | Existing feedback and collection policies preserved |
| Caching | `planned` | Scalar v3 pool, targeted invalidation, outage/fallback tests |
| Database/migrations | `planned` | Additive reversible schema, no migration backfill, readiness/rollback |
| Production operations | `planned` | Backup/space/canary/failure recovery and disabled-first rollout |
| Search/facets | `planned` | Progressive loading and active URL filter restoration |
| SEO/sitemap | `planned` | Stable/empty/private canonical and noindex tests |
| Release calendar | `planned` | Canonical future rows plus truthful incomplete-season fallback |
| Editorial collections | `planned_dependency` | Existing master Tasks 8–9/15 and featured canary gate |
| Personalized recommendations | `planned_dependency` | Legacy query budget and existing v6 build/quality gate |
| Mobile/accessibility | `planned` | Desktop/mobile Playwright and Axe |
| Documentation/README/CHANGELOG | `planned` | Updated only with verified implementation/release state |
| Commit/push | `unresolved` | Shared worktree currently contains foreign changes and `main` is ahead of remote |

## 18. Expected implementation manifest and protected contracts

**Expected new owners:** projection models/services/command/migrations, upcoming query, personalized snapshot query, collection summary loader, focused PHPUnit/Playwright/audit files.

**Expected modified owners:** `CatalogDiscoveryPage`, recommendation service/cache/public query/exclusions, popularity query, facet query, relevant mutation owners, collection query after ownership gate, translations/config/schedule and canonical docs.

**Must remain compatible:** public/localized routes, enum values, API resources, title route binding, visibility/watchability, policies/gates, feedback/undo, collection URLs/covers/profile/API, release calendar records, importer command/options, cache domain/invalidation contract, SEO canonical/noindex, RU/EN keys, SQLite, current player/premium/region/legal boundaries.

**No expected route change.** **No destructive migration.** **No new production dependency.** **No cache flush.** **No automatic editorial publication.**
