# Full Public Cache Warming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в `cache:warm-catalog` возобновляемый queued-режим, который обходит весь явный безопасный набор канонических гостевых страниц и никогда не кэширует чувствительные маршруты.

**Architecture:** Существующий `critical` прогрев остаётся неизменным. Новый `all-public` coordinator хранит только generation/cursor/counters в operational Redis, получает ограниченные порции URL из детерминированного источника и последовательно вызывает same-origin HTTP warmer. Любой неизвестный, авторизованный, signed, download, user-profile или mutating route закрыт по умолчанию.

**Tech Stack:** PHP 8.5, Laravel 13.20, Laravel Queue/Cache/HTTP Client, Redis locks, SQLite, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Не менять `.env`; новые параметры добавлять в `config/cache-architecture.php` и `.env.example`.
- Не добавлять production dependencies.
- Не хранить в state Eloquent models, HTML, cookies, tokens, raw media URL или private identifiers.
- Не кэшировать authenticated, signed, download, admin, account, Livewire, health и mutating routes.
- Не перечислять search и комбинации `season`, `episode`, `media`, `variant`, `quality`, `format`.
- Большие таблицы обходить keyset cursor; не загружать весь каталог в память.
- Все HTTP-вызовы в тестах закрывать через `Http::preventStrayRequests()` и `Http::fake()`.
- После PHP-изменений запускать `./vendor/bin/pint --dirty --format agent`.
- Основной дизайн: `docs/superpowers/specs/2026-07-16-full-public-cache-warming-design.md`.

---

### Task 1: Командный контракт и отдельный scope

**Files:**
- Modify: `app/Console/Commands/WarmCatalogCache.php`
- Create: `tests/Feature/FullPublicCacheWarmCommandTest.php`

**Interfaces:**
- Consumes: существующие `CatalogCacheWarmer`, `CatalogCacheWarmRequestStore`, `WarmCatalogCaches`.
- Produces: options `--scope=critical|all-public`, `--dry-run`, `--resume`; queued dispatch `WarmPublicCatalogCaches`.

- [ ] **Step 1: Write the failing command tests**

```php
public function test_all_public_requires_queue_unless_dry_run(): void
{
    $this->artisan('cache:warm-catalog', ['--scope' => 'all-public'])
        ->expectsOutput('Полный публичный прогрев выполняется только через Redis-очередь. Добавьте --queue.')
        ->assertFailed();
}

public function test_critical_scope_keeps_existing_queue_contract(): void
{
    Queue::fake();

    $this->artisan('cache:warm-catalog', ['--queue' => true])
        ->assertSuccessful();

    Queue::assertPushed(WarmCatalogCaches::class);
    Queue::assertNotPushed(WarmPublicCatalogCaches::class);
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/FullPublicCacheWarmCommandTest.php`

Expected: FAIL because `--scope`, `--dry-run`, `--resume` and `WarmPublicCatalogCaches` do not exist.

- [ ] **Step 3: Add the command options and validation**

```php
#[Signature('cache:warm-catalog
    {--queue : Поставить прогрев в Redis-очередь вместо синхронного выполнения}
    {--refresh : Пересобрать текущие warmable namespaces без удаления читаемого snapshot}
    {--scope=critical : Область прогрева: critical или all-public}
    {--dry-run : Только подсчитать безопасные цели полного прогрева}
    {--resume : Продолжить последнее незавершённое поколение all-public}')]
```

В `handle()` нормализовать scope строгим `in_array($scope, ['critical', 'all-public'], true)`, отклонить `--resume` для `critical`, сохранить прежнюю ветку `critical`, а `all-public` передать в сервисы следующих задач. Все сообщения команды оставить на русском языке.

- [ ] **Step 4: Run command tests and verify GREEN for validation paths**

Run: `php artisan test tests/Feature/FullPublicCacheWarmCommandTest.php --filter='requires_queue|critical_scope'`

Expected: PASS.

---

### Task 2: Типизированные цели и конечный безопасный источник URL

**Files:**
- Create: `app/DTOs/PublicCacheWarmTarget.php`
- Create: `app/DTOs/PublicCacheWarmBatch.php`
- Create: `app/Services/Catalog/PublicCatalogWarmTargetSource.php`
- Create: `tests/Unit/PublicCatalogWarmTargetSourceTest.php`

