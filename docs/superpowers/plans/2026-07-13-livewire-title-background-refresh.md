# Livewire Title Background Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refresh a visible Seasonvar title, every known season, every episode, and external media in the background when its public page is opened, then update the complete open page through Livewire without interrupting valid playback.

**Architecture:** A cache-backed state store and atomic coordinator enforce a 15-minute success window and one dispatch per title. A unique job runs the existing forced targeted importer under the existing title-group lock on `seasonvar-import`. A parent Livewire component rerenders the complete title shell every three seconds while work is active and dispatches a scoped event that makes the nested player reread seasons, episodes, and media while retaining valid URL selection.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Redis-backed Laravel cache locks, Laravel queues, Eloquent, Blade, Tailwind CSS 4.3, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Verify the worktree is clean before implementation and stop if unrelated changes appear during execution.
- `php artisan seasonvar:import` remains the only public Seasonvar import command.
- The public request accepts only a server-bound catalog title id; it never accepts a source URL or import option from the browser.
- Normalize and allow only `https://seasonvar.ru/` or `https://www.seasonvar.ru/` catalog URLs through `SeasonvarUrl`.
- Do not download video files; persist only existing external playback metadata.
- Treat a successful visitor-triggered refresh as fresh for exactly 15 minutes.
- Poll exactly every three seconds with `wire:poll.3s.visible` and only while state is `queued` or `running`.
- Keep Russian visible UI text and English translation parity in `lang/en/catalog.php`.
- Do not expose raw Seasonvar URLs, external media URLs, exceptions, lock tokens, or queue payloads through HTML or Livewire state.
- Use existing title visibility, playback authorization, importer transaction, retry, timeout, cache invalidation, and title-group locking boundaries.
- Do not add a production dependency or migration.
- Write a failing PHPUnit test before each production behavior and observe the expected failure.
- Run Pint after PHP edits and `npm run build` after Blade/Tailwind changes.

---

### Task 1: Per-title refresh state and atomic dispatch coordinator

**Files:**
- Create: `app/DTOs/CatalogTitleRefreshState.php`
- Create: `app/Services/Seasonvar/CatalogTitleRefreshStateStore.php`
- Create: `app/Services/Seasonvar/CatalogTitleRefreshCoordinator.php`
- Modify: `config/seasonvar.php:25-36`
- Modify: `.env.example:240-241`
- Test: `tests/Feature/CatalogTitleBackgroundRefreshTest.php`

**Interfaces:**
- Consumes: `CatalogTitle`, `SeasonvarImportStatus`, `CacheKeyFactory`, the configured domain/lock stores, and Laravel's `Illuminate\Contracts\Bus\Dispatcher`.
- Produces: `CatalogTitleRefreshState::fromArray(array $state): self`, `CatalogTitleRefreshState::toArray(): array`, `CatalogTitleRefreshStateStore::read(int $catalogTitleId): CatalogTitleRefreshState`, state transition methods, and `CatalogTitleRefreshCoordinator::request(CatalogTitle $catalogTitle): CatalogTitleRefreshState`.

- [ ] **Step 1: Verify the clean main worktree before touching production files**

Run:

```bash
git status --short --branch
```

Expected: the command reports `main`, ahead of `origin/main`, with only this implementation plan untracked before its plan commit. After the plan commit, the worktree must be clean. If unrelated changes appear, stop and identify their owner before editing overlapping files.

- [ ] **Step 2: Write failing state/coordinator tests**

Create `tests/Feature/CatalogTitleBackgroundRefreshTest.php` with `RefreshDatabase`. In `setUp()`, use the array stores and deterministic limits:

```php
config([
    'cache-architecture.stores.domain' => 'array',
    'seasonvar.queue.lock_store' => 'array',
    'seasonvar.title_refresh.fresh_minutes' => 15,
    'seasonvar.title_refresh.state_ttl_seconds' => 86_400,
    'seasonvar.title_refresh.active_seconds' => 21_900,
    'seasonvar.title_refresh.dispatch_lock_seconds' => 10,
]);

Cache::store('array')->flush();
Queue::fake();
```

Add these tests with a published factory title whose `source_url` is `https://seasonvar.ru/serial-42-Test-1-season.html` and whose `source_url_hash` matches it:

```php
public function test_it_queues_one_refresh_for_concurrent_visits_and_respects_the_success_window(): void
{
    $title = $this->refreshableTitle();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->twice()->andReturn(1);
    $this->app->instance(Dispatcher::class, $dispatcher);
    $coordinator = app(CatalogTitleRefreshCoordinator::class);
    $states = app(CatalogTitleRefreshStateStore::class);

    $this->assertSame('queued', $coordinator->request($title)->status?->value);
    $this->assertSame('queued', $coordinator->request($title)->status?->value);

    $states->completed($title->id, 91);
    $this->travel(14)->minutes();
    $this->assertSame('completed', $coordinator->request($title)->status?->value);

    $this->travel(2)->minutes();
    $this->assertSame('queued', $coordinator->request($title)->status?->value);
}

public function test_it_recovers_expired_active_state_but_does_not_queue_a_title_without_a_source_url(): void
{
    $title = $this->refreshableTitle();
    $states = app(CatalogTitleRefreshStateStore::class);
    $coordinator = app(CatalogTitleRefreshCoordinator::class);

    $states->queued($title->id);
    $this->travel(21_901)->seconds();
    $this->assertSame('queued', $coordinator->request($title)->status?->value);

    $title->update(['source_url' => null, 'source_url_hash' => null]);
    $states->forget($title->id);
    $this->assertNull($coordinator->request($title->fresh())->status);
    Queue::assertPushed(RefreshSeasonvarCatalogTitle::class, 1);
}
```

Inject a mocked `Dispatcher` that throws `RuntimeException('queue unavailable')` and add:

```php
public function test_it_returns_sanitized_failed_state_when_dispatch_throws(): void
{
    $title = $this->refreshableTitle();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    $this->app->instance(Dispatcher::class, $dispatcher);

    $state = app(CatalogTitleRefreshCoordinator::class)->request($title);

    $this->assertSame('failed', $state->status?->value);
    $this->assertArrayNotHasKey('error', $state->toArray());
    $this->assertStringNotContainsString('queue unavailable', json_encode($state->toArray(), JSON_THROW_ON_ERROR));
}
```

Use a private `refreshableTitle(): CatalogTitle` factory helper. Import `CatalogTitle`, `CatalogTitleRefreshCoordinator`, `CatalogTitleRefreshStateStore`, `RefreshSeasonvarCatalogTitle`, `Cache`, `Queue`, `Dispatcher`, `Mockery`, and `RuntimeException` explicitly.