**Interfaces:**
- Produces: `PublicCatalogWarmTargetSource::batch(?array $cursor, int $limit): PublicCacheWarmBatch`.
- Produces: `PublicCatalogWarmTargetSource::estimate(): array{targets:int, by_source:array<string,int>}`.
- Produces: `PublicCacheWarmTarget(relativeUrl, accept, kind)` and array-only cursor.

- [ ] **Step 1: Write failing source tests**

```php
public function test_source_lists_only_canonical_guest_targets(): void
{
    CatalogTitle::factory()->create(['slug' => 'safe-title']);

    $batch = app(PublicCatalogWarmTargetSource::class)->batch(null, 500);
    $urls = collect($batch->targets)->pluck('relativeUrl');

    $this->assertTrue($urls->contains('/'));
    $this->assertTrue($urls->contains('/titles/safe-title'));
    $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, '/admin')));
    $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, '/api/v1/me')));
    $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, 'episode=')));
}

public function test_source_resumes_titles_by_id_without_duplicates(): void
{
    CatalogTitle::factory()->count(3)->create();
    $first = app(PublicCatalogWarmTargetSource::class)->batch(['source' => 'titles', 'position' => ['last_id' => 0]], 2);
    $second = app(PublicCatalogWarmTargetSource::class)->batch($first->nextCursor, 2);

    $this->assertEmpty(array_intersect(
        array_column($first->targets, 'relativeUrl'),
        array_column($second->targets, 'relativeUrl'),
    ));
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Unit/PublicCatalogWarmTargetSourceTest.php`

Expected: FAIL because DTOs and source do not exist.

- [ ] **Step 3: Implement immutable in-memory DTOs**

```php
final readonly class PublicCacheWarmTarget
{
    public function __construct(
        public string $relativeUrl,
        public string $accept = 'text/html',
        public string $kind = 'page',
    ) {}
}

final readonly class PublicCacheWarmBatch
{
    /** @param list<PublicCacheWarmTarget> $targets */
    public function __construct(
        public array $targets,
        public ?array $nextCursor,
        public bool $completed,
    ) {}
}
```

- [ ] **Step 4: Implement deterministic target segments**

`PublicCatalogWarmTargetSource` обрабатывает источники в фиксированном порядке:

```php
private const SOURCES = [
    'fixed', 'catalog_pages', 'directories', 'discovery', 'titles',
    'years', 'taxonomies', 'collections', 'requests', 'documents', 'public_api',
];
```

Правила источников:

- `fixed`: `/`, supported localized home, `/stats`;
- `catalog_pages`: `/titles` и `?page=N` по `availableTo(null)->count()` при 24 элементах;
- `directories`: каждый route из `CatalogDirectoryRegistry::routeMap()` и страницы по `CatalogDirectoryDefinition::perPage`;
- `discovery`: только `CatalogRecommendationType::isIndexable()`, root и supported locales, страницы 1..`ceil(candidate_limit/page_size)`;
- `titles`: `CatalogTitle::query()->availableTo(null)->where('id', '>', $lastId)->orderBy('id')->limit($limit)->get(['id','slug'])`;
- `years`: grouped available title counts, `/titles/year/{year}` и `?page=N`;
- `taxonomies`: модели из `CatalogTaxonomyRegistry`, `publiclyEligible()` для canonical tags, `withCount()` только `availableTo(null)`, `/titles/{type}/{slug}` и `?page=N`;
- `collections`: `/collections`, localized indexes и `collectionsPage=N` по `CatalogCollection::publiclyListed()`;
- `requests`: directory pages and every `ContentRequest::publiclyVisible()` detail for root and supported locales;
- `documents`: fixed XML/text routes plus title/video/request sitemap page numbers calculated from existing sitemap page sizes;
- `public_api`: only parameter-free anonymous endpoints already protected by `public.cache:*`; never `/me`, search suggestions, sync, playback or title parameters.

Для перехода между sources использовать array cursor вида:

```php
[
    'source' => 'taxonomies',
    'position' => ['type_index' => 2, 'last_id' => 481, 'page' => 3],
]
```

URL нормализовать до relative path, дедуплицировать внутри одного batch и отклонять значения не с `/` или начинающиеся с `//`.

- [ ] **Step 5: Run source tests and verify GREEN**

Run: `php artisan test tests/Unit/PublicCatalogWarmTargetSourceTest.php`

Expected: PASS.

---

### Task 3: Fail-soft same-origin HTTP warmer

**Files:**
- Modify: `app/Services/Catalog/PublicPageCacheWarmer.php`
- Create: `tests/Unit/PublicPageCacheBatchWarmerTest.php`