- [ ] **Step 3: Run the tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/CatalogTitleBackgroundRefreshTest.php
```

Expected: FAIL because the DTO, store, coordinator, and refresh job do not exist.

- [ ] **Step 4: Add the typed state value**

Create `app/DTOs/CatalogTitleRefreshState.php` as a final readonly DTO. Store only scalar values in cache:

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SeasonvarImportStatus;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class CatalogTitleRefreshState
{
    public function __construct(
        public ?SeasonvarImportStatus $status = null,
        public ?Carbon $queuedAt = null,
        public ?Carbon $startedAt = null,
        public ?Carbon $completedAt = null,
        public ?Carbon $failedAt = null,
        public ?Carbon $activeUntil = null,
        public ?int $importRunId = null,
    ) {}

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        return new self(
            status: is_string($state['status'] ?? null) ? SeasonvarImportStatus::tryFrom($state['status']) : null,
            queuedAt: self::date($state['queued_at'] ?? null),
            startedAt: self::date($state['started_at'] ?? null),
            completedAt: self::date($state['completed_at'] ?? null),
            failedAt: self::date($state['failed_at'] ?? null),
            activeUntil: self::date($state['active_until'] ?? null),
            importRunId: is_numeric($state['import_run_id'] ?? null) ? (int) $state['import_run_id'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status?->isActive() === true && $this->activeUntil?->isFuture() === true;
    }

    public function isFresh(int $minutes): bool
    {
        return $this->status === SeasonvarImportStatus::Completed
            && $this->completedAt?->greaterThan(now()->subMinutes(max(1, $minutes))) === true;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'status' => $this->status?->value,
            'queued_at' => $this->queuedAt?->toIso8601String(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),
            'failed_at' => $this->failedAt?->toIso8601String(),
            'active_until' => $this->activeUntil?->toIso8601String(),
            'import_run_id' => $this->importRunId,
        ];
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Add the scalar cache state store**

Create `app/Services/Seasonvar/CatalogTitleRefreshStateStore.php`. Use `CacheKeyFactory::data(CacheDomain::Operational, 'catalog-title-refresh', ['catalog_title_id' => $id], 1)` so keys remain environment/schema scoped without being invalidated by unrelated catalog version bumps. Implement:

```php
public function read(int $catalogTitleId): CatalogTitleRefreshState;
public function queued(int $catalogTitleId): CatalogTitleRefreshState;
public function running(int $catalogTitleId): CatalogTitleRefreshState;
public function completed(int $catalogTitleId, int $importRunId): CatalogTitleRefreshState;
public function failed(int $catalogTitleId): CatalogTitleRefreshState;
public function forget(int $catalogTitleId): void;
public function dispatchLockKey(int $catalogTitleId): string;
```

`queued()` writes `queued_at`, `active_until`, and clears terminal fields. `running()` preserves `queued_at`, adds `started_at`, and renews `active_until`. `completed()` preserves start fields, writes `completed_at`, `import_run_id`, and clears `active_until`. `failed()` preserves non-sensitive timestamps and writes `failed_at`; it never accepts an exception or message. Use the configured domain store for values and `seasonvar.title_refresh.state_ttl_seconds` for TTL:

```php
private function write(int $catalogTitleId, CatalogTitleRefreshState $state): CatalogTitleRefreshState
{
    Cache::store($this->stateStore())->put(
        $this->key($catalogTitleId),
        $state->toArray(),
        max(60, (int) config('seasonvar.title_refresh.state_ttl_seconds', 86_400)),
    );

    return $state;
}
```

`read()` catches cache-read failures, reports them, and returns a new empty `CatalogTitleRefreshState` so the public title page remains usable. Transition writes remain throwable: the coordinator catches request-side failures, while queue failures remain retryable and observable.

- [ ] **Step 6: Add the atomic coordinator and configuration**

Create `app/Services/Seasonvar/CatalogTitleRefreshCoordinator.php` with constructor injection for `CatalogTitleRefreshStateStore` and `Dispatcher`. The public flow must be:

```php
public function request(CatalogTitle $catalogTitle): CatalogTitleRefreshState
{
    if (trim((string) $catalogTitle->source_url) === '') {
        return new CatalogTitleRefreshState;
    }

    $lock = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'))->lock(
        $this->states->dispatchLockKey($catalogTitle->id),
        max(1, (int) config('seasonvar.title_refresh.dispatch_lock_seconds', 10)),
    );

    if (! $lock->get()) {
        return $this->states->read($catalogTitle->id);
    }

    try {
        $state = $this->states->read($catalogTitle->id);

        if ($state->isActive() || $state->isFresh((int) config('seasonvar.title_refresh.fresh_minutes', 15))) {
            return $state;
        }

        $state = $this->states->queued($catalogTitle->id);

        try {
            $this->dispatcher->dispatch(
                (new RefreshSeasonvarCatalogTitle($catalogTitle->id))->afterCommit(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->states->failed($catalogTitle->id);
        }

        return $state;
    } finally {
        $lock->release();
    }
}
```

Add this configuration to `config/seasonvar.php`:

```php
'title_refresh' => [
    'fresh_minutes' => (int) env('SEASONVAR_TITLE_REFRESH_FRESH_MINUTES', 15),
    'state_ttl_seconds' => (int) env('SEASONVAR_TITLE_REFRESH_STATE_TTL_SECONDS', 86_400),
    'active_seconds' => (int) env('SEASONVAR_TITLE_REFRESH_ACTIVE_SECONDS', 21_900),
    'dispatch_lock_seconds' => (int) env('SEASONVAR_TITLE_REFRESH_DISPATCH_LOCK_SECONDS', 10),
],
```

Document the four matching defaults in `.env.example` after the existing Seasonvar queue variables.

- [ ] **Step 7: Add the temporary job class shell needed by coordinator tests**

Create `app/Jobs/RefreshSeasonvarCatalogTitle.php` with the final class name, scalar constructor, queue selection, and unique policy. Its full execution behavior is completed test-first in Task 2. The shell must compile:

```php
class RefreshSeasonvarCatalogTitle implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout;
    public int $uniqueFor;
    public readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $catalogTitleId)
    {
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $this->uniqueFor = max(300, (int) config('seasonvar.title_refresh.active_seconds', 21_900));
        $this->retryUntilTimestamp = now()->addSeconds($this->uniqueFor)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function uniqueId(): string
    {
        return 'catalog-title-refresh:'.$this->catalogTitleId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }
}
```

- [ ] **Step 8: Run focused tests and verify GREEN**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CatalogTitleBackgroundRefreshTest.php
```

Expected: Pint exits 0 and all coordinator/state tests pass.

- [ ] **Step 9: Commit Task 1**

Run `git status --short --branch`, verify `main`, then commit only Task 1 files:

```bash
git add .env.example config/seasonvar.php app/DTOs/CatalogTitleRefreshState.php app/Services/Seasonvar/CatalogTitleRefreshStateStore.php app/Services/Seasonvar/CatalogTitleRefreshCoordinator.php app/Jobs/RefreshSeasonvarCatalogTitle.php tests/Feature/CatalogTitleBackgroundRefreshTest.php
git commit -m "feat: coordinate title background refreshes"
```

---

### Task 2: Unique targeted refresh job

**Files:**
- Modify: `app/Jobs/RefreshSeasonvarCatalogTitle.php`
- Test: `tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php`
- Verify existing integration: `tests/Feature/SeasonvarParsePageCommandTest.php:38-150`

**Interfaces:**
- Consumes: `CatalogTitleRefreshStateStore`, `SeasonvarImportPipeline::run()`, `SeasonvarUrl`, `SeasonvarImportGroupKey`, `CatalogTitle`, and the existing queue configuration.
- Produces: a complete `RefreshSeasonvarCatalogTitle::handle(SeasonvarImportPipeline $pipeline, SeasonvarUrl $urls, SeasonvarImportGroupKey $groupKeys, CatalogTitleRefreshStateStore $states): void`, `backoff(): list<int>`, `retryUntil(): DateTimeInterface`, `uniqueId(): string`, `uniqueVia(): Repository`, and `failed(?Throwable $exception): void`.

- [ ] **Step 1: Write failing job tests**

Create `tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php` with `RefreshDatabase` and array cache stores. Add one test for policy and successful targeted invocation:

```php
public function test_it_is_unique_per_title_and_runs_the_forced_targeted_pipeline_on_the_import_queue(): void
{
    $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
    $title = CatalogTitle::factory()->create([
        'source_url' => $url,
        'source_url_hash' => hash('sha256', $url),
    ]);
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'url',
        'status' => 'completed',
        'argument' => $url,
        'force' => true,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
    $pipeline->shouldReceive('run')->once()->withArgs(fn (
        ?string $argument,
        bool $force,
        bool $forever,
        ?int $sleepSeconds,
        bool $discover,
    ): bool => $argument === $url
        && $force
        && ! $forever
        && $sleepSeconds === null
        && ! $discover)->andReturn($run);

    $job = new RefreshSeasonvarCatalogTitle($title->id);
    $job->handle(
        $pipeline,
        app(SeasonvarUrl::class),
        app(SeasonvarImportGroupKey::class),
        app(CatalogTitleRefreshStateStore::class),
    );

    $this->assertSame('redis', $job->connection);
    $this->assertSame('seasonvar-import', $job->queue);
    $this->assertSame('catalog-title-refresh:'.$title->id, $job->uniqueId());
    $this->assertSame([60, 300, 900], $job->backoff());
    $this->assertSame('completed', app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value);
    $this->assertSame($run->id, app(CatalogTitleRefreshStateStore::class)->read($title->id)->importRunId);
}
```

Add a lock-contention test using `withFakeQueueInteractions()`: acquire the exact `SeasonvarImportGroupKey` lock first, call `handle()`, assert `assertReleased(delay: 30)`, assert the pipeline was not called, and assert state remains `queued`.

Add URL and terminal-failure tests:

```php
public function test_it_rejects_a_non_seasonvar_source_url_before_running_the_pipeline(): void
{
    $title = CatalogTitle::factory()->create([
        'source_url' => 'https://example.com/serial-42-Test-1-season.html',
    ]);
    $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
    $pipeline->shouldNotReceive('run');

    $this->expectException(InvalidArgumentException::class);

    (new RefreshSeasonvarCatalogTitle($title->id))->handle(
        $pipeline,
        app(SeasonvarUrl::class),
        app(SeasonvarImportGroupKey::class),
        app(CatalogTitleRefreshStateStore::class),
    );
}

public function test_terminal_failure_updates_only_sanitized_refresh_state(): void
{
    $title = CatalogTitle::factory()->create();
    $job = new RefreshSeasonvarCatalogTitle($title->id);

    $job->failed(new RuntimeException('private remote token'));

    $state = app(CatalogTitleRefreshStateStore::class)->read($title->id);
    $this->assertSame('failed', $state->status?->value);
    $this->assertStringNotContainsString('private remote token', json_encode($state->toArray(), JSON_THROW_ON_ERROR));
}
```

- [ ] **Step 2: Run job tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php
```

Expected: FAIL because the job shell has no `handle()`, `backoff()`, or `failed()` behavior.

- [ ] **Step 3: Implement the complete job lifecycle**

Add these methods to `RefreshSeasonvarCatalogTitle` with explicit imports for `CatalogTitle`, `SeasonvarImportPipeline`, `SeasonvarUrl`, `SeasonvarImportGroupKey`, `CatalogTitleRefreshStateStore`, `Log`, `InvalidArgumentException`, and `Throwable`:

```php
public function handle(
    SeasonvarImportPipeline $pipeline,
    SeasonvarUrl $urls,
    SeasonvarImportGroupKey $groupKeys,
    CatalogTitleRefreshStateStore $states,
): void {
    $catalogTitle = CatalogTitle::query()->findOrFail($this->catalogTitleId);
    $url = $urls->normalize((string) $catalogTitle->source_url);

    if (! $urls->isAllowed($url)) {
        throw new InvalidArgumentException('Ссылка тайтла не принадлежит разрешенному каталогу Seasonvar.');
    }

    $lock = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'))->lock(
        $groupKeys->forUrl($url, $urls->hash($url)),
        $this->timeout + 300,
    );

    if (! $lock->get()) {
        $states->queued($this->catalogTitleId);
        $this->release(30);

        return;
    }

    try {
        $states->running($this->catalogTitleId);
        $run = $pipeline->run(
            argument: $url,
            force: true,
            forever: false,
            sleepSeconds: null,
            discover: false,
        );

        if (! in_array($run->status, ['completed', 'partial'], true)) {
            throw new RuntimeException('Целевое обновление Seasonvar завершилось без успешного состояния.');
        }

        $states->completed($this->catalogTitleId, $run->id);
    } finally {
        $lock->release();
    }
}