**Interfaces:**
- Consumes: `iterable<PublicCacheWarmTarget>`.
- Produces: `warmTargets(iterable $targets): array{attempted:int,succeeded:int,failed:int,errors:list<array{fingerprint:string,status:int|null,exception:string|null}>}`.

- [ ] **Step 1: Write failing failure-isolation tests**

```php
public function test_batch_continues_after_one_failed_target(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://seasonvar.test/first' => Http::response('', 500),
        'https://seasonvar.test/second' => Http::response('<html></html>', 200),
    ]);

    $result = app(PublicPageCacheWarmer::class)->warmTargets([
        new PublicCacheWarmTarget('/first'),
        new PublicCacheWarmTarget('/second'),
    ]);

    $this->assertSame(2, $result['attempted']);
    $this->assertSame(1, $result['succeeded']);
    $this->assertSame(1, $result['failed']);
    Http::assertSentCount(2);
}
```

- [ ] **Step 2: Run test and verify RED**

Run: `php artisan test tests/Unit/PublicPageCacheBatchWarmerTest.php`

Expected: FAIL because `warmTargets()` does not exist.

- [ ] **Step 3: Implement bounded fail-soft warming**

Вынести создание `PendingRequest` по `Accept`, отключить redirects через `withOptions(['allow_redirects' => false])`, сохранить current timeouts/retries and internal header. Для каждой ошибки сохранять только `hash('sha256', $relativeUrl)`, status и exception class. Existing `warm()` вызывает общий request primitive в fail-fast режиме, чтобы не менять critical behavior.

- [ ] **Step 4: Run warmer tests and verify GREEN**

Run: `php artisan test tests/Unit/PublicPageCacheBatchWarmerTest.php tests/Feature/CacheWarmJobTest.php`

Expected: PASS.

---

### Task 4: Redis generation state и resumable queue job

**Files:**
- Create: `app/Services/Catalog/PublicCatalogWarmStateStore.php`
- Create: `app/Jobs/WarmPublicCatalogCaches.php`
- Modify: `app/Console/Commands/WarmCatalogCache.php`
- Modify: `config/cache-architecture.php`
- Modify: `.env.example`
- Modify: `tests/Feature/FullPublicCacheWarmCommandTest.php`
- Create: `tests/Feature/FullPublicCacheWarmJobTest.php`

**Interfaces:**
- Produces: `start(bool $refresh): array`, `resume(): ?array`, `read(): ?array`, `advance(string $generation, PublicCacheWarmBatch $batch, array $result): array`.
- Produces: queued `WarmPublicCatalogCaches(string $generation, bool $refresh = false)`.

- [ ] **Step 1: Write failing state/job tests**

```php
public function test_job_advances_cursor_and_dispatches_one_tail(): void
{
    Queue::fake();
    $state = app(PublicCatalogWarmStateStore::class)->start(false);

    (new WarmPublicCatalogCaches($state['generation']))->handle(
        app(PublicCatalogWarmStateStore::class),
        app(PublicCatalogWarmTargetSource::class),
        app(PublicPageCacheWarmer::class),
    );

    $updated = app(PublicCatalogWarmStateStore::class)->read();
    $this->assertGreaterThan(0, $updated['attempted']);
    Queue::assertPushed(WarmPublicCatalogCaches::class, 1);
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/FullPublicCacheWarmJobTest.php tests/Feature/FullPublicCacheWarmCommandTest.php`

Expected: FAIL because state store/job are missing.

- [ ] **Step 3: Implement array-only Redis state under atomic lock**

State shape:

```php
[
    'generation' => (string) Str::uuid(),
    'status' => 'queued',
    'refresh' => false,
    'cursor' => null,
    'estimated' => 0,
    'attempted' => 0,
    'warmed' => 0,
    'failed' => 0,
    'last_errors' => [],
    'started_at' => null,
    'updated_at' => now()->toIso8601String(),
    'finished_at' => null,
]
```

Использовать `Cache::store(config('cache-architecture.stores.locks'))`, `LockProvider`, `CacheKeyFactory`, retention и lock timeouts из config. Хранить не более `full_error_limit` diagnostics.

- [ ] **Step 4: Implement the queued coordinator**

Job implements `ShouldQueue` and `ShouldBeUniqueUntilProcessing`, uses `WithoutOverlapping('catalog-cache-warm-all-public-v1')->expireAfter($timeout + 30)->releaseAfter(60)`, three tries, `[60, 300, 900]` backoff, `afterCommit()`, current queue connection/name and locks store. One execution processes at most `full_batch_url_limit` targets and `full_job_budget_seconds`, updates heartbeat, and dispatches one tail only when `completed === false`.

Если `seasonvar_import_runs` содержит status `queued`, `discovering`, `running` или `finalizing`, job вызывает `$this->release(full_import_pause_seconds)` без движения cursor.

- [ ] **Step 5: Wire command start/resume/dry-run**

`--dry-run` вызывает `estimate()` и выводит total/by-source без dispatch. `--resume` получает незавершённое state или возвращает русскую ошибку. Новый старт создаёт state и dispatches job. Existing `critical` branch remains byte-for-byte equivalent apart from scope validation.

- [ ] **Step 6: Add config/env defaults**

```php
'full_batch_url_limit' => (int) env('CACHE_WARM_FULL_BATCH_URL_LIMIT', 25),
'full_job_budget_seconds' => (int) env('CACHE_WARM_FULL_JOB_BUDGET_SECONDS', 180),
'full_request_delay_milliseconds' => (int) env('CACHE_WARM_FULL_REQUEST_DELAY_MILLISECONDS', 100),
'full_error_limit' => (int) env('CACHE_WARM_FULL_ERROR_LIMIT', 100),
'full_import_pause_seconds' => (int) env('CACHE_WARM_FULL_IMPORT_PAUSE_SECONDS', 300),
'full_state_retention_seconds' => (int) env('CACHE_WARM_FULL_STATE_RETENTION_SECONDS', 2_592_000),
```

- [ ] **Step 7: Run command/job tests and verify GREEN**

Run: `php artisan test tests/Feature/FullPublicCacheWarmCommandTest.php tests/Feature/FullPublicCacheWarmJobTest.php tests/Feature/CacheWarmJobTest.php tests/Feature/CacheWarmScheduleTest.php`

Expected: PASS.

---

### Task 5: Route-level cache safety

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Support/Cache/PublicPageCachePolicy.php`
- Create: `tests/Feature/PublicCacheRouteSafetyTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Produces: `public.page:discovery` only for indexable discovery types.
- Removes: page cache middleware from `profiles.collections`.
- Adds: collections profile only to localized collection directory, never collection/profile detail.

- [ ] **Step 1: Write failing route safety tests**

```php
public function test_sensitive_routes_never_have_public_page_cache(): void
{
    $forbidden = ['admin.catalog', 'profile.show', 'profiles.collections', 'localized.profiles.collections', 'playback.source', 'titles.media.download'];

    foreach ($forbidden as $name) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
        $this->assertFalse(collect($middleware)->contains(fn (string $item): bool => str_contains($item, 'CachePublicPage')));
    }
}

public function test_only_indexable_discovery_types_receive_shared_context(): void
{
    $this->get('/discover/popular')->assertHeader('X-Seasonvar-Page-Cache', 'MISS');
    $this->get('/discover/random')->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/PublicCacheRouteSafetyTest.php`

Expected: FAIL because discovery has no page cache and profile collections still has it.

- [ ] **Step 3: Implement discovery policy and route middleware changes**

Add a `discovery` profile to `PublicPageCachePolicy` whose context returns `CatalogPages` only when route parameter `type` resolves to `CatalogRecommendationType` and `isIndexable()` is true. Allow only canonical `page` for this profile. Apply `public.page:discovery` to root/localized discovery routes, apply `public.page:collections` to `localized.collections.index`, and remove it from `profiles.collections`.

- [ ] **Step 4: Add route inventory contract**

Test all routes with `CachePublicPage` and assert their names belong to the explicit patterns:

```php
$allowed = [
    'home', 'localized.home', 'stats', 'titles.index', 'titles.year',
    'titles.taxonomy', 'titles.show', 'collections.index',
    'localized.collections.index', 'requests.index', 'requests.show',
    'localized.requests.index', 'localized.requests.show',
    'discover.index', 'localized.discover.index', 'legacy.tags.show',
];
```

Directory `*.index` names are added from `CatalogDirectoryRegistry::routeMap()`. Any other shared-cache route fails the test.

- [ ] **Step 5: Run route/cache tests and verify GREEN**

Run: `php artisan test tests/Feature/PublicCacheRouteSafetyTest.php tests/Feature/CatalogPageTest.php`

Expected: PASS.

---

### Task 6: Health state and operational reporting