/** @return list<int> */
public function backoff(): array
{
    return [60, 300, 900];
}

public function failed(?Throwable $exception): void
{
    app(CatalogTitleRefreshStateStore::class)->failed($this->catalogTitleId);

    Log::error('Фоновое обновление страницы тайтла Seasonvar завершилось ошибкой.', [
        'catalog_title_id' => $this->catalogTitleId,
        'exception' => $exception ? $exception::class : null,
    ]);
}
```

Use only the exception class in logs; do not log its message or source URL.

- [ ] **Step 4: Verify the job and the existing all-seasons importer contract**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php
php artisan test --compact --filter=test_it_parses_requested_page_and_all_detected_seasons_into_one_title tests/Feature/SeasonvarParsePageCommandTest.php
```

Expected: all tests pass. The importer test must show that the targeted URL flow fetches both the requested and detected season URLs, stores them in one title, and reconciles final missing-data flags.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/Jobs/RefreshSeasonvarCatalogTitle.php tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php
git commit -m "feat: refresh Seasonvar titles in the queue"
```

---

### Task 3: Complete Livewire title shell and player refresh

**Files:**
- Create: `app/Livewire/CatalogTitleDetail.php`
- Create by moving existing body: `resources/views/livewire/catalog-title-detail.blade.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php:5-100`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php:22-100`
- Modify: `app/Http/Controllers/CatalogController.php:27-37`
- Replace body: `resources/views/catalog/show.blade.php`
- Modify: `lang/ru/catalog.php:107-163`
- Modify: `lang/en/catalog.php:107-163`
- Test: `tests/Feature/CatalogTitleLiveRefreshTest.php`
- Modify contract audit: `tests/Unit/FrontendAssetContractTest.php`

**Interfaces:**
- Consumes: `CatalogTitleRefreshCoordinator::request()`, `CatalogTitleRefreshStateStore::read()`, `CatalogTitleQuery::visibleTo()`, `CatalogTitlePageBuilder::data(CatalogTitle $catalogTitle, ?User $user): array`, and the existing `CatalogTitlePlayer` selection boundary.
- Produces: `CatalogTitleDetail::mount(int $catalogTitleId): void`, `CatalogTitleDetail::refreshCatalog(): void`, full title-shell rendering, and `CatalogTitlePlayer::refreshCatalogData(int $catalogTitleId): void` listening for `catalog-title-refreshed`.

- [ ] **Step 1: Write failing Livewire acceptance tests**

Create `tests/Feature/CatalogTitleLiveRefreshTest.php` with `RefreshDatabase`, array cache stores, and `Queue::fake()`. Build a published title with a valid Seasonvar source URL. Add:

```php
public function test_the_title_page_queues_refresh_and_polls_the_complete_livewire_shell_every_three_seconds(): void
{
    $title = $this->refreshableTitle(['title' => 'Старое название']);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSeeLivewire('catalog-title-detail')
        ->assertSeeLivewire('catalog-title-player')
        ->assertSee('Старое название')
        ->assertSee('wire:poll.3s.visible="refreshCatalog"', false)
        ->assertSee('Обновляем данные');

    Queue::assertPushed(RefreshSeasonvarCatalogTitle::class, 1);
}

public function test_livewire_poll_reloads_all_title_data_notifies_the_player_and_stops_after_completion(): void
{
    $title = $this->refreshableTitle(['title' => 'Старое название']);
    $component = Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id]);

    $title->update(['title' => 'Новое название', 'description' => 'Новое описание']);

    $component
        ->call('refreshCatalog')
        ->assertSee('Новое название')
        ->assertSee('Новое описание')
        ->assertDispatched('catalog-title-refreshed', catalogTitleId: $title->id);

    app(CatalogTitleRefreshStateStore::class)->completed($title->id, 73);

    $component
        ->call('refreshCatalog')
        ->assertSee('Данные обновлены')
        ->assertDontSee('wire:poll.3s.visible="refreshCatalog"', false);
}

public function test_failed_refresh_keeps_current_catalog_data_and_exposes_no_source_url_or_error(): void
{
    $title = $this->refreshableTitle(['title' => 'Последние сохраненные данные']);
    app(CatalogTitleRefreshStateStore::class)->failed($title->id);

    Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id])
        ->assertSee('Последние сохраненные данные')
        ->assertSee('Не удалось обновить')
        ->assertDontSee((string) $title->source_url)
        ->assertDontSee('wire:poll.3s.visible="refreshCatalog"', false);
}
```

Add a visibility test using an unpublished title and `->assertNotFound()`. Add a player event test that creates one visible season/episode/media, mounts `CatalogTitlePlayer`, creates a second episode/media after mount, dispatches `catalog-title-refreshed` with the matching title id, selects the season, and asserts both episode labels are rendered. Also dispatch the event with a foreign title id and assert no cross-title state change.