**Files:**
- Modify: `app/Services/Operations/InfrastructureHealthCheck.php`
- Modify: `tests/Feature/CacheInfrastructureIntegrationTest.php`
- Modify: `tests/Feature/FullPublicCacheWarmJobTest.php`

**Interfaces:**
- Consumes: `PublicCatalogWarmStateStore::read()`.
- Produces: independent `full_cache_warming` health component.

- [ ] **Step 1: Write failing health assertions**

```php
$components = $this->artisanJson('app:health --json')['components'];
$this->assertArrayHasKey('full_cache_warming', $components);
$this->assertContains($components['full_cache_warming']['status'], ['idle', 'running', 'ok', 'degraded']);
```

- [ ] **Step 2: Run test and verify RED**

Run: `php artisan test tests/Feature/CacheInfrastructureIntegrationTest.php --filter=health`

Expected: FAIL because component is absent.

- [ ] **Step 3: Add non-blocking full warm health component**

Map no state to `idle`, queued/running with fresh `updated_at` to `running`, completed to `ok`, completed_with_failures or stale heartbeat to `degraded`, failed to `failed`. A healthy long-running full pass must not mark readiness false; stale/failed state may mark aggregate health degraded.

- [ ] **Step 4: Run health tests and verify GREEN**

Run: `php artisan test tests/Feature/CacheInfrastructureIntegrationTest.php tests/Feature/FullPublicCacheWarmJobTest.php`

Expected: PASS.

---

### Task 7: Documentation and operator contract

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/caching.md`
- Modify: `docs/deployment.md`

**Interfaces:**
- Documents: safe scope, exclusions, dry-run, queued start, resume, state/health and rollout.

- [ ] **Step 1: Check the documentation ownership map**

Run: `sed -n '1,240p' docs/README.md`

Expected: identifies `docs/caching.md` and the deployment/operations owner document.

- [ ] **Step 2: Update Russian documentation**

Document exact commands:

```bash
php artisan cache:warm-catalog --scope=all-public --dry-run
php artisan cache:warm-catalog --scope=all-public --queue
php artisan cache:warm-catalog --scope=all-public --queue --resume
```

State explicitly that sensitive/private/signed/download/search/media-selection routes are excluded and that the ten-minute schedule remains `critical` only. Add a dated visitor-facing README history item without internal implementation terminology.

- [ ] **Step 3: Refresh managed documentation only if required**

Run when an owned managed input changed: `php artisan project:docs-refresh`

Expected: only managed `project-docs` blocks change.

- [ ] **Step 4: Verify documentation policy**

Run: `scripts/check-readme-policy.sh`

Expected: PASS.

---

### Task 8: Formatting, regression verification and commit

**Files:**
- Review: all files changed by Tasks 1–7

**Interfaces:**
- Produces: verified cache warming implementation on `main`.

- [ ] **Step 1: Format PHP changes**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: PASS and only task-owned PHP files are reformatted.

- [ ] **Step 2: Run focused cache suite**

Run: `php artisan test tests/Feature/FullPublicCacheWarmCommandTest.php tests/Feature/FullPublicCacheWarmJobTest.php tests/Unit/PublicCatalogWarmTargetSourceTest.php tests/Unit/PublicPageCacheBatchWarmerTest.php tests/Feature/PublicCacheRouteSafetyTest.php tests/Feature/CacheWarmJobTest.php tests/Feature/CacheWarmScheduleTest.php tests/Feature/CacheInfrastructureIntegrationTest.php`

Expected: 0 failures.

- [ ] **Step 3: Run broader regression tests**

Run: `php artisan test`

Expected: 0 failures.

- [ ] **Step 4: Verify command discovery and dry-run**

Run: `php artisan list --raw | rg '^cache:warm-catalog'`

Expected: command is registered.

Run: `php artisan cache:warm-catalog --scope=all-public --dry-run`

Expected: successful Russian summary with total targets and no queue/HTTP mutations.

- [ ] **Step 5: Review security diff**

Run: `git diff -- app/Console/Commands/WarmCatalogCache.php app/Jobs/WarmPublicCatalogCaches.php app/Services/Catalog app/Support/Cache/PublicPageCachePolicy.php routes/web.php config/cache-architecture.php .env.example`

Expected: no private route, token, cookie, raw media URL, unrestricted external URL or cache flush.

- [ ] **Step 6: Commit only task-owned changes**

Run: `git status --short --branch`

Expected: branch is `main`; foreign concurrent changes remain identified and unstaged.

Stage the exact task files and commit with:

```bash
git commit -m "feat: warm all safe public catalog pages"
```