- [ ] **Step 2: Run Livewire tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/CatalogTitleLiveRefreshTest.php
```

Expected: FAIL because `CatalogTitleDetail`, its view, polling markup, translated status, and player listener do not exist.

- [ ] **Step 3: Refactor the page builder to accept explicit viewer state**

Change:

```php
public function data(CatalogShowRequest $request, CatalogTitle $catalogTitle): array
```

to:

```php
public function data(CatalogTitle $catalogTitle, ?User $user): array
```

Replace every `$request->user()` in the builder with `$user`. Remove the `CatalogShowRequest` import. Update the controller call to:

```php
$page = $this->titlePage->data($catalogTitle, $request->user());

return view('catalog.show', [
    'title' => $page['title'],
    'seo' => $page['seo'],
]);
```

Keep the canonical historical-slug redirect before building the page.

- [ ] **Step 4: Implement the parent Livewire component**

Create `app/Livewire/CatalogTitleDetail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CatalogTitleDetail extends Component
{
    #[Locked]
    public int $catalogTitleId;

    protected CatalogTitlePageBuilder $pages;
    protected CatalogTitleQuery $titles;
    protected CatalogTitleRefreshCoordinator $refreshes;
    protected CatalogTitleRefreshStateStore $states;

    public function boot(
        CatalogTitlePageBuilder $pages,
        CatalogTitleQuery $titles,
        CatalogTitleRefreshCoordinator $refreshes,
        CatalogTitleRefreshStateStore $states,
    ): void {
        $this->pages = $pages;
        $this->titles = $titles;
        $this->refreshes = $refreshes;
        $this->states = $states;
    }

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $this->refreshes->request($this->title());
    }

    public function refreshCatalog(): void
    {
        $this->dispatch('catalog-title-refreshed', catalogTitleId: $this->catalogTitleId)
            ->to(component: CatalogTitlePlayer::class);
    }

    public function render(): View
    {
        $title = $this->title();
        $refreshState = $this->states->read($this->catalogTitleId);

        return view('livewire.catalog-title-detail', [
            ...$this->pages->data($title, $this->user()),
            'refreshState' => $refreshState,
            'refreshStatus' => $this->refreshStatus($refreshState),
        ]);
    }

    private function title(): CatalogTitle
    {
        return $this->titles->visibleTo($this->user())->findOrFail($this->catalogTitleId);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @return array{label: string, icon: string, tone: string}|null */
    private function refreshStatus(CatalogTitleRefreshState $state): ?array
    {
        return match ($state->status) {
            SeasonvarImportStatus::Queued, SeasonvarImportStatus::Running => [
                'label' => __('catalog.title.refreshing'),
                'icon' => 'fa-solid fa-arrows-rotate fa-spin',
                'tone' => 'active',
            ],
            SeasonvarImportStatus::Completed => [
                'label' => __('catalog.title.refreshed'),
                'icon' => 'fa-solid fa-circle-check',
                'tone' => 'completed',
            ],
            SeasonvarImportStatus::Failed => [
                'label' => __('catalog.title.refresh_failed'),
                'icon' => 'fa-solid fa-triangle-exclamation',
                'tone' => 'failed',
            ],
            default => null,
        };
    }
}
```

Import `CatalogTitleRefreshState` and `SeasonvarImportStatus` in the component.

- [ ] **Step 5: Move the complete page body into the Livewire view**

Use `apply_patch` to move the current `resources/views/catalog/show.blade.php` body into `resources/views/livewire/catalog-title-detail.blade.php`; do not recreate or simplify any existing title markup. Remove only `@extends`, `@section('content')`, and `@endsection`, then wrap the entire existing content in one root:

```blade
<div
    @if ($refreshState->isActive())
        wire:poll.3s.visible="refreshCatalog"
    @endif
    class="space-y-5"
    data-livewire-catalog-title-detail
>
</div>
```

Inside the existing title-hero toolbar, after the catalog-back link, add the exact status block:

```blade
@if ($refreshStatus !== null)
    <span @class([
        'inline-flex min-h-9 items-center gap-2 rounded-control px-3 py-2 text-xs font-bold',
        'bg-sky-50 text-sky-700' => $refreshStatus['tone'] === 'active',
        'bg-emerald-50 text-emerald-700' => $refreshStatus['tone'] === 'completed',
        'bg-rose-50 text-rose-700' => $refreshStatus['tone'] === 'failed',
    ]) data-title-refresh-status>
        <x-ui.icon :name="$refreshStatus['icon']" />
        <span>{{ $refreshStatus['label'] }}</span>
    </span>
@endif
```

Keep the nested player key stable:

```blade
<livewire:catalog-title-player
    :catalog-title-id="$title->id"
    :wire:key="'catalog-title-player-'.$title->id"
/>
```

Replace `resources/views/catalog/show.blade.php` with the small layout shell:

```blade
@extends('layouts.app', ['title' => $seo['title'] ?? $title->display_title, 'seo' => $seo ?? []])

@section('content')
    <livewire:catalog-title-detail :catalog-title-id="$title->id" />
@endsection
```

- [ ] **Step 6: Add the scoped player refresh listener**

Import `Livewire\Attributes\On` and add:

```php
#[On('catalog-title-refreshed')]
public function refreshCatalogData(int $catalogTitleId): void
{
    if ($catalogTitleId !== $this->catalogTitleId) {
        return;
    }

    $this->resolvedTitle = null;
    $this->resolvedEpisode = null;
    $this->resolvedSeasons = null;
}
```

Do not reset the public season, episode, media, variant, quality, or format properties. Existing render-time authorization and normalization decide whether those values remain valid.

- [ ] **Step 7: Add translated status copy and update asset contracts**

Add these keys under `catalog.title` in both locale files:

```php
'refreshing' => 'Обновляем данные',
'refreshed' => 'Данные обновлены',
'refresh_failed' => 'Не удалось обновить',
```

```php
'refreshing' => 'Updating data',
'refreshed' => 'Data updated',
'refresh_failed' => 'Could not update',
```

Update `FrontendAssetContractTest` to assert that `resources/views/livewire/catalog-title-detail.blade.php` contains `wire:poll.3s.visible="refreshCatalog"`, `data-livewire-catalog-title-detail`, and `data-title-refresh-status`, and that the compact `catalog/show.blade.php` mounts `catalog-title-detail`. Preserve all pre-existing assertions.

- [ ] **Step 8: Run focused Livewire/page tests and verify GREEN**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CatalogTitleLiveRefreshTest.php
php artisan test --compact --filter='test_title_page|CatalogTitlePlayer' tests/Feature/CatalogPageTest.php
php artisan test --compact tests/Unit/FrontendAssetContractTest.php
npm run build
```

Expected: all focused tests pass and Vite builds the Tailwind/Blade scan without missing classes or template errors.

- [ ] **Step 9: Commit Task 3**

```bash
git add app/Livewire/CatalogTitleDetail.php app/Livewire/CatalogTitlePlayer.php app/Services/Catalog/CatalogTitlePageBuilder.php app/Http/Controllers/CatalogController.php resources/views/catalog/show.blade.php resources/views/livewire/catalog-title-detail.blade.php lang/ru/catalog.php lang/en/catalog.php tests/Feature/CatalogTitleLiveRefreshTest.php tests/Unit/FrontendAssetContractTest.php
git commit -m "feat: live refresh complete title pages"
```

---

### Task 4: Project documentation and regression coverage

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/testing.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/plans/2026-07-13-livewire-title-background-refresh.md`
- Create: `tests/Unit/TitleBackgroundRefreshDocumentationTest.php`

**Interfaces:**
- Consumes: the tested coordinator, job, Livewire component, and player event contract from Tasks 1-3.
- Produces: project-owned documentation for operations, frontend behavior, testing, and release history.

- [ ] **Step 1: Add exact documentation assertions first**

Create `tests/Unit/TitleBackgroundRefreshDocumentationTest.php` with one exact source-of-truth contract:

```php
<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TitleBackgroundRefreshDocumentationTest extends TestCase
{
    public function test_project_docs_describe_the_title_background_refresh_contract(): void
    {
        $this->assertStringContainsString('15 минут', File::get(base_path('docs/importer.md')));
        $this->assertStringContainsString('wire:poll.3s.visible', File::get(base_path('docs/frontend.md')));
        $this->assertStringContainsString('RefreshSeasonvarCatalogTitle', File::get(base_path('docs/queues.md')));
    }
}
```

- [ ] **Step 2: Run the documentation test and verify RED**

Run:

```bash
php artisan test --compact tests/Unit/TitleBackgroundRefreshDocumentationTest.php
```

Expected: FAIL because the thematic documents do not yet describe the new contracts.

- [ ] **Step 3: Update each source-of-truth document**

Write concise project-specific statements:

- `docs/architecture.md`: the controller retains validation/canonical redirect, while `CatalogTitleDetail` owns the dynamic title shell and delegates playback to a nested component.
- `docs/importer.md`: a visible stale title can request one forced targeted refresh; it parses the canonical page and every known direct season URL and never accepts a browser URL.
- `docs/queues.md`: `RefreshSeasonvarCatalogTitle` is unique per title, uses `seasonvar-import`, retries inside the configured window, and shares the title-group lock with scheduled page jobs.
- `docs/frontend.md`: the complete title shell polls with `wire:poll.3s.visible` only during active work and sends a scoped player refresh event without resetting valid playback selection.
- `docs/views.md`: `catalog.show` is the layout entry and `catalog-title-detail` is the full dynamic presentation boundary.
- `docs/testing.md`: name the coordinator, job, all-seasons HTTP fake, Livewire polling, payload sanitization, and player-selection regression tests.
- `docs/UI_STANDARDS.md`: record the compact Russian refresh status and the rule that polling stops at terminal state.
- `docs/MAINTENANCE_LOG.md`: add a dated operational entry for the implementation.
- `CHANGELOG.md`: add one user-visible entry under the current unreleased section.

Do not edit content inside `project-docs:start` and `project-docs:end` manually.

- [ ] **Step 4: Refresh managed blocks and run documentation tests**

Run:

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check
php artisan test --compact tests/Unit/TitleBackgroundRefreshDocumentationTest.php tests/Unit/ProjectDocumentationRefresherTest.php tests/Unit/FrontendAssetContractTest.php
```

Expected: the refresh command changes only managed documentation blocks, check exits 0, and selected tests pass.

- [ ] **Step 5: Commit Task 4**

```bash
git add CHANGELOG.md docs/architecture.md docs/importer.md docs/queues.md docs/frontend.md docs/views.md docs/testing.md docs/UI_STANDARDS.md docs/MAINTENANCE_LOG.md docs/superpowers/plans/2026-07-13-livewire-title-background-refresh.md tests/Unit/TitleBackgroundRefreshDocumentationTest.php
git commit -m "docs: document live title refreshes"
```

Before committing, inspect `git diff --cached --name-only` and unstage any test file not intentionally changed by this task.

---

### Task 5: Full verification, browser QA, and final Git audit

**Files:**
- Verify all files changed in Tasks 1-4.

**Interfaces:**
- Consumes: the completed feature commits.
- Produces: verified feature behavior on `main`, stopped temporary sessions, and a clean final Git state.

- [ ] **Step 1: Run the complete automated verification on the committed feature**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CatalogTitleBackgroundRefreshTest.php tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php tests/Feature/CatalogTitleLiveRefreshTest.php
php artisan test --compact
npm run build
php artisan project:docs-refresh --check
```

Expected: Pint exits 0, all focused tests pass, the full Laravel suite reports zero failures, Vite exits 0, and documentation check exits 0.

- [ ] **Step 2: Start the application and queue worker for browser QA**

Select a free port deterministically and use the existing queue configuration:

```bash
port="$(for candidate in $(seq 8013 8099); do if ! ss -ltn "sport = :$candidate" | tail -n +2 | grep -q .; then echo "$candidate"; break; fi; done)"
test -n "$port"
php artisan serve --host=127.0.0.1 --port="$port"
php artisan queue:work redis --queue=seasonvar-import --sleep=1 --tries=0 --timeout=900
```

Start the server and worker in separate tracked sessions only if the configured Redis services are available. Never substitute the synchronous queue for the real-background browser check.

Expected: both sessions remain running without startup errors. Record the generated local URL.

- [ ] **Step 3: Run Playwright title-page QA**

Open a published title page and verify at desktop and mobile widths:

- initial title data renders immediately;
- exactly one background job is queued for a stale title;
- `Обновляем данные` is visible without overlapping the toolbar;
- network activity shows three-second Livewire updates only while active and visible;
- changed title metadata, counts, seasons, episodes, and media appear without browser reload;
- active playback does not restart when selection remains valid;
- terminal `Данные обновлены` or `Не удалось обновить` removes polling;
- browser console has no Livewire, Alpine, Plyr, or HLS errors;
- HTML and Livewire responses contain no raw Seasonvar or playback URL.

Capture desktop and mobile screenshots for inspection but do not commit generated QA artifacts.

- [ ] **Step 4: Stop all temporary server/worker sessions**

Send normal interrupt signals and wait for both processes to exit. Do not leave required sessions running at task completion.

- [ ] **Step 5: Verify Git state and feature commit boundary**

Run:

```bash
git status --short --branch
git log -5 --oneline --decorate
git diff --check
```

Expected: feature commits are on `main`, no conflict markers or whitespace errors exist, and `git status --short --branch` contains no modified or untracked files.

---

## Plan Self-Review

- Every design requirement maps to a task: 15-minute freshness and deduplication in Task 1; per-title queue execution, URL validation, retries, and title-group locking in Task 2; complete three-second Livewire refresh and player preservation in Task 3; source-of-truth documentation in Task 4; full/browser verification and final Git audit in Task 5.
- All public inputs are scalar title ids resolved again through the visibility boundary; no client-provided URL reaches the importer.
- Cache values are scalar arrays, compatible with Laravel 13's disabled arbitrary class unserialization.
- Queue `retry_after` remains greater than the 900-second job timeout, and time-bounded retries use `$tries = 0`.
- Type and method names are consistent across tasks: `CatalogTitleRefreshState`, `CatalogTitleRefreshStateStore`, `CatalogTitleRefreshCoordinator`, `RefreshSeasonvarCatalogTitle`, `CatalogTitleDetail`, and `catalog-title-refreshed`.
- No new command, dependency, migration, video download, global sitemap discovery, or unrelated refactor is included.
