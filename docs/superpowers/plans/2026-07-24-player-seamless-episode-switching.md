# Безлимитный master plan развития бесшовного проигрывателя

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сохранять и развивать доступный player-owned контур `Сезон → Серия → Перевод`, в котором ручной и автоматический переход меняет только авторизованный источник внутри существующих `<video>`, Plyr, player session и standard fullscreen element, а новые задачи добавляются без искусственного верхнего лимита только по проверенному evidence.

**Architecture:** `CatalogTitlePlaybackQuery` remains the only ordering and watchability owner. Three sequential Livewire `#[Renderless]` actions expose a bounded episode page, prepare one revalidated transition without mutating visible state, and commit only the transition actually accepted by the browser. `CatalogPlayerTransitionFactory` creates typed allowlisted DTOs containing one same-origin signed source and a fresh progress context. A JavaScript-owned menu and hot-swap state machine live inside the existing keyed `wire:ignore` shell; monotonic client generations discard stale responses while the same `CatalogPlayerSession`, `<video>`, Plyr root, and fullscreen node stay alive. Tasks 1–15 preserve the delivered baseline; Tasks 16+ form one monotonic evidence-driven rolling queue with separate code, delivery, production and platform-evidence states.

**Tech Stack:** PHP 8.5.8, Laravel 13.21.1, Livewire 4.3.3, Laravel Boost 2.4.13, PHPUnit 12.5.31, Node 26.4.0, npm 12.0.1, Plyr 3.8.4, HLS.js 1.6.16, Tailwind CSS 4.3.2, Vite 8.1.4, Playwright 1.61.1.

**Delivery status (24.07.2026):** Tasks 1–15 are locally implemented, verified and recorded through RED → GREEN change sets and commits `1ded102`/`ab6532a`. Typed payloads, bounded query/factory/actions, safe bootstrap, accessible menu, generation-guarded bridge, in-place MP4/HLS transition, immediate auto-next, progress/context rotation, History API, responsive/fullscreen behavior, lazy sibling compatibility, and runtime documentation are complete. Final evidence: focused player PHP `108/108` tests and `1164` assertions; full PHPUnit `1510` tests, `1499` passed, `11` expected skipped, `123588` assertions; Vite `24` modules; Playwright player matrix `15` passed and `12` expected skipped across desktop/mobile/tablet. Remote delivery remains `unresolved` because configured GitHub HTTPS authentication is unavailable; native iOS fullscreen remains `unresolved` without a real device; production activation is not claimed.

**Plan model:** безлимитный rolling roadmap не имеет верхнего номера или срока окончания, но каждая принятая задача конечна, bounded, TDD-проверяема, обратима и не расширяет user/production authority. Неподтверждённые capabilities остаются triggers, а не обещанными implementation tasks.

## Global Constraints

- Work only on the existing `main` branch. Do not create a branch, worktree, or pull-request branch.
- Preserve the pre-existing unstaged `composer.lock` change. Do not stage, modify, restore, or describe it as part of this feature.
- Re-read `AGENTS.md`, `docs/requirements/index.md`, every applicable canonical requirement, the approved design, and this plan before application edits.
- Apply strict RED → GREEN → REFACTOR TDD. Each behavior is tested and observed failing for the intended reason before production code is added.
- Use `#[Renderless]` for the three player data actions. Do not use `#[Json]` or `#[Async]`: installed Livewire 4.3.3 automatically runs `#[Json]` actions asynchronously, which conflicts with ordered player mutations.
- Keep one full keyed `wire:ignore`, one `<video>`, one `CatalogPlayerSession`, one Plyr instance, and at most one active HLS.js instance.
- Never expose an upstream/provider media URL, user ID, entitlement reason, credential, exception, raw Eloquent graph, or administrative state to HTML, Livewire public state, action payloads, history, browser storage, logs, or visible errors.
- Every episode/media ID supplied by the browser is untrusted and must be resolved again through the viewer-aware query, entitlement, source, hierarchy, publication, audience, availability-window, health, premium, region, and legal boundaries.
- Keep the existing signed `playback.source` route, viewer binding, TTL, and `private, no-store` behavior. Do not add a route, controller, API resource, migration, table, index, cache key, queue, scheduler, service worker, dependency, config key, or environment variable.
- Limit an episode-menu response to 24 episodes. Limit prefetch to one next transition at most 60 seconds before the known end. Do not request menu data on `timeupdate`.
- Prefetch must not change Livewire selection, URL, discussion target, Media Session metadata, or visible current labels. Only a transition accepted by the current browser generation may be committed.
- Manual episode selection, manual translation selection, and automatic next start at `0`. Only technical source fallback within the same episode preserves the prior position.
- Preserve volume, muted state, speed, autoplay preference, keyboard preference, subtitle preference, data-saver behavior, and preferred variant/quality/format across an episode transition. A temporary source fallback must not overwrite the preferred profile.
- Preserve `/titles/{catalogTitle:slug}`, localized aliases, query parameters `season`, `episode`, `media`, `variant`, `quality`, `format`, `marker`, Back/Forward semantics, discussion targeting, Media Session actions, SSR links, and no-JavaScript navigation.
- Keep regular and special release lanes separate. The last regular episode of one regular season may continue to the first watchable regular episode of the next regular season.
- The standard Fullscreen API contract is guaranteed by DOM identity. Native iOS video fullscreen remains an explicit real-device evidence gate; do not create fake CSS fullscreen or claim unverified WebKit behavior.
- All visible interface copy belongs in `lang/ru/catalog.php` and `lang/en/catalog.php`, with identical keys/placeholders. Translation/studio identity values remain source identity and are not translated.
- Use DOM construction and `textContent` for server data. Do not use inline business JavaScript, `innerHTML` with action data, inline CSS, or a second player component.
- Update `README.md` only when the visitor-facing runtime is actually delivered. Planning-only commits must not add a fictional visitor update.
- Before completion run Pint after PHP changes, focused tests, the full PHPUnit suite, Vite build, Playwright player matrix, documentation checks, legacy scans, and `git diff --check`.
- Commit authorized completed changes only on `main`, then attempt the configured push. Report authentication or remote failures as `unresolved`.

---

## Exact File and Contract Map

### New files

- `app/DTOs/PlayerEpisodePageData.php`
  - Produces one bounded, browser-safe season page.
  - Public method: `toArray(): array`.
- `app/DTOs/PlaybackTransitionData.php`
  - Produces one browser-safe playback transition or one localized unavailable result.
  - Public methods:
    - `ready(string $message, string $contextKey, array $source, array $selection, array $labels, array $translations, array $navigation, array $mediaSession, array $progress, ?string $noticeCode): self`
    - `unavailable(string $message): self`
    - `isReady(): bool`
    - `toArray(): array`
- `app/Services/Catalog/CatalogPlayerTransitionFactory.php`
  - Orchestrates existing query, source resolver, progress-session, media metadata, and localization boundaries.
  - Public methods:
    - `episodePage(CatalogTitle $title, ?User $user, Season $season, int $page, ?int $currentEpisodeId): PlayerEpisodePageData`
    - `prepare(CatalogTitle $title, ?User $user, Episode $episode, ?int $requestedMediaId, PlaybackPreferencesData $preferences): PlaybackTransitionData`
- `tests/Unit/PlayerEpisodePageDataTest.php`
- `tests/Unit/PlaybackTransitionDataTest.php`
- `tests/Feature/CatalogPlayerTransitionFactoryTest.php`
- `resources/js/player-menu.js`
  - Exports `CatalogPlayerMenu`.
  - Owns only accessible menu DOM, menu state, pagination controls, focus, and responsive level navigation.

### Modified application files

- `app/Livewire/CatalogTitlePlayer.php`
  - Inject `CatalogPlayerTransitionFactory`.
  - Add three ordered renderless actions:
    - `playerEpisodePage(mixed $seasonId, mixed $page = 1): array`
    - `preparePlayerTransition(mixed $episodeId, mixed $mediaId = null): array`
    - `commitPlayerTransition(mixed $episodeId, mixed $mediaId): array`
  - Keep existing rendered `selectSeason`, `selectEpisode`, and `selectMedia` for SSR/no-JavaScript fallback.
- `app/Services/Catalog/CatalogTitlePlaybackQuery.php`
  - Extract the shared season episode query.
  - Add bounded pagination and direct adjacent navigation without loading every episode.
- `app/View/ViewData/CatalogPlayerCopy.php`
  - Add allowlisted `menu` copy and transition runtime states.
- `app/View/ViewModels/CatalogShowViewModel.php`
  - Reuse current translation/profile presentation where needed; no database queries.
- `resources/views/livewire/catalog-title-player.blade.php`
  - Add safe bootstrap data and action markers.
  - Keep fallback links outside the ignored shell.
  - Remove delivered countdown controls only after JavaScript and browser tests are GREEN.
- `resources/js/player.js`
  - Keep one `CatalogPlayerSession`.
  - Add transition preparation, in-place source application, immediate auto-next, progress-context rotation, retry, and menu integration.
- `resources/js/player-navigation.js`
  - Bridge the three renderless actions.
  - Commit only accepted transitions and update URL/history/discussion-compatible Livewire state without rendering.
- `resources/css/app.css`
  - Add responsive menu/dialog/fullscreen/safe-area/reduced-motion styles.
- `lang/ru/catalog.php`
- `lang/en/catalog.php`

### Modified tests

- `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- `tests/Feature/CatalogPageTest.php`
- `tests/Unit/CatalogPlayerCopyTest.php`
- `tests/Unit/LivewireWireIgnoreContractTest.php`
- `tests/browser/player-lifecycle.spec.js`
- `tests/browser/support/player-media-fixtures.js` only if a second episode/translation fixture cannot be expressed with existing fixture routes.

### Documentation and delivery files

- `docs/audits/video-playback-report.md`
- `docs/architecture.md`
- `docs/frontend.md`
- `docs/UI_STANDARDS.md` only for a genuinely new reusable player-menu rule.
- `docs/superpowers/specs/2026-07-24-player-seamless-episode-switching-design.md`
- `docs/plans/current-task-plan.md`
- `README.md`
- `CHANGELOG.md`

### Contracts that must remain compatible

- Existing route names, localized route aliases, route model binding, canonical URL, and SEO output.
- Existing signed playback responder and source grant validation.
- Existing `CatalogTitlePlaybackQuery` visibility and release ordering.
- Existing account preference storage `seasonvar.account-preferences.v1`.
- Existing authenticated progress token rules and anonymous bounded progress storage.
- Existing rendered season/episode/media links and their `href` values.
- Existing `catalog-progress`, `catalog-source-fallback`, `catalog-source-refresh`, `catalog-autoplay-preference`, `catalog-player-preferences`, `catalog-restart-progress`, and `catalog-save-playback-marker` event contracts.
- Existing discussion event `discussion-target-selected`.
- Existing source retry/fallback, authorization refresh, restart, PiP, keyboard, captions, Media Session, pagehide, Livewire navigation, and cleanup behavior.

---

### Task 1: Freeze typed browser payload shapes

**Files:**

- Create: `tests/Unit/PlayerEpisodePageDataTest.php`
- Create: `tests/Unit/PlaybackTransitionDataTest.php`
- Create: `app/DTOs/PlayerEpisodePageData.php`
- Create: `app/DTOs/PlaybackTransitionData.php`

**Interfaces:**

- `PlayerEpisodePageData::toArray()` returns exactly:

```php
array{
    status: 'ready',
    season: array{id: int, label: string},
    episodes: list<array{
        id: int,
        label: string,
        title: string|null,
        mediaCount: int,
        current: bool
    }>,
    pagination: array{
        page: int,
        lastPage: int,
        previousPage: int|null,
        nextPage: int|null
    }
}
```

- `PlaybackTransitionData::toArray()` returns exactly one of:

```php
array{
    status: 'ready',
    message: string,
    contextKey: string,
    source: array{
        url: string,
        mimeType: string|null,
        format: string|null,
        expiresAt: string|null
    },
    selection: array{
        seasonId: int,
        episodeId: int,
        mediaId: int,
        variant: string,
        quality: string,
        format: string,
        query: array<string, string>
    },
    labels: array{
        title: string,
        season: string,
        episode: string,
        media: string
    },
    translations: list<array{
        mediaId: int,
        label: string,
        detail: string|null,
        active: bool
    }>,
    navigation: array{
        previous: array{id: int, label: string}|null,
        next: array{id: int, label: string}|null
    },
    mediaSession: array{
        title: string,
        artist: string,
        album: string,
        artwork: string|null
    },
    progress: array{
        enabled: bool,
        token: string,
        sequence: 0
    },
    noticeCode: string|null
}
```

or:

```php
array{
    status: 'unavailable',
    message: string
}
```

- DTOs accept only scalars and already-normalized arrays; they never accept Eloquent models.

- [x] **Step 1: Write the RED episode-page DTO test**

Create `tests/Unit/PlayerEpisodePageDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\PlayerEpisodePageData;
use PHPUnit\Framework\TestCase;

final class PlayerEpisodePageDataTest extends TestCase
{
    public function test_it_serializes_only_the_bounded_allowlisted_page_shape(): void
    {
        $data = new PlayerEpisodePageData(
            seasonId: 11,
            seasonLabel: '2 сезон',
            episodes: [[
                'id' => 101,
                'label' => '3 серия',
                'title' => 'Возвращение',
                'mediaCount' => 2,
                'current' => true,
            ]],
            page: 2,
            lastPage: 4,
        );

        self::assertSame([
            'status' => 'ready',
            'season' => ['id' => 11, 'label' => '2 сезон'],
            'episodes' => [[
                'id' => 101,
                'label' => '3 серия',
                'title' => 'Возвращение',
                'mediaCount' => 2,
                'current' => true,
            ]],
            'pagination' => [
                'page' => 2,
                'lastPage' => 4,
                'previousPage' => 1,
                'nextPage' => 3,
            ],
        ], $data->toArray());
    }
}
```

- [x] **Step 2: Write the RED transition DTO tests**

Create `tests/Unit/PlaybackTransitionDataTest.php` with one ready-payload test and one unavailable-payload test. The ready test must instantiate every constructor field and use `assertSame()` against the exact array above. The unavailable test must assert:

```php
$data = PlaybackTransitionData::unavailable('Серия недоступна');

self::assertFalse($data->isReady());
self::assertSame([
    'status' => 'unavailable',
    'message' => 'Серия недоступна',
], $data->toArray());
```

The ready test must additionally assert:

```php
self::assertTrue($data->isReady());
self::assertStringStartsWith('/playback/', $data->toArray()['source']['url']);
self::assertArrayNotHasKey('providerUrl', $data->toArray()['source']);
self::assertArrayNotHasKey('userId', $data->toArray()['progress']);
```

- [x] **Step 3: Run both tests and verify RED**

Run:

```bash
php artisan test tests/Unit/PlayerEpisodePageDataTest.php tests/Unit/PlaybackTransitionDataTest.php
```

Expected: FAIL because both DTO classes do not exist.

- [x] **Step 4: Implement immutable DTOs**

Implement both classes as `final readonly class` with fully typed constructor properties, PHPDoc array shapes, and explicit `toArray()` methods. Clamp neither values nor labels in the DTO; validation and normalization belong to the factory/action boundary.

`PlayerEpisodePageData` derives `previousPage` and `nextPage` only from validated `page`/`lastPage`. `PlaybackTransitionData::ready()` requires all ready fields, while `unavailable()` creates the two-key failure payload. Do not serialize null ready fields into an unavailable payload.

- [x] **Step 5: Verify GREEN and formatting**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/PlayerEpisodePageDataTest.php tests/Unit/PlaybackTransitionDataTest.php
```

Expected: both tests pass; Pint reports no remaining style change.

- [x] **Step 6: Commit the isolated typed contract**

Run:

```bash
git status --short --branch
git add app/DTOs/PlayerEpisodePageData.php app/DTOs/PlaybackTransitionData.php tests/Unit/PlayerEpisodePageDataTest.php tests/Unit/PlaybackTransitionDataTest.php
git diff --cached --check
git commit -m "test: define player transition payloads"
```

Do not stage `composer.lock`.

---


### Task 2: Add bounded season pagination and direct release-lane navigation

**Files:**

- Modify: `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php:262-700`

**Interfaces:**

- Add:

```php
/** @return LengthAwarePaginator<int, Episode> */
public function episodesForSeasonPage(
    CatalogTitle $catalogTitle,
    Season $season,
    ?User $user,
    int $page,
    int $perPage = 24,
): LengthAwarePaginator
```

- Add:

```php
public function navigationForEpisode(
    CatalogTitle $catalogTitle,
    ?User $user,
    Episode $episode,
): CatalogEpisodeNavigation
```

- Extract a private `episodesForSeasonQuery(CatalogTitle $catalogTitle, Season $season, ?User $user, bool $withMedia): Builder` used by both existing `episodesForSeason()` and the new paginator.
- Preserve existing `episodesForSeason()` and `episodeNavigation()` public behavior for rendered fallback consumers.

- [x] **Step 1: Add the RED bounded-page test**

Append a test that creates 27 visible regular episodes with one published playable media each, plus:

- one hidden episode;
- one expired episode;
- one episode without a playable source;
- one episode in another season;
- one episode requiring authentication.

For a guest, call:

```php
$firstPage = $playback->episodesForSeasonPage($title, $season, null, 1);
$secondPage = $playback->episodesForSeasonPage($title, $season, null, 2);
```

Assert:

```php
self::assertCount(24, $firstPage->items());
self::assertCount(3, $secondPage->items());
self::assertSame(27, $firstPage->total());
self::assertSame(2, $firstPage->lastPage());
self::assertSame(1, $firstPage->currentPage());
self::assertSame(2, $secondPage->currentPage());
self::assertNotContains($hiddenEpisode->id, $firstPage->getCollection()->modelKeys());
self::assertNotContains($foreignEpisode->id, $secondPage->getCollection()->modelKeys());
```

Also assert every item has `available_media_count` and that no `licensedMedia` relation was eager loaded.

- [x] **Step 2: Add the RED invalid hierarchy and page-bound test**

Call `episodesForSeasonPage()` with a season belonging to another title and assert an empty paginator. Call it with `page` values `0`, `-1`, and `1000001`; assert that the service returns an empty paginator without issuing an item query. The query service itself must never issue an unbounded offset.

Use `DB::listen()` or query log to assert the page response performs a bounded count plus bounded item query, not one query per episode.

- [x] **Step 3: Add RED direct-navigation tests**

Add a test with:

- two regular seasons;
- regular episodes at both season boundaries;
- special episodes in the same regular seasons;
- one separate special season.

Assert:

```php
$navigation = $playback->navigationForEpisode($title, null, $lastRegularInSeasonOne);

self::assertSame($previousRegular->id, $navigation->previous?->id);
self::assertSame($firstRegularInSeasonTwo->id, $navigation->next?->id);
```

Then assert that a special episode points only to the next special episode in the compatible special lane and never to a regular episode or an episode in an incompatible season-kind lane. Assert both values are null for the final accessible episode.

- [x] **Step 4: Run focused query tests and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
```

Expected: existing tests pass and new tests fail because the two public methods do not exist.

- [x] **Step 5: Extract the shared season query**

Move the existing `Episode::query()` construction from `episodesForSeason()` into:

```php
/** @return Builder<Episode> */
private function episodesForSeasonQuery(
    CatalogTitle $catalogTitle,
    Season $season,
    ?User $user,
    bool $withMedia,
): Builder
```

Before constructing the query, fail closed when `(int) $season->catalog_title_id !== $catalogTitle->id`.

Keep the current select list, `available_media_count`, relation hierarchy constraints, viewer scopes, release ordering, and optional media eager load unchanged. `episodesForSeason()` calls `->get()`. `episodesForSeasonPage()` validates `1 <= $perPage <= 24` and `1 <= $page <= 10000`, uses Laravel `paginate(perPage: $perPage, page: $page)`, and returns an empty length-aware paginator for invalid hierarchy/page input.

- [x] **Step 6: Implement direct adjacent navigation**

`navigationForEpisode()` must:

1. resolve the episode again through `watchableEpisode($catalogTitle, $user, $episode->id)`;
2. return an empty `CatalogEpisodeNavigation` if it is no longer watchable;
3. call the existing tuple-ordered `adjacentEpisode($catalogTitle, $user, $current, false)` and `adjacentEpisode($catalogTitle, $user, $current, true)`;
4. preserve both `season.kind` and `episode.kind` lane filters;
5. return only minimal watchable episode models needed for labels/IDs.

Do not load the full active season merely to find adjacent episodes.

- [x] **Step 7: Verify GREEN, query bounds, and existing compatibility**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
php artisan test --filter=test_catalog_title_player_navigates_only_accessible_episodes_inside_the_current_release_lane
```

Expected: all query tests and the existing rendered-navigation feature test pass.

- [x] **Step 8: Commit the query boundary**

Run:

```bash
git status --short --branch
git add app/Services/Catalog/CatalogTitlePlaybackQuery.php tests/Feature/CatalogTitlePlaybackQueryTest.php
git diff --cached --check
git commit -m "feat: add bounded player episode queries"
```

Do not stage `composer.lock`.

### Task 3: Build one server-authorized playback transition

**Files:**

- Create: `tests/Feature/CatalogPlayerTransitionFactoryTest.php`
- Create: `app/Services/Catalog/CatalogPlayerTransitionFactory.php`
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`

**Interfaces:**

```php
final readonly class CatalogPlayerTransitionFactory
{
    public function episodePage(
        CatalogTitle $title,
        ?User $user,
        Season $season,
        int $page,
        ?int $currentEpisodeId,
    ): PlayerEpisodePageData;

    public function prepare(
        CatalogTitle $title,
        ?User $user,
        Episode $episode,
        ?int $requestedMediaId,
        PlaybackPreferencesData $preferences,
    ): PlaybackTransitionData;
}
```

- The factory consumes existing services:
  - `CatalogTitlePlaybackQuery`
  - `CatalogPlaybackSourceResolver`
  - `CatalogPlaybackProgressSession`
  - `ExternalMediaMetadata`
- The factory does not mutate `CatalogTitlePlayer`, account preferences, progress rows, URLs, or discussion state.
- A call produces at most one signed source grant.

- [x] **Step 1: Create the RED episode-page factory test**

Create `tests/Feature/CatalogPlayerTransitionFactoryTest.php` with `RefreshDatabase`.

Build a title, season, two public playable episodes, one hidden episode, and one authenticated-only episode. Resolve:

```php
$page = app(CatalogPlayerTransitionFactory::class)->episodePage(
    $title,
    null,
    $season,
    1,
    $secondEpisode->id,
)->toArray();
```

Assert the exact top-level keys and bounded contents:

```php
self::assertSame(['status', 'season', 'episodes', 'pagination'], array_keys($page));
self::assertSame($season->id, $page['season']['id']);
self::assertSame([$firstEpisode->id, $secondEpisode->id], array_column($page['episodes'], 'id'));
self::assertFalse($page['episodes'][0]['current']);
self::assertTrue($page['episodes'][1]['current']);
self::assertNotContains($hiddenEpisode->id, array_column($page['episodes'], 'id'));
self::assertNotContains($authenticatedEpisode->id, array_column($page['episodes'], 'id'));
self::assertLessThanOrEqual(24, count($page['episodes']));
```

Assert there is no `source`, `playback_url`, `path`, `user_id`, or `catalogTitle` key anywhere in `json_encode($page, JSON_THROW_ON_ERROR)`.

- [x] **Step 2: Create the RED ready-transition security test**

Use `Http::preventStrayRequests()`. Create a verified user, public title/season/episode, and a playable media row whose upstream `playback_url` is `https://data00-cdn.11cdn.org/private-origin.m3u8`.

Prepare:

```php
$transition = app(CatalogPlayerTransitionFactory::class)->prepare(
    $title,
    $user,
    $episode,
    $media->id,
    new PlaybackPreferencesData,
)->toArray();
```

Assert:

```php
self::assertSame('ready', $transition['status']);
self::assertSame($episode->id, $transition['selection']['episodeId']);
self::assertSame($media->id, $transition['selection']['mediaId']);
self::assertSame(0, $transition['progress']['sequence']);
self::assertTrue($transition['progress']['enabled']);
self::assertNotSame('', $transition['progress']['token']);
self::assertTrue(URL::hasValidSignature(
    Request::create($transition['source']['url']),
));
self::assertStringNotContainsString('11cdn.org', json_encode($transition, JSON_THROW_ON_ERROR));
self::assertStringNotContainsString('private-origin', json_encode($transition, JSON_THROW_ON_ERROR));
self::assertStringNotContainsString((string) $user->id, $transition['contextKey']);
```

Also issue the signed URL as the same user and assert the existing playback responder remains `private, no-store`. Issue it as another user and assert the current viewer-binding rejection remains unchanged.

- [x] **Step 3: Add RED hierarchy and access denial cases**

Use a data provider or separate assertions for:

- episode from another title;
- requested media from another episode;
- hidden episode;
- future episode;
- expired episode;
- guest requesting authenticated media;
- unavailable/failed media;
- missing source;
- inaccessible season;
- premium/region/legal denial using the existing project fixture pattern.

For every case assert:

```php
self::assertSame([
    'status' => 'unavailable',
    'message' => __('catalog.player.transition_unavailable'),
], $result->toArray());
```

Do not assert a private entitlement reason.

- [x] **Step 4: Add RED preferred-translation and fallback tests**

Create two media variants for episode one and only one variant for episode two:

- preferred `variant_key=voiceover-studio-a`;
- fallback `variant_key=voiceover-studio-b`.

Pass preferences selecting studio A.

Assert episode one selects studio A. Assert episode two selects studio B, returns `noticeCode=preferred_translation_unavailable`, and leaves the input `PlaybackPreferencesData` unchanged. Query account settings again and assert no write occurred.

Create a second test with explicit `$requestedMediaId` for studio B and assert the explicit, authorized media wins over the preference only for this transition.

- [x] **Step 5: Add RED navigation, query, labels, and progress-rotation tests**

Prepare transitions for consecutive episodes across a season boundary. Assert:

- `navigation.next.id` is the first regular episode of the next regular season;
- special episodes never point to regular episodes;
- final episode has `navigation.next === null`;
- `selection.query` contains only non-empty `season`, `episode`, `media`, `variant`, `quality`, and `format`;
- `marker` is omitted for a new episode;
- labels are localized for the active locale;
- media translation/studio identity text is preserved;
- authenticated transitions for episode A and episode B have distinct `contextKey` and progress token values;
- guest transition has `progress.enabled=false` and `progress.token=''`.

- [x] **Step 6: Run factory tests and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogPlayerTransitionFactoryTest.php
```

Expected: FAIL because the factory does not exist.

- [x] **Step 7: Implement `episodePage()`**

The method must:

1. call `episodesForSeasonPage($title, $season, $user, $page, perPage: 24)`;
2. map only ID, localized episode label, optional title, `available_media_count`, and current flag;
3. use the existing season and episode display semantics;
4. create `PlayerEpisodePageData`;
5. perform no source resolution and no preference/progress write.

Do not expose a Laravel paginator object directly.

- [x] **Step 8: Implement `prepare()` with one source resolution**

The method must execute this order:

1. re-resolve `$episode` with `watchableEpisode($title, $user, $episode->id)`;
2. reject a requested media unless `findAvailableMedia()` returns it and both `catalog_title_id` and `episode_id` match;
3. call `CatalogPlaybackSourceResolver::resolve()` exactly once;
4. resolve the selected media from the returned `mediaId`;
5. load only that episode's available media for translation options;
6. compute direct previous/next with `navigationForEpisode()`;
7. issue a progress token only for a verified authenticated viewer under the existing rule;
8. create a random opaque `contextKey` with `Str::uuid()->toString()`;
9. build a relative same-page query map rather than a route URL, preserving the active localized path in JavaScript;
10. return `PlaybackTransitionData::ready()`.

If any required model/source is unavailable, return `PlaybackTransitionData::unavailable(__('catalog.player.transition_unavailable'))`.

Add exact RU/EN server messages `catalog.player.transition_unavailable` and `catalog.player.transition_limited` before the factory/action tests become GREEN. These messages remain generic and never expose an entitlement or provider failure reason.

- [x] **Step 9: Reuse media-profile presentation**

Extract only the smallest pure label/profile helpers needed by both `CatalogShowViewModel` and the transition factory. If direct extraction would change unrelated view output, keep `CatalogShowViewModel` unchanged and add equivalent private factory helpers covered by exact RU/EN tests.

The transition `translations` list must:

- contain only authorized media from the selected episode;
- be bounded by the existing media query and hard-limited to 24 entries;
- sort active first, then localized/display label, then media ID;
- use `textContent`-safe scalar values;
- identify the exact active `mediaId`;
- include quality/format as `detail`, not as translated source identity.

Do not add a public model method solely for UI copy.

- [x] **Step 10: Verify GREEN and no hidden writes**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogPlayerTransitionFactoryTest.php
php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
php artisan test --filter=CatalogPlaybackSource
```

Expected: focused tests pass, no upstream request escapes, and source resolver compatibility tests remain GREEN.

- [x] **Step 11: Commit the transition factory**

Run:

```bash
git status --short --branch
git add app/Services/Catalog/CatalogPlayerTransitionFactory.php app/Services/Catalog/CatalogTitlePlaybackQuery.php app/View/ViewModels/CatalogShowViewModel.php lang/ru/catalog.php lang/en/catalog.php tests/Feature/CatalogPlayerTransitionFactoryTest.php
git diff --cached --check
git commit -m "feat: prepare authorized player transitions"
```

Stage `CatalogShowViewModel.php` only if it actually changed. Do not stage `composer.lock`.

---


### Task 4: Expose ordered renderless Livewire actions

**Files:**

- Modify: `tests/Feature/CatalogPageTest.php:2280-2920`
- Modify: `app/Livewire/CatalogTitlePlayer.php:47-1080`

**Interfaces:**

```php
#[Renderless]
public function playerEpisodePage(mixed $seasonId, mixed $page = 1): array;

#[Renderless]
public function preparePlayerTransition(mixed $episodeId, mixed $mediaId = null): array;

#[Renderless]
public function commitPlayerTransition(mixed $episodeId, mixed $mediaId): array;
```

- `preparePlayerTransition()` is read-only from the perspective of Livewire selection. This is mandatory because near-end prefetch must not change the currently playing episode.
- `commitPlayerTransition()` changes only the canonical URL-backed selection after revalidation and returns the canonical query map.

- [x] **Step 1: Add the RED menu-action test**

Create a title with 25 playable episodes and call:

```php
$component = Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $title->id])
    ->call('playerEpisodePage', $season->id, 2)
    ->assertReturned(function (array $payload) use ($episodeTwentyFive): bool {
        self::assertSame('ready', $payload['status']);
        self::assertSame([$episodeTwentyFive->id], array_column($payload['episodes'], 'id'));

        return true;
    });
```

Assert the returned page is `ready`, contains one episode on page two, and the component's existing `season`, `episode`, `media`, and `authorizationVersion` properties did not change.

Call with arrays, zero, negative, oversized numeric strings, a foreign season, and page `1000001`. Assert the returned result is a localized unavailable or empty bounded result, no exception text is returned, and selection is unchanged.

- [x] **Step 2: Add the RED prepare-without-mutation test**

Set the component to episode one, then call:

```php
$component
    ->call('preparePlayerTransition', $episodeTwo->id, null)
    ->assertReturned(function (array $payload) use ($episodeTwo): bool {
        self::assertSame('ready', $payload['status']);
        self::assertSame($episodeTwo->id, $payload['selection']['episodeId']);

        return true;
    });
```

Assert:

```php
$component
    ->assertSet('episode', (string) $episodeOne->id)
    ->assertSet('media', (string) $mediaOne->id)
    ->assertSet('season', (string) $seasonOne->id);
```

Assert `discussion-target-selected` was not dispatched by preparation.

- [x] **Step 3: Add the RED commit test**

Call:

```php
$component
    ->call('commitPlayerTransition', $episodeTwo->id, $mediaTwo->id)
    ->assertReturned(function (array $payload) use ($episodeTwo, $mediaTwo): bool {
        self::assertSame('ready', $payload['status']);
        self::assertSame((string) $episodeTwo->id, $payload['query']['episode']);
        self::assertSame((string) $mediaTwo->id, $payload['query']['media']);

        return true;
    });
```

Assert:

- `season`, `episode`, and `media` are the canonical string IDs;
- variant/quality/format match the selected media profile;
- `marker` becomes empty for the new episode;
- `failedMediaIds` becomes empty for the new episode;
- `discussion-target-selected` is dispatched with the new title/season/episode IDs;
- the returned query map exactly matches the component state;
- no render-side player shell replacement is required for the action result.

- [x] **Step 4: Add RED invalid commit and rate-limit tests**

Assert a foreign/hidden/unavailable episode or mismatched media:

- returns `['status' => 'unavailable', 'message' => localized copy]`;
- leaves every selection property unchanged;
- does not dispatch discussion target;
- does not disclose the rejection reason.

Exercise the existing `attemptPlaybackAction()` pattern with distinct keys:

- `player-menu`: 30 attempts per minute;
- `player-transition`: 12 attempts per minute;
- `player-transition-commit`: 20 attempts per minute.

The test after the limit must fail closed with localized `transition_limited` copy and leave state unchanged.

- [x] **Step 5: Run focused Livewire tests and verify RED**

Run:

```bash
php artisan test --filter=CatalogTitlePlayer
```

Expected: new methods are missing or return no typed payload; existing player tests remain GREEN.

- [x] **Step 6: Inject the factory and implement normalization**

Add `CatalogPlayerTransitionFactory` to `boot()`. Use existing `positiveId()`, `nonNegativeInteger()`, viewer resolution, `attemptPlaybackAction()`, and error/reset conventions.

For `playerEpisodePage()`:

1. normalize season/page;
2. rate limit;
3. find the season only in `seasonSummaries($title, $user)`;
4. call factory `episodePage()`;
5. return its array.

For `preparePlayerTransition()`:

1. normalize episode and optional media;
2. rate limit;
3. re-resolve watchable episode;
4. call factory `prepare()`;
5. return its array without changing public selection.

- [x] **Step 7: Implement commit-after-acceptance**

`commitPlayerTransition()` must:

1. normalize both IDs;
2. rate limit independently;
3. re-resolve episode and media with viewer-aware queries;
4. verify complete title/season/episode/media hierarchy;
5. only after all checks pass, assign `season`, `episode`, `media`;
6. clear `marker`, `failedMediaIds`, and stale resolved episode state;
7. call `syncMediaProfile($media)`;
8. dispatch the existing discussion target;
9. return:

```php
[
    'status' => 'ready',
    'query' => [
        'season' => (string) $episode->season_id,
        'episode' => (string) $episode->id,
        'media' => (string) $media->id,
        'variant' => $profile['variant'],
        'quality' => $profile['quality'],
        'format' => $profile['format'],
    ],
]
```

Filter empty profile values from `query`. Do not call `$refresh()` and do not increment `authorizationVersion`.

- [x] **Step 8: Verify GREEN and renderless compatibility**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogTitlePlayer
php artisan test tests/Unit/LivewireWireIgnoreContractTest.php
```

Expected: all focused tests pass and the ignored-shell contract still reports exactly one full `wire:ignore`.

- [x] **Step 9: Commit the Livewire boundary**

Run:

```bash
git status --short --branch
git add app/Livewire/CatalogTitlePlayer.php tests/Feature/CatalogPageTest.php
git diff --cached --check
git commit -m "feat: expose renderless player transitions"
```

Do not stage `composer.lock`.

---


### Task 5: Add exact RU/EN player-menu copy

**Files:**

- Modify: `tests/Unit/CatalogPlayerCopyTest.php`
- Modify: `app/View/ViewData/CatalogPlayerCopy.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`

**Interfaces:**

- `CatalogPlayerCopy::current()` returns:

```php
array{
    runtime: array<string, string>,
    controls: array<string, string>,
    menu: array<string, string>
}
```

- Add runtime keys:
  - `loadingTransition`
  - `transitionUnavailable`
  - `transitionLimited`
  - `preferredTranslationUnavailable`
  - `playRequired`
- Preserve the existing `finalEpisode` runtime key with its current semantics.
- Add menu keys:
  - `open`
  - `close`
  - `title`
  - `seasons`
  - `episodes`
  - `translations`
  - `back`
  - `previousPage`
  - `nextPage`
  - `page`
  - `seasonEmpty`
  - `loading`
  - `retry`

- [x] **Step 1: Update the exact-key test first**

Change `CatalogPlayerCopyTest` to assert all three exact groups. Keep `array_keys(Arr::dot($ru)) === array_keys(Arr::dot($en))`, non-empty values, and locale inequality for representative strings.

Add placeholder parity:

```php
$placeholderPattern = '/:[A-Za-z_][A-Za-z0-9_]*/';

foreach (array_keys(Arr::dot($payloads['ru'])) as $key) {
    preg_match_all($placeholderPattern, (string) data_get($payloads['ru'], $key), $ruMatches);
    preg_match_all($placeholderPattern, (string) data_get($payloads['en'], $key), $enMatches);
    self::assertSame($ruMatches[0], $enMatches[0], $key);
}
```

- [x] **Step 2: Add translation-file assertions**

Assert Russian visible values include `Серии`, `Сезоны`, `Переводы`, `Назад`, `Предыдущая страница`, and `Следующая страница`. Assert English values are genuine English copy and not Russian duplicates.

Change the shortcut help from only `Shift+P / Shift+N` to include `Shift+E` with a localized explanation.

- [x] **Step 3: Run the copy test and verify RED**

Run:

```bash
php artisan test tests/Unit/CatalogPlayerCopyTest.php
```

Expected: FAIL because `menu` and the new runtime keys are absent.

- [x] **Step 4: Add translations and allowlist them**

Add semantic keys under `catalog.player.runtime`, `catalog.player.menu`, and the existing shortcuts section in both locales. Update `CatalogPlayerCopy` explicitly; do not serialize an entire translation subtree.

Keep existing countdown strings until Task 10 has delivered immediate auto-next and a repository scan proves they have no consumer.

- [x] **Step 5: Verify GREEN and translation parity**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/CatalogPlayerCopyTest.php
php artisan test --filter=Translation
```

Expected: copy and broader translation tests pass.

- [x] **Step 6: Commit the copy contract**

Run:

```bash
git status --short --branch
git add app/View/ViewData/CatalogPlayerCopy.php lang/ru/catalog.php lang/en/catalog.php tests/Unit/CatalogPlayerCopyTest.php
git diff --cached --check
git commit -m "feat: localize player episode menu"
```

Do not stage `composer.lock`.

---

---


### Task 6: Add safe player-menu bootstrap without changing fallback navigation

**Files:**

- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Unit/LivewireWireIgnoreContractTest.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php:532-747`
- Modify: `resources/views/livewire/catalog-title-player.blade.php:58-630`

**Interfaces:**

- The existing ignored shell receives one escaped `data-player-menu-bootstrap` JSON value with:

```php
array{
    seasons: list<array{id: int, label: string, episodeCount: int, current: bool}>,
    current: array{
        seasonId: int|null,
        episodeId: int|null,
        mediaId: int|null
    },
    translations: list<array{
        mediaId: int,
        label: string,
        detail: string|null,
        active: bool
    }>
}
```

- Initial bootstrap contains no source URL, progress token, user identity, raw model, or more than the bounded season summaries and current episode's authorized translation options.
- Existing season/episode/media anchors keep their current `href` and Livewire fallback methods.

- [x] **Step 1: Add the RED escaped-bootstrap feature test**

Render a title containing:

- two visible playable seasons;
- one hidden season;
- two media translations for the selected episode;
- one media item from another episode;
- an upstream URL containing a unique secret-like marker.

Assert:

```php
$response
    ->assertOk()
    ->assertSee('data-player-menu-bootstrap=', false)
    ->assertSee('data-player-transition-episode="'.$selectedEpisode->id.'"', false)
    ->assertSee('data-player-transition-media="'.$selectedMedia->id.'"', false)
    ->assertDontSee('provider-secret-marker', false)
    ->assertDontSee((string) $hiddenSeason->id, false);
```

Parse `data-player-menu-bootstrap` with `Crawler` or a DOM helper and assert the exact keys and authorized IDs.

- [x] **Step 2: Strengthen the ignored-shell RED contract**

Update `LivewireWireIgnoreContractTest` to assert:

```php
self::assertSame(1, substr_count($markup, 'wire:ignore'));
self::assertSame(1, substr_count($player, '<video'));
self::assertStringContainsString('data-player-menu-bootstrap=', $player);
self::assertStringNotContainsString('wire:ignore.self', $player);
```

Assert fallback episode/media controls remain after the closing ignored shell and retain:

- `wire:click.prevent="selectEpisode({{ $episodeOption->id }})"`;
- `wire:click.prevent="selectMedia({{ $mediaOption['mediaId'] }})"`;
- `data-catalog-history`;
- their normal `href`.

- [x] **Step 3: Run focused tests and verify RED**

Run:

```bash
php artisan test tests/Unit/LivewireWireIgnoreContractTest.php
php artisan test --filter=player_menu_bootstrap
```

Expected: bootstrap marker and transition markers are absent.

- [x] **Step 4: Build safe bootstrap data in `render()`**

Map existing loaded season summaries and current episode media into scalars. Reuse factory/presentation helpers where available, but do not call source resolution again.

Hard bounds:

- seasons: existing bounded season summaries, capped at 100;
- translations: current authorized media only, capped at 24;
- labels: localized interface copy plus unchanged source identity;
- counts: non-negative integers.

Pass `$playerMenuBootstrap` to Blade.

- [x] **Step 5: Add escaped markers and progressive-enhancement hooks**

Inside the existing `data-player-shell`, add:

```blade
data-player-menu-bootstrap="{{ \Illuminate\Support\Js::encode($playerMenuBootstrap) }}"
```

On fallback episode anchors add:

```blade
data-player-transition-episode="{{ $episodeOption->id }}"
```

On fallback media anchors add:

```blade
data-player-transition-episode="{{ $selectedEpisode->id }}"
data-player-transition-media="{{ $mediaOption['mediaId'] }}"
```

Do not move these controls inside `wire:ignore`. JavaScript will intercept only when a live `CatalogPlayerSession` accepts the event; otherwise the current Livewire/no-JavaScript behavior remains.

- [x] **Step 6: Verify GREEN and HTML privacy**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/LivewireWireIgnoreContractTest.php
php artisan test --filter=CatalogTitlePlayer
```

Expected: tests pass, exactly one ignored shell and one video remain, and upstream URLs are absent from bootstrap.

- [x] **Step 7: Commit the bootstrap contract**

Run:

```bash
git status --short --branch
git add app/Livewire/CatalogTitlePlayer.php resources/views/livewire/catalog-title-player.blade.php tests/Feature/CatalogPageTest.php tests/Unit/LivewireWireIgnoreContractTest.php
git diff --cached --check
git commit -m "feat: bootstrap player episode menu"
```

Do not stage `composer.lock`.

---


### Task 7: Build the JavaScript-owned accessible player menu

**Files:**

- Create: `resources/js/player-menu.js`
- Modify: `resources/js/player.js:1-450`
- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

```js
export class CatalogPlayerMenu {
    constructor({
        shell,
        controlsRoot,
        copy,
        bootstrap,
        signal,
        loadEpisodePage,
        selectEpisode,
        selectTranslation,
    })

    open()
    close({ restoreFocus = true } = {})
    toggle()
    updateCurrent(transition)
    setLoading(isLoading)
    setError(message)
    destroy()
}
```

- `CatalogPlayerMenu` owns only DOM and menu interaction.
- It never calls Livewire directly and never reads an upstream URL.
- All dynamic server text is assigned with `textContent`.

- [x] **Step 1: Add the RED Playwright menu-presence test**

In `tests/browser/player-lifecycle.spec.js`, add a desktop-only test that:

1. opens the current player fixture;
2. asserts one button with localized accessible name from `copy.menu.open`;
3. clicks it;
4. asserts one modal dialog;
5. asserts headings for seasons, episodes, and translations;
6. asserts the active season and translation use `aria-current="true"`;
7. closes it and verifies focus returns to the opener.

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="episode menu"
```

Expected: FAIL because the button/dialog do not exist.

- [x] **Step 2: Add RED keyboard and focus behavior**

Extend the same test:

- blur page focus and press `Shift+E`; dialog opens;
- press `Tab` repeatedly and assert focus never leaves dialog;
- press `Shift+Tab` from the first focusable target and assert focus wraps to the last target;
- press arrow keys and assert focus moves within the active list;
- press `Enter` or `Space` on a season;
- press `Escape`; dialog closes and opener regains focus;
- while dialog is open, press `Space`, `k`, `ArrowLeft`, and `ArrowRight`; assert playback state/time do not change.

- [x] **Step 3: Implement semantic DOM construction**

Create `player-menu.js` using `document.createElement()`:

- one control button inserted into the existing `.plyr__controls`;
- one `<dialog>` with `aria-labelledby`;
- one heading;
- desktop container with three labelled sections;
- one listbox/list per section with buttons;
- pagination navigation with previous/next buttons and page status;
- mobile back button and a single active level;
- polite status/error region;
- close button.

Use an internal helper that accepts only tag name, class list, text, and attributes. Do not accept raw HTML.

- [x] **Step 4: Implement focus and keyboard rules**

Implement:

- opener capture and focus restoration;
- native `showModal()` with safe fallback `open` attribute;
- explicit first/last Tab wrapping for fallback browsers;
- roving `tabindex` within each list;
- Up/Down for list movement;
- Left/Right for desktop column movement;
- Enter/Space activation;
- Escape close;
- mobile back-level behavior;
- no action for modifier combinations except `Shift+E`;
- no playback shortcut propagation while open.

The dialog stays open while season pages load. Selecting a season invokes `loadEpisodePage(seasonId, 1)` and does not invoke `selectEpisode`.

- [x] **Step 5: Integrate with `CatalogPlayerSession`**

After Plyr initialization:

1. parse `data-player-menu-bootstrap` with the existing safe JSON pattern;
2. locate the actual Plyr controls root;
3. instantiate one `CatalogPlayerMenu`;
4. pass callbacks that dispatch session-owned requests;
5. route `Shift+E` before background playback shortcuts;
6. close menu during `destroy()`;
7. keep the menu instance attached to the same shell for the full session.

If bootstrap is missing/invalid or controls root is absent, playback remains functional and no broken menu is displayed.

- [x] **Step 6: Preserve standard fullscreen placement**

When the menu opens:

- if `document.fullscreenElement` contains the player, append the dialog inside that element;
- otherwise append inside `data-player-shell`;
- retain the original shell reference;
- return the dialog to the shell on close only if both nodes remain connected;
- never call fullscreen enter/exit and never replace `document.fullscreenElement`.

- [x] **Step 7: Verify GREEN and Vite compilation**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="episode menu"
```

Expected: build succeeds and the menu/focus test passes.

- [x] **Step 8: Commit the menu owner**

Run:

```bash
git status --short --branch
git add resources/js/player-menu.js resources/js/player.js tests/browser/player-lifecycle.spec.js
git diff --cached --check
git commit -m "feat: add accessible player episode menu"
```

Do not stage `composer.lock` or generated untracked artifacts.

---


### Task 8: Bridge menu requests to sequential Livewire actions

**Files:**

- Modify: `resources/js/player.js:248-900`
- Modify: `resources/js/player-navigation.js:1-209`
- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- Session dispatches:
  - `catalog-player-menu-page-request`
  - `catalog-player-transition-request`
  - `catalog-player-transition-commit`
- Each event detail includes immutable scalar request data, a monotonic `generation`, and `resolve`/`reject` callbacks.
- The bridge calls exactly one matching Livewire action and never calls `$refresh()`.

- [x] **Step 1: Add the RED season-browse test**

In Playwright, stub or observe Livewire action calls and:

1. start video playback;
2. open menu;
3. select another season;
4. wait for its episode page;
5. assert the video remains playing;
6. assert current time did not reset;
7. assert source/session/URL/current discussion target did not change;
8. assert only `playerEpisodePage` was called.

- [x] **Step 2: Add the RED last-selection-wins test**

Delay the response for episode A and allow episode B to resolve first. Trigger A then B rapidly. Assert:

- only B is passed to the hot-swap application callback;
- A cannot close the menu, change source, change labels, change URL, or commit state;
- loading state ends for the accepted generation;
- no uncaught promise rejection is recorded.

- [x] **Step 3: Implement event bridge helpers**

In `player-navigation.js`, add:

```js
const callWire = async (root, method, args) => {
    const wire = wireFor(root);

    if (!wire) {
        throw new Error('Player component is unavailable.');
    }

    return wire[method](...args);
};
```

Bind the three events:

- menu page → `playerEpisodePage(seasonId, page)`;
- transition prepare → `preparePlayerTransition(episodeId, mediaId)`;
- transition commit → `commitPlayerTransition(episodeId, mediaId)`.

Before invoking a callback, verify:

- root remains connected;
- request session key equals `root.dataset.activePlayerSession`;
- request generation is still accepted by the session callback;
- payload has a recognized `status`.

Pass failures to `reject()` with no raw server exception text.

- [x] **Step 4: Implement monotonic generations in the session**

Add:

```js
this.menuGeneration = 0;
this.transitionGeneration = 0;
this.acceptedTransitionGeneration = 0;
```

Each menu page request increments `menuGeneration`. Each manual selection increments `transitionGeneration`, invalidates a prefetched transition, and records the selected generation.

Callbacks compare exact generation before changing any session/menu state. `destroy()` increments both generation counters and rejects/discards pending callbacks through the existing `AbortController`.

- [x] **Step 5: Intercept fallback links only when the session can handle them**

In the existing root click capture:

- find `data-player-transition-episode`;
- ignore modified clicks, non-left clicks, downloads, external targets, and current anchors;
- when a live session exists, prevent default and request the transition;
- otherwise preserve existing `data-catalog-history` plus Livewire/no-JavaScript navigation.

Do not remove `wire:click.prevent`, `href`, or existing rendered methods.

- [x] **Step 6: Verify GREEN and lifecycle cleanup**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="season browsing|last selection wins"
```

Expected: both tests pass, no duplicate listener/action appears after repeated `livewire:navigated`.

- [x] **Step 7: Commit the bridge**

Run:

```bash
git status --short --branch
git add resources/js/player.js resources/js/player-navigation.js tests/browser/player-lifecycle.spec.js
git diff --cached --check
git commit -m "feat: bridge player menu transitions"
```

Do not stage `composer.lock`.

---


### Task 9: Hot-swap source and rotate progress context in place

**Files:**

- Modify: `resources/js/player.js:248-1530`
- Modify: `resources/js/player-navigation.js`
- Modify: `tests/browser/player-lifecycle.spec.js`
- Modify: `tests/browser/support/player-media-fixtures.js` if required

**Interfaces:**

```js
CatalogPlayerSession.prototype.applyTransition = async function (
    transition,
    { autoplay, reason, generation },
)
```

- The method resolves only after the new source reaches metadata/ready or a bounded error state.
- It never replaces `this.video`, `this.plyr`, `this.shell`, or the Plyr container.

- [x] **Step 1: Add the RED manual episode identity test**

In Playwright:

1. capture references to `video`, `.plyr`, `data-player-shell`, and `document.fullscreenElement`;
2. mark them with unique expando/data values;
3. open the menu and select another episode;
4. wait for the new source to load;
5. assert all nodes are the same JavaScript objects and retain markers;
6. assert exactly one video and one Plyr root exist;
7. assert current time is `0`;
8. assert the new episode/media IDs are active.

Run once in normal mode and once after entering standard fullscreen when the browser permits it. Skip only with an explicit capability check, not on assertion failure.

- [x] **Step 2: Add the RED translation identity test**

Select another translation for the current episode and assert:

- same video/Plyr/fullscreen DOM identity;
- time resets to `0`;
- volume, muted state, speed, autoplay, and keyboard preferences remain unchanged;
- menu closes and focus returns to the player control;
- preferred profile storage changes only through the existing explicit preference path, not through technical fallback.

- [x] **Step 3: Add the RED progress-context separation test**

Capture emitted `catalog-progress` payloads. Begin episode A at 40 seconds, apply transition to episode B, begin B at 5 seconds, then pause.

Assert:

- A emits a final forced event with A's episode ID, token, and monotonic A sequence;
- B starts with B's episode ID, a different token, and sequence `1`;
- no event combines A token with B episode ID;
- root/video active session markers match the new context only after A's immutable flush event is constructed.

- [x] **Step 4: Implement immutable old-context flush**

Before changing any mutable session field:

1. capture old `sessionKey`, `episodeId`, `mediaId`, progress token, sequence, position, duration, and completion flag into a plain object;
2. dispatch the old progress event from that snapshot;
3. stop heartbeat and clear seek/recovery/buffering timers;
4. invalidate prefetched data and source failure state for the old episode.

The network call triggered by the progress event is not awaited.

- [x] **Step 5: Implement atomic context rotation**

Set the accepted transition fields as one synchronous block:

- `sessionKey = transition.contextKey`;
- `episodeId`, `mediaId`;
- source URL/MIME/format/expiry datasets;
- progress token, sequence `0`, enabled marker;
- `completed=false`;
- `expired=false`;
- `fallbackRequested=false`;
- `networkRetries=0`;
- `mediaRecoveries=0`;
- `hasStartedPlayback=false`;
- `hasDispatchedProgress=false`;
- `lastSavedPosition=0`;
- `resumePosition=0`;
- new navigation and Media Session data;
- root `data-active-player-session`;
- video `data-player-session`, `data-progress-episode`, `data-player-media-id`, and `data-progress-session`.

Update menu current markers after the context block.

- [x] **Step 6: Replace MP4 and HLS sources without replacing nodes**

For every transition:

1. pause current media;
2. destroy the old HLS instance and remove only its handlers;
3. remove obsolete `<source>` child values/datasets from the same video;
4. clear the same video's `src`, call `load()`, then set the new source;
5. for HLS.js-supported playback, create exactly one new HLS instance through existing `replaceHls(newUrl)`;
6. for native HLS or MP4, set the same video's `src` directly;
7. call `load()`;
8. wait for `loadedmetadata`/`canplay` with the session abort signal and a bounded error path.

Do not use a Plyr source setter if it replaces or reconstructs the video node. Keep the existing Plyr instance attached.

- [x] **Step 7: Apply playback policy**

After metadata:

- keep `currentTime=0`;
- if `autoplay=true`, call `video.play()`;
- on fulfilled play, enter normal playing state;
- on `NotAllowedError` or any rejected promise, retain the selected source, set localized `playRequired`, show the existing play control, and do not roll back to the old episode;
- if `autoplay=false`, remain ready at `0`.

Technical same-episode fallback continues using `queueTransientResume()` and must not call the new zero-position transition path.

- [x] **Step 8: Commit accepted selection without blocking source start**

Immediately after atomic context rotation, dispatch `catalog-player-transition-commit`. Do not await this network request before media loading/play begins.

On successful commit:

- update current URL query through Task 11's history helper;
- keep the transition current.

On commit failure:

- leave the already-authorized source active;
- show localized synchronization error;
- never apply an older generation;
- allow retry of state synchronization without reissuing an upstream URL.

- [x] **Step 9: Refresh Media Session without reinitializing the player**

Replace captured DOM-anchor handlers with handlers that consult current in-memory `navigation.previous`/`navigation.next`. Update metadata from `transition.mediaSession`, retain play/pause/seek handlers, and set next/previous handlers to request player transitions.

- [x] **Step 10: Verify GREEN for MP4, HLS, progress, and fullscreen**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="hot swap|progress context|translation identity|fullscreen identity"
```

Expected: all focused cases pass with one video/Plyr/HLS owner and no external leak/browser error.

- [x] **Step 11: Commit the in-place transition**

Run:

```bash
git status --short --branch
git add resources/js/player.js resources/js/player-navigation.js tests/browser/player-lifecycle.spec.js tests/browser/support/player-media-fixtures.js
git diff --cached --check
git commit -m "feat: switch player sources in place"
```

Stage the fixture helper only if changed. Do not stage `composer.lock`.

---


### Task 10: Replace countdown navigation with immediate prefetched auto-next

**Files:**

- Modify: `resources/js/player.js:504-900`
- Modify: `resources/views/livewire/catalog-title-player.blade.php:137-162`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`
- Modify: `app/View/ViewData/CatalogPlayerCopy.php`
- Modify: `tests/Unit/CatalogPlayerCopyTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- One prefetch state:

```js
this.prefetchedTransition = null;
this.prefetchPromise = null;
this.prefetchEpisodeId = null;
```

- Prefetch threshold: known remaining duration `<= 60` seconds.
- Artificial countdown/timer: absent.

- [x] **Step 1: Add RED immediate auto-next test**

Prepare two playable episodes. Instrument:

- `setTimeout`;
- `setInterval`;
- source load timestamps;
- `ended` event timestamp.

Enable autoplay, make a valid next transition ready, dispatch `ended`, and assert:

- completion event for old episode is emitted first;
- new source application begins in the same task/microtask chain;
- no countdown dialog is visible;
- no 1-second countdown interval is created;
- no artificial delay is awaited;
- new episode starts at `0`;
- DOM/fullscreen identity remains unchanged.

- [x] **Step 2: Add RED near-end prefetch bounds**

Set known duration to 120 seconds and dispatch time updates at 20, 59, 60, 61, and 70 seconds.

Assert:

- no transition request before remaining time reaches 60 seconds;
- exactly one request once threshold is crossed;
- repeated time updates do not create requests;
- seeking backward does not create another request while the prepared grant remains valid;
- manual episode choice, autoplay disable, navigation, or destroy invalidates the prefetched transition;
- no menu-page request occurs from time updates.

- [x] **Step 3: Add RED autoplay-off, final, expiry, network, and blocked-play cases**

Assert:

- autoplay off: old episode stays ended and no transition applies;
- no next episode: localized final-episode notice appears;
- expired prepared grant: one immediate bounded re-prepare occurs;
- prefetch network failure: current playback continues unaffected, then `ended` performs one immediate retry;
- retry failure: same video/fullscreen shell stays present with error and retry control;
- blocked `play()`: new source stays selected at `0`, status is `playRequired`, and user can press play;
- unrelated recommendation is never started.

- [x] **Step 4: Run browser test and verify RED**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="immediate auto next"
```

Expected: FAIL because current code displays and updates the countdown, then clicks a link.

- [x] **Step 5: Implement one bounded near-end prefetch**

In `handleTimeUpdate()`:

1. keep current position and Media Session updates;
2. return unless autoplay is enabled;
3. require finite duration and current time;
4. require current `navigation.next.id`;
5. require remaining time between `0` and `60`;
6. return when the same next episode has a live prefetched transition or pending promise;
7. request one `preparePlayerTransition(nextId, null)`;
8. store only if session key, next ID, and generation are still current;
9. treat errors as silent prefetch failure.

Never show a loading state during prefetch.

- [x] **Step 6: Replace `handleEnded()` behavior**

The new order is:

1. guard duplicate completion;
2. stop heartbeat;
3. dispatch immutable completion progress;
4. set ended status and Media Session state;
5. if autoplay is off, return;
6. if no next, show `finalEpisode` and return;
7. if valid prefetched transition exists, call `applyTransition(transition, { autoplay: true, reason: 'auto-next', generation })` immediately;
8. otherwise set `loadingTransition`, issue one immediate preparation, then apply;
9. on bounded failure, preserve shell/fullscreen and expose retry.

No countdown, `setInterval`, cancel button, or “watch now” path remains.

- [x] **Step 7: Remove delivered countdown code and markup**

Delete:

- countdown DOM block;
- countdown element fields;
- countdown timer fields;
- `startAutoplayCountdown()`;
- `renderAutoplayCountdown()`;
- `cancelAutoplayCountdown()`;
- `navigateToNextEpisode()` link-click behavior;
- countdown event listeners;
- countdown configuration data attribute if no other consumer exists;
- stale countdown translation keys and `autoplayCancelled` runtime copy after a repository-wide consumer scan.

Keep the autoplay toggle. Escape closes the player menu/help dialog; it no longer “cancels” a post-ended countdown.

- [x] **Step 8: Update PHP/Blade tests for countdown removal**

Assert:

```php
self::assertStringNotContainsString('data-player-autoplay-countdown', $view);
self::assertStringNotContainsString('data-player-countdown-seconds', $view);
self::assertStringNotContainsString('startAutoplayCountdown', $runtime);
self::assertStringNotContainsString('setInterval(() => {\n            this.countdownRemaining', $runtime);
```

Update exact copy keys only after the runtime references are removed.

- [x] **Step 9: Verify GREEN and search for stale countdown paths**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/CatalogPlayerCopyTest.php
php artisan test --filter=CatalogTitlePlayer
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="immediate auto next"
rg -n "autoplay-countdown|countdownRemaining|startAutoplayCountdown|next_episode_starts|autoplayCancelled" app resources lang tests docs
```

Expected: tests/build pass. The final search finds only historical design/plan/changelog evidence, not active application or test consumers.

- [x] **Step 10: Commit immediate auto-next**

Run:

```bash
git status --short --branch
git add app/View/ViewData/CatalogPlayerCopy.php lang/ru/catalog.php lang/en/catalog.php resources/views/livewire/catalog-title-player.blade.php resources/js/player.js tests/Unit/CatalogPlayerCopyTest.php tests/Feature/CatalogPageTest.php tests/browser/player-lifecycle.spec.js
git diff --cached --check
git commit -m "feat: enable immediate player auto next"
```

Do not stage `composer.lock`.

---


### Task 11: Preserve URL history, Back/Forward, discussions, Media Session, and fallback links

**Files:**

- Modify: `resources/js/player-navigation.js`
- Modify: `resources/js/player.js`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- Add in `player-navigation.js`:

```js
const playerSelectionKeys = [
    'season',
    'episode',
    'media',
    'variant',
    'quality',
    'format',
    'marker',
];

const playerUrlForQuery = (query) => URL;
const pushPlayerHistory = (query) => void;
```

- Current pathname, locale prefix, unrelated query parameters, and `#player` are preserved.
- Accepted transition creates one history entry only after a successful server commit response.

- [x] **Step 1: Add the RED history test**

Start on a localized title URL containing an unrelated safe query parameter and the player anchor. Select episode two, then translation two.

Assert after each accepted transition:

- same pathname and locale;
- unrelated query parameter preserved;
- canonical season/episode/media/profile values updated;
- stale `marker` removed;
- `#player` preserved;
- document navigation count remains unchanged;
- exactly one new history entry per accepted transition.

Press Back and Forward. Assert the existing popstate restoration selects the matching rendered state without a broken player, duplicate session, or lost unrelated query parameter.

- [x] **Step 2: Add RED discussion and Media Session assertions**

Observe the discussion Livewire component/event and `navigator.mediaSession` test shim.

After accepted transition assert:

- discussion target episode ID is the new episode;
- prefetch alone never changed discussion target;
- metadata title/artist/album correspond to new episode;
- `nexttrack` requests current navigation next;
- `previoustrack` requests current navigation previous;
- final episode has no active next handler;
- handlers are cleared on destroy.

- [x] **Step 3: Add RED no-JavaScript fallback test**

Disable JavaScript in a dedicated Playwright context or assert server HTML directly:

- season, episode, and media anchors have valid `href`;
- selecting the URL server-side renders the requested authorized state;
- unknown or inaccessible IDs normalize to the existing safe default;
- no new public endpoint is required.

- [x] **Step 4: Implement URL construction**

`playerUrlForQuery()` must:

1. clone `new URL(window.location.href)`;
2. delete only the seven player selection keys;
3. add normalized non-empty string values from the committed server query;
4. preserve unrelated query values;
5. set hash to existing hash or `#player`;
6. return the URL.

`pushPlayerHistory()` calls `history.pushState({ catalogPlayer: true }, '', url)` only when the resulting URL differs from current URL.

Do not trust the pre-commit transition query for history; use the query returned by `commitPlayerTransition()`.

- [x] **Step 5: Keep popstate compatibility explicit**

Retain `restoreSelectionFromLocation()` and its existing `$set('season'|'episode'|'media'|'variant'|'quality'|'format'|'marker', value, false)` calls plus one `$refresh()` for Back/Forward recovery. Do not call it for normal in-place transitions.

Before popstate refresh:

- flush current progress;
- close player menu;
- invalidate pending transition generations;
- allow normal session cleanup.

After refresh, assert one new valid session is initialized. This compatibility path may recreate the rendered player because browser Back/Forward is explicit navigation; manual selection and auto-next remain in-place.

- [x] **Step 6: Verify GREEN**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogTitlePlayer
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="history|discussion|Media Session|fallback"
```

Expected: accepted transitions preserve routing/discussion/media contracts and fallback navigation remains operational.

- [x] **Step 7: Commit compatibility integration**

Run:

```bash
git status --short --branch
git add resources/js/player-navigation.js resources/js/player.js tests/Feature/CatalogPageTest.php tests/browser/player-lifecycle.spec.js
git diff --cached --check
git commit -m "feat: synchronize player transition state"
```

Do not stage `composer.lock`.

---


### Task 12: Finish responsive, fullscreen, touch, and reduced-motion presentation

**Files:**

- Modify: `resources/css/app.css`
- Modify: `resources/js/player-menu.js`
- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- Desktop: three visible columns.
- Mobile: one semantic tree shown as sequential levels with Back.
- Episode page: at most 24 buttons.
- Season summaries: client-side pages of at most 12 buttons so the dialog never needs an internal scrolling list.
- Minimum coarse-pointer target: 44 CSS pixels.

- [x] **Step 1: Add RED responsive geometry tests**

For Desktop, Tablet, and Mobile Chromium assert:

- no document horizontal overflow;
- no menu/list internal scrollbar;
- desktop has three visible labelled columns;
- mobile has one visible level and a Back button after moving deeper;
- every visible interactive menu target has width and height at least 44 px on coarse-pointer projects;
- long labels wrap without clipping;
- episode pagination changes pages and retains focus;
- season client pagination shows at most 12 seasons at once;
- current episode remains discoverable by opening its containing page.

- [x] **Step 2: Add RED fullscreen and safe-area tests**

In standard fullscreen:

- dialog is a descendant of the fullscreen element;
- dialog stays within viewport bounds;
- close/back/pagination controls remain reachable;
- source transition keeps the same fullscreen element;
- safe-area padding resolves from `env(safe-area-inset-*)` declarations;
- no page behind the dialog receives pointer input.

- [x] **Step 3: Add RED reduced-motion test**

Emulate `prefers-reduced-motion: reduce`. Open/close menu and switch levels. Assert no required animation duration delays visibility, focus, or source selection.

- [x] **Step 4: Implement component classes in Tailwind source CSS**

Add narrowly named player classes under the existing CSS layer:

- `.catalog-player-menu`;
- `.catalog-player-menu__panel`;
- `.catalog-player-menu__columns`;
- `.catalog-player-menu__section`;
- `.catalog-player-menu__list`;
- `.catalog-player-menu__option`;
- `.catalog-player-menu__pagination`;
- `.catalog-player-menu__mobile-back`;
- state/data attribute selectors for active level/loading/error.

Use the project light palette, existing radii/spacing, no gradients, no inline CSS, and local FontAwesome icons only.

Use media queries for:

- three columns at desktop width;
- one active level below the mobile breakpoint;
- coarse pointer target sizing;
- fullscreen viewport/safe-area;
- reduced motion.

Do not use a fixed-height list with `overflow-y:auto`. Page long episode and season lists instead.

- [x] **Step 5: Implement client-side season paging**

In `CatalogPlayerMenu`:

- store all bounded season summaries from bootstrap;
- display 12 per client page;
- place current season's page first when menu opens;
- previous/next season-page controls use localized labels;
- changing season page does not call the server;
- choosing a season calls the bounded episode-page action;
- retain logical focus after page changes.

- [x] **Step 6: Verify GREEN across configured browser projects**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --grep="responsive episode menu"
```

Expected: Desktop, Tablet, and Mobile Chromium cases pass with no overflow/internal scrolling and correct accessible layout.

- [x] **Step 7: Commit presentation**

Run:

```bash
git status --short --branch
git add resources/css/app.css resources/js/player-menu.js tests/browser/player-lifecycle.spec.js
git diff --cached --check
git commit -m "feat: make player episode menu responsive"
```

Do not stage `composer.lock`.

---


### Task 13: Complete the browser failure and lifecycle matrix

**Files:**

- Modify: `tests/browser/player-lifecycle.spec.js`
- Modify: `tests/browser/support/player-media-fixtures.js`
- Modify: `tests/browser/prepare-fixtures.php` if additional catalog fixtures are required

**Interfaces:**

- Browser tests remain same-origin guarded.
- Fixtures never call a real upstream source.
- Every failure scenario has a finite request/retry count.

- [x] **Step 1: Add cross-season and release-lane coverage**

Create deterministic browser fixtures with:

- final regular episode in season one;
- first regular episode in season two;
- special episodes;
- final accessible regular episode.

Assert auto-next crosses regular season boundary, special navigation remains special-only, and the final episode displays localized final copy without a transition request.

- [x] **Step 2: Add preferred translation fallback coverage**

Set preferred translation A. Make next episode expose only translation B.

Assert:

- next episode starts with B;
- localized fallback notice is shown;
- active menu translation is B;
- stored preferred translation remains A;
- a later episode containing A selects A again.

- [x] **Step 3: Add network failure and retry coverage**

Use fixture queues to produce:

- transition action rejection;
- signed source 503;
- signed source 410/expired;
- HLS manifest network failure;
- corrupt media segment;
- successful retry.

Assert:

- current video/shell/fullscreen identity is retained;
- retries are bounded;
- no countdown or page navigation occurs;
- retry applies only the current generation;
- terminal state exposes one localized retry control;
- no raw status/provider URL is visible.

- [x] **Step 4: Add blocked-play and data-saver coverage**

Stub `video.play()` to reject. Assert source/current selection is committed, time remains `0`, play control is available, and no automatic retry loop starts.

Emulate `navigator.connection.saveData=true`. Assert existing data-saver behavior still disables autoplay/prefetch and the ended episode remains ended.

- [x] **Step 5: Add cleanup and duplicate-owner coverage**

Repeat:

- open/close menu;
- transition episodes;
- dispatch `livewire:navigated`;
- navigate away/back;
- dispatch `pagehide/pageshow`;
- resize/orientation changes.

Assert:

- one video;
- one Plyr root;
- at most one HLS fixture request chain;
- one menu button/dialog;
- no stale timers;
- no detached dialog;
- no duplicate document keyboard handling;
- old generation cannot commit after navigation;
- Media Session handlers are cleared/rebound once.

- [x] **Step 6: Run the full player browser file**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: all player lifecycle tests pass in Desktop, Mobile, and Tablet Chromium, with intentional project skips only where a test is explicitly single-project.

- [x] **Step 7: Review Playwright artifacts**

Inspect failures if any through trace/screenshots/video. On success, confirm no unexpected retained failure artifacts or untracked fixture databases are staged.

- [x] **Step 8: Commit final browser coverage**

Run:

```bash
git status --short --branch
git add tests/browser/player-lifecycle.spec.js tests/browser/support/player-media-fixtures.js tests/browser/prepare-fixtures.php
git diff --cached --check
git commit -m "test: cover seamless player lifecycle"
```

Stage only files that changed. Do not stage `composer.lock`, `output/`, screenshots, traces, videos, databases, or generated reports.

---


### Task 14: Run full regression, update canonical documentation, and close compliance

**Files:**

- Modify: `docs/audits/video-playback-report.md`
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/UI_STANDARDS.md` only if the delivered menu establishes a reusable rule
- Modify: `docs/superpowers/specs/2026-07-24-player-seamless-episode-switching-design.md`
- Modify: `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [x] **Step 1: Re-read all applicable canonical requirements**

Read in index order:

```bash
sed -n '1,260p' AGENTS.md
sed -n '1,260p' docs/requirements/index.md
```

Then re-read every player-applicable canonical owner listed by the index, including code standards, architecture, development, multilingual, security, performance/caching, UI/frontend, maintenance/upgrades, production operations, system-wide integration, data relations, and playback audit.

Record any new discovery immediately in `docs/plans/current-task-plan.md` before further code changes.

- [x] **Step 2: Run PHP formatting and focused tests**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/PlayerEpisodePageDataTest.php tests/Unit/PlaybackTransitionDataTest.php
php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
php artisan test tests/Feature/CatalogPlayerTransitionFactoryTest.php
php artisan test --filter=CatalogTitlePlayer
php artisan test tests/Unit/CatalogPlayerCopyTest.php tests/Unit/LivewireWireIgnoreContractTest.php
```

Expected: every focused test passes.

- [x] **Step 3: Run frontend and browser verification**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: Vite build and the complete player browser matrix pass.

- [x] **Step 4: Run full repository verification**

Run:

```bash
php artisan test
./vendor/bin/phpunit
php artisan project:docs-refresh --check
git diff --check
```

If project hooks document additional current checks in `docs/development.md`, run those exact non-destructive checks too. Do not run nonexistent Pest or npm lint commands.

Expected: full suites, managed-doc check, and whitespace check pass.

- [x] **Step 5: Perform repository-wide legacy and privacy scans**

Run:

```bash
rg -n "autoplay-countdown|countdownRemaining|startAutoplayCountdown|next_episode_starts|autoplayCancelled" app resources lang tests docs
rg -n "#\\[Json\\]|#\\[Async\\]" app/Livewire/CatalogTitlePlayer.php docs/superpowers/specs/2026-07-24-player-seamless-episode-switching-design.md docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md
rg -n "data-player-transition|playerEpisodePage|preparePlayerTransition|commitPlayerTransition|CatalogPlayerTransitionFactory" app resources tests docs
rg -n "playback_url|source_url|provider|userId|user_id" app/DTOs/PlayerEpisodePageData.php app/DTOs/PlaybackTransitionData.php app/Services/Catalog/CatalogPlayerTransitionFactory.php resources/js/player.js resources/js/player-menu.js
rg -n "wire:ignore" resources/views tests/Unit/LivewireWireIgnoreContractTest.php
rg -n "<video|new this\\.Plyr|new this\\.Hls" resources/views/livewire/catalog-title-player.blade.php resources/js/player.js
```

Review every match semantically. Historical documentation references may remain. Active duplicate countdown implementations, raw URL serialization, second player/menu owners, unfinished paths, and dead controls must not remain.

- [x] **Step 6: Update canonical runtime documentation**

Change implementation-pending language to delivered evidence only after all tests pass:

- playback report: immediate auto-next, transition contract, progress rotation, fallback, browser evidence;
- architecture: one renderless data boundary and one JS-owned ignored-shell menu;
- frontend: DOM ownership, menu behavior, lifecycle cleanup, responsive/accessibility rules;
- UI standards: only if a reusable menu rule was truly introduced;
- approved design: status `реализовано` plus deviations/evidence;
- implementation plan: check completed steps and record exact commands/results.

Do not claim native iOS fullscreen preservation without real-device evidence.

- [x] **Step 7: Update visitor documentation and changelog**

Because runtime behavior is now visitor-facing:

- update the relevant player section in Russian `README.md`;
- append one dated visitor-history item describing in-player season/episode/translation choice and immediate next episode without reopening the player;
- keep `История обновлений для посетителей` as the last level-two section;
- add a separate Russian technical bullet to `CHANGELOG.md`;
- preserve all previous changelog entries;
- keep technical identifiers in exact spelling and code formatting.

Do not manually edit managed `project-docs` blocks. Run `php artisan project:docs-refresh` only if an owner change requires regeneration, then inspect the exact generated diff.

- [x] **Step 8: Complete the task compliance matrix**

Record `completed`, `already_compliant`, `not_applicable`, or `unresolved` for:

- canonical intake/order;
- version-specific Livewire behavior;
- TDD RED/GREEN evidence;
- player/source lifecycle;
- hierarchy/access/source security;
- privacy/raw URL;
- progress identity;
- translations;
- accessibility/mobile/fullscreen;
- history/discussions/Media Session;
- cache/performance;
- routes/API/SEO;
- migrations/database;
- dependencies/build tooling;
- production assets/rollback;
- README/CHANGELOG/docs;
- real-device iOS evidence;
- Git commit/push.

Do not mark iOS evidence complete without a real device.

- [x] **Step 9: Commit verified runtime and documentation**

Run:

```bash
git status --short --branch
git diff --stat
git diff -- README.md CHANGELOG.md docs
git add README.md CHANGELOG.md docs/audits/video-playback-report.md docs/architecture.md docs/frontend.md docs/UI_STANDARDS.md docs/superpowers/specs/2026-07-24-player-seamless-episode-switching-design.md docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md docs/plans/current-task-plan.md
if git diff --cached --name-only | rg -x 'composer\\.lock'; then exit 1; fi
git diff --cached --check
git status --short --branch
git commit -m "feat: deliver seamless player episode switching"
```

Stage `docs/UI_STANDARDS.md` only if it changed. The commit must be on `main`, include meaningful README product change, exclude `composer.lock`, and pass repository hooks. If any authorized application file remains uncommitted from earlier checkpoints, review and stage it by its exact path rather than staging a directory.

---


### Task 15: Production-impact review, rollback proof, and Git delivery

**Files:**

- Verify only; modify documentation only if verification discovers an inaccuracy.

- [x] **Step 1: Confirm no schema/dependency/route drift**

Run:

```bash
git diff origin/main...HEAD -- database/migrations routes composer.json composer.lock package.json package-lock.json config .env.example
php artisan route:list --name=playback.source
```

Expected:

- no task-owned migration, dependency, config, environment, or public route change;
- the existing signed playback route remains the only source delivery route;
- pre-existing `composer.lock` remains unstaged and unmodified by this task.

- [x] **Step 2: Confirm production asset boundary**

Run:

```bash
npm run build
test -f public/build/manifest.json
git status --short --branch
```

Document the deployment requirement: application code, Vite manifest, and referenced hashed assets activate together. A failed/interrupted build blocks activation; it is not repaired by clearing application data.

- [x] **Step 3: Record rollback strategy**

Rollback is code/assets only:

1. deploy the previous compatible application commit;
2. deploy that commit's matching Vite manifest and hashed assets together;
3. verify the old rendered next/countdown path and existing signed source route;
4. do not run migration rollback, data repair, cache flush, queue clear, storage deletion, or dependency reinstall;
5. keep old hashed assets available until the manifest switch is confirmed.

For partial deployment:

- new PHP + old assets: do not activate; restore matching release;
- old PHP + new assets: do not activate; restore matching release;
- unavailable source/provider: current bounded error/retry remains;
- stale browser asset: hashed manifest prevents mixed filenames; retain prior assets during rollout.

- [x] **Step 4: Record iOS real-device evidence**

If a real iPhone/iPad is available, test:

- native fullscreen source switch;
- playback continuation;
- exit back to inline player;
- menu availability after return.

If not available, keep this row:

```text
Native iOS fullscreen source-swap preservation: unresolved — Chromium emulation does not prove WebKit/OS fullscreen behavior; no fake fullscreen was added.
```

- [x] **Step 5: Final repository and branch check**

Run:

```bash
git status --short --branch
git log -5 --oneline
git diff --check
```

Expected: branch is `main`; all authorized feature/docs changes are committed; only the pre-existing unstaged `composer.lock` may remain.

- [x] **Step 6: Push configured remote**

Run:

```bash
git push origin main
```

Expected: push succeeds. If HTTPS authentication or remote access fails, record the exact failure as `unresolved`; do not claim delivery and do not change remote credentials.

- [x] **Step 7: Final handoff**

Report:

- delivered visitor behavior;
- exact verification commands/results;
- commit SHA;
- push result;
- explicit `composer.lock` exclusion;
- compliance matrix summary;
- native iOS evidence status;
- any genuine unresolved item.

Do not report implementation complete until all non-platform acceptance criteria and required verification gates pass.

---


## Final Acceptance Checklist

- [x] In-player menu exposes seasons, bounded/paginated episodes, and authorized translations.
- [x] Desktop uses three columns; mobile uses sequential levels and Back.
- [x] `Shift+E`, Escape, focus trap/return, arrows, Enter, Space, touch targets, safe area, and reduced motion pass.
- [x] Browsing seasons does not pause/reset current playback.
- [x] Manual episode and translation changes start at `0`.
- [x] Automatic next begins immediately after `ended` with no countdown/artificial timer.
- [x] Autoplay off, final episode, prefetch failure, expired grant, source failure/retry, and blocked `play()` are honest finite states.
- [x] Regular cross-season and special-lane ordering are server-owned and deterministic.
- [x] Every transition revalidates hierarchy/access/source and returns one same-origin signed grant only.
- [x] Preferred translation survives temporary fallback and is retried on later episodes.
- [x] Old/new progress episode IDs, tokens, and sequences never mix.
- [x] Same `<video>`, Plyr root, player shell, and standard fullscreen element survive manual and automatic transitions.
- [x] URL/history, Back/Forward, discussion target, Media Session, source fallback, restart, preferences, PiP, and cleanup remain compatible.
- [x] SSR/no-JavaScript links remain valid.
- [x] One full `wire:ignore`, one video, one Plyr, and at most one HLS.js instance remain.
- [x] No route, migration, cache key, dependency, service worker, queue, or raw provider URL is added.
- [x] Focused PHP tests, full PHPUnit, Vite build, full player Playwright matrix, docs checks, scans, and `git diff --check` pass.
- [x] README, canonical owners, design, implementation plan, current-task compliance matrix, and Russian CHANGELOG reflect actual delivered state.
- [x] Native iOS fullscreen is either proven on a real device or honestly `unresolved`.
- [x] Authorized changes are committed on `main`; configured push is attempted and truthfully reported.

## Plan Self-Review Checklist

- [x] Every approved design requirement maps to at least one task and one acceptance check.
- [x] Every new public PHP/JavaScript contract has an exact name and owning file.
- [x] Every behavior change has a RED command, expected reason, GREEN command, and compatibility check.
- [x] Payload types use consistent camelCase browser keys and typed PHP constructor properties.
- [x] Prefetch and commit state are explicitly separate.
- [x] No task instructs use of `#[Json]`, `#[Async]`, raw provider URLs, duplicate players, fake fullscreen, unbounded lists, or client-trusted authorization.
- [x] No placeholder, deferred implementation note, unfinished stub, or omitted repetition remains.
- [x] Commit steps exclude the pre-existing `composer.lock`.
- [x] Documentation and production rollback are part of implementation, not an optional follow-up.

---

## Безлимитный rolling roadmap

### Назначение

Этот раздел продолжает тот же план после завершения Tasks 1–15. Он не
объявляет проигрыватель «вечной незавершённой задачей» и не требует выполнять
все возможные улучшения. Он задаёт постоянный механизм приёма новых задач:
измеренный дефект, новый подтверждённый источник данных, browser/platform
evidence, security requirement, совместимое изменение provider contract или
обязательное сопровождение превращается в следующий монотонный `Task N`.

План безлимитен по числу будущих evidence-driven задач. Каждая отдельная задача
имеет ограниченный scope, завершение, rollback и commit. Task не создаётся
только ради заполнения очереди, новой версии package или гипотетической
возможности.

### Execution ledger

| Workstream | Code | Verification | Local delivery | Remote delivery | Production activation |
| --- | --- | --- | --- | --- | --- |
| Tasks 1–4: typed server transition boundary | `completed` | `completed` | `1ded102` | `unresolved_auth` | `not_claimed` |
| Tasks 5–8: translations, bootstrap, menu и bridge | `completed` | `completed` | `1ded102` | `unresolved_auth` | `not_claimed` |
| Tasks 9–12: hot swap, auto-next, history и responsive fullscreen | `completed` | `completed` | `1ded102` | `unresolved_auth` | `not_claimed` |
| Tasks 13–14: lifecycle matrix, docs и compliance | `completed` | `completed` | `1ded102` | `unresolved_auth` | `not_claimed` |
| Task 15: rollback/delivery evidence | `completed_local` | `completed` | `ab6532a` | `unresolved_auth` | `not_claimed` |
| Native iOS fullscreen evidence | `not_applicable_to_code` | `unresolved_device` | `not_applicable` | `not_applicable` | `unresolved_device` |
| Rolling roadmap update | `not_applicable_to_runtime` | `documentation_gate_required` | `pending_commit` | `unresolved_auth` | `not_applicable` |

Статусы независимы. `completed` code не означает published remote, deployed
production или проверенный native iOS. Отсутствующий внешний evidence никогда
не понижает уже проверенный локальный runtime, но остаётся видимым gate.

### Монотонная нумерация и intake

- Tasks 1–15 никогда не перенумеровываются и не переписываются под новый scope.
- Следующий принятый work item получает первый свободный номер начиная с
  `Task 16`; удалённый кандидат не освобождает и не переиспользует номер.
- `Task 20+` создаётся только после датированного evidence. Линия roadmap может
  быть без верхнего предела, но в `in_progress` находится не более одной
  player-задачи, если две задачи меняют общие PHP/JavaScript/Blade/CSS contracts.
- Независимый read-only browser/provider audit может идти рядом только когда он
  не меняет shared files, index, runtime или production state.
- Новый Task сначала появляется в этом документе с reason, exact scope,
  expected files, protected contracts, cross-feature matrix, RED/verification,
  rollback и delivery gate. Только затем разрешены application edits.
- Неподтверждённая идея остаётся trigger в permanent lane и не получает fake
  checkbox, UI control, route, schema, environment variable или package.

### Definition of Ready

Будущий player Task готов к реализации только когда одновременно выполнено:

1. Записан конкретный business, correctness, security, accessibility,
   compatibility, performance или maintenance reason.
2. Есть воспроизводящий тест, browser trace, production-safe observation,
   provider contract, реальное устройство или authoritative version guidance.
3. Проверены текущие `CatalogTitlePlayer`, `CatalogTitlePlaybackQuery`,
   `CatalogPlayerTransitionFactory`, resolver/entitlement/progress boundaries,
   `player.js`, `player-menu.js`, `player-navigation.js`, Blade, translations,
   tests и тематические docs.
4. Перечислены exact files и public/persisted contracts, которые должны
   остаться совместимыми.
5. Оценены routes, migrations, translations, cache keys, permissions,
   progress/history, SEO, API, importer/admin, mobile, Premium/region/legal,
   production assets и rollback.
6. Любой dependency/runtime/provider change имеет отдельный decision record и
   official version-specific evidence.
7. Любое production действие имеет authority, previous known-good release,
   data/asset safety и failure-recovery plan.
8. Shared `main` и index не содержат чужого staged scope, который попадёт в
   task commit или будет перезаписан.

Если хотя бы одно обязательное условие отсутствует, статус задачи —
`unresolved_prerequisite`, а не `in_progress`.

### Definition of Done

Будущий player Task завершён только когда:

1. RED воспроизводит именно заявленный дефект или missing contract.
2. Минимальный GREEN сохраняет один player lifecycle и server-owned access.
3. Focused PHPUnit/contract tests, соответствующий Playwright matrix, Vite
   build и применимые full gates проходят на окончательном diff.
4. Проверены stale generations, cleanup, progress context, history,
   translations, keyboard/touch/focus, source privacy и fallback bounds.
5. Нет нового raw provider URL, client-trusted entitlement, второго player,
   polling/timeupdate request, fake capability, unbounded list или global cache
   flush.
6. Canonical playback/frontend/security/performance/production owners,
   current-task compliance, `README.md` при реальном product/roadmap change и
   русский `CHANGELOG.md` отражают только фактический результат.
7. Rollback охватывает code, matching Vite manifest/assets, schema/data/config
   только если они действительно изменились; unsafe rollback честно запрещён.
8. Task-owned diff изолированно закоммичен в существующей `main`, push
   выполнен либо внешний отказ записан как `unresolved`.
9. Не выполненная real-device/provider/production проверка остаётся отдельным
   status, а не маскируется локальным test pass.

### Постоянные workstream lanes

| Lane | Trigger для нового Task | Защищённые contracts |
| --- | --- | --- |
| Delivery и production | Git authentication восстановлена, появился release authority или deployment evidence | direct-to-`main`, matching code/manifest/assets, no history rewrite, documented rollback |
| Correctness и state machine | Воспроизводимый stale-response, double-transition, ended/final/blocked-play или cleanup defect | generation ordering, один session/video/Plyr/HLS, finite retry |
| Access, security и privacy | Изменился entitlement/provider/legal contract или найден конкретный IDOR/SSRF/leak | server revalidation, viewer binding, allowlist/public DNS, no raw URL/private context |
| Browser и platform | Новый подтверждённый WebKit/Chromium/Firefox/OS behavior либо regression | feature detection, no fake fullscreen/PiP, playsinline, standard fallback |
| Formats и media capabilities | Каталог действительно получил разрешённый HLS/audio/subtitle/format contract | truthful capability UI, importer/admin ownership, codec/CORS/Range/MIME evidence |
| Progress, history и preferences | Воспроизводимое смешение episode/token/sequence, resume, completion или device/account precedence | `(user_id, episode_id)`, monotonic writes, bounded anonymous store, stable preference key |
| UX, accessibility и localization | Реальная keyboard/touch/screen-reader/zoom/long-label проблема | RU/EN parity, semantic dialog, 44 px targets, no internal scroll, reduced motion |
| Performance и payload | Измеренный query, hydration, bundle, memory, startup, prefetch или playback latency regression | bounded menu/prefetch, no N+1/full graph, no unmeasured claim |
| Provider reliability | Подтверждённый Range/CORS/MIME/manifest/segment/outage pattern | bounded retry/fallback, provider controls intact, safe user error |
| API, importer и administration | Existing shared media/profile contract меняется у одного из соседних owners | canonical media identity, web/mobile parity, importer idempotency, one admin domain |
| Maintenance и upgrades | Authoritative advisory, EOL, deprecation или justified compatibility need | isolated version group, lock review, browser matrix, production rollback |
| Support и observability | Реальные tickets/health observations не позволяют безопасно диагностировать player defect | low-cardinality secret-free context, no playback analytics/fingerprinting by default |

### Триггеры, которые не создают задачу автоматически

- Появление более новой версии Laravel, Livewire, Plyr, HLS.js, Vite,
  Tailwind, Node или Playwright без project-specific benefit.
- Желание показать отдельный audio/subtitle selector без нормализованных tracks
  и реально выдаваемых URLs.
- DRM, MPEG-DASH, transcoding, offline video, generic proxy, HLS merge,
  protected-stream scraping или fake CSS fullscreen без отдельного approved
  product/legal/security/provider design.
- Один client playback error без server/provider corroboration.
- Желание добавить analytics, fingerprinting, heartbeat на каждую секунду,
  polling или permanent source URL ради удобства диагностики.
- Непроверенное обещание одинакового fullscreen/PiP/background поведения на
  всех browser/OS.

---

### Task 16: Опубликовать локально проверенный player baseline

**Status:** `unresolved_auth`. Это delivery-only Task; application code,
schema, assets и документация не меняются только ради повторной попытки.

**Files:**

- Verify: Git history and configured remote.
- Modify only after factual status change:
  `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
  and `docs/plans/current-task-plan.md`.

**Interfaces:**

- Consumes: local commits `1ded102`, `ab6532a` and the later isolated rolling
  plan commit.
- Produces: ordinary fast-forward publication evidence for `origin/main`, or
  an exact external failure classified `unresolved`.

- [ ] **Step 1: Confirm exact branch and task commits**

Run:

```bash
git status --short --branch
git branch --show-current
git log --oneline --decorate -12
git remote -v
```

Expected: branch is `main`; player commits remain ancestors of `HEAD`; no
credential value is printed or written.

- [ ] **Step 2: Confirm no history rewrite is required**

Run:

```bash
git merge-base --is-ancestor origin/main HEAD
git rev-list --left-right --count origin/main...HEAD
```

Expected: `origin/main` is an ancestor of local `HEAD`; only ordinary
fast-forward push is permitted. A failure stops the task for manual review;
force push, rebase and reset are forbidden.

- [ ] **Step 3: Execute the configured push**

Run:

```bash
git push origin main
```

Expected: success updates `origin/main`. Missing HTTPS credentials or remote
access remains `unresolved_auth`; do not edit remote credentials, expose a
token or claim publication.

- [ ] **Step 4: Record only the factual outcome**

On success, update both plan ledgers with the published `HEAD` and remote
status `completed`. On failure, keep the exact safe error category without
username, token, private hostname or credential detail.

- [ ] **Step 5: Commit a status-only correction only when it adds new evidence**

Do not create a commit that merely repeats the same authentication failure.
If publication status changed, run docs checks, stage only the two plan hunks,
commit on `main`, and publish that follow-up through the same ordinary push.

---

### Task 17: Проверить native iOS/WebKit fullscreen на реальном устройстве

**Status:** `unresolved_device`. Реализация не начинается без физического
iPhone/iPad или эквивалентного approved device-lab evidence.

**Files:**

- Modify after evidence:
  `docs/audits/video-playback-report.md`
- Modify after evidence:
  `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- Modify after evidence:
  `docs/plans/current-task-plan.md`
- Test only if a defect is reproduced:
  `tests/browser/player-lifecycle.spec.js`
- Runtime files are chosen only after a reproduced defect and a new finite
  implementation sub-plan.

**Interfaces:**

- Consumes: a published compatible build, public/authenticated test accounts,
  one title with at least two playable episodes and two real source variants
  when translation switching is tested.
- Produces: device/OS/browser/build matrix, exact pass/fail behavior and either
  confirmed limitation or a new bounded defect Task.

- [ ] **Step 1: Record safe test identity**

Record only device model family, iOS/iPadOS version, Safari/WebKit version,
orientation and application commit/build identity. Do not record account,
provider URL, signed grant, IP, cookie, token or private hostname.

- [ ] **Step 2: Test inline and native fullscreen transitions**

Execute manually:

1. Start episode 1 inline and enter native fullscreen.
2. Let immediate auto-next move to episode 2.
3. Confirm whether native fullscreen remains active and playback continues.
4. Exit to inline player and confirm menu, labels, URL and controls match
   episode 2.
5. Repeat with manual episode selection after returning inline.
6. Repeat with orientation change and browser Back/Forward.

Expected: no duplicate audio/session, no stale episode/progress context, and
the inline player is recoverable even if OS fullscreen exits by platform
design.

- [ ] **Step 3: Test honest platform limitations**

Confirm custom HTML menu is not claimed inside OS-owned native fullscreen when
WebKit does not expose it. Confirm no fake fullscreen, device sniffing or
hidden unsupported control was introduced.

- [ ] **Step 4: Classify the result**

- `completed`: behavior matches the documented limitation and no correctness
  defect exists.
- `unresolved_device`: device/build evidence is incomplete.
- `new_defect`: duplicate session, stale progress/source, unrecoverable UI or
  unsafe access behavior is reproducible.

Only `new_defect` creates the next numbered implementation Task with RED
evidence and exact affected files.

- [ ] **Step 5: Update evidence and verify documentation**

Run:

```bash
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
git diff --check
```

Expected: docs pass and claims match the recorded device evidence.

---

### Task 18: Активировать matching player release и выполнить production smoke

**Status:** `unresolved_authority`; blocked by Task 16 publication and explicit
deployment authority. This Task never edits `.env`, restarts services, clears
cache or deploys automatically without that authority.

**Files:**

- Verify/update after factual execution:
  `docs/deployment.md`
- Verify/update after factual execution:
  `docs/audits/video-playback-report.md`
- Update status:
  `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- Update status:
  `docs/plans/current-task-plan.md`

**Interfaces:**

- Consumes: published intended commit, its matching Vite manifest/hashed
  assets, previous known-good release and canonical deployment runbook.
- Produces: safe activation/rollback evidence; no new application contract.

- [ ] **Step 1: Satisfy activation prerequisites**

Verify explicit intended commit, clean deploy source, matching locked
dependencies, matching built assets, previous known-good release, backup
decision, web/PHP runtime compatibility and authorized operator.

Expected: any missing prerequisite blocks activation without changing runtime.

- [ ] **Step 2: Activate code and matching assets as one release**

Follow the existing deployment runbook. Do not mix new PHP/Blade/lang with old
Vite manifest or old PHP with new player chunks. Do not use `Cache::flush()`,
`cache:clear`, queue deletion, migration rollback or storage cleanup as a
player deployment step.

- [ ] **Step 3: Perform bounded smoke**

Verify:

1. public title page and one authenticated title page;
2. one signed source grant with `private, no-store`;
3. MP4 play/pause/seek and keyboard exclusions;
4. menu season/episode/translation switch;
5. immediate auto-next and autoplay off;
6. Back/Forward, progress resume and source fallback;
7. standard fullscreen DOM identity on a supported browser;
8. no first-party 4xx/5xx, console error or raw provider URL in HTML/action
   payload.

- [ ] **Step 4: Roll back on compatibility failure**

Activate the previous known-good code and its matching manifest/assets
together. Do not repair player rollout with schema/data/cache/queue mutations;
the player change has no migration or data repair.

- [ ] **Step 5: Record factual production state**

Use only `completed`, `rolled_back`, `unresolved_authority` or
`unresolved_environment`. Do not claim zero downtime, provider health, native
iOS compatibility or successful production activation without direct evidence.

---

### Task 19: Выполнить post-activation regression и принять следующий Task

**Status:** `blocked_by_task_18`.

**Files:**

- Modify only if new evidence changes a contract:
  `docs/audits/video-playback-report.md`
- Modify intake/ledger:
  `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- Modify compliance:
  `docs/plans/current-task-plan.md`
- Add or modify application/tests only in a separately promoted `Task 20+`.

**Interfaces:**

- Consumes: successful or rolled-back Task 18 evidence and bounded support/
  browser/provider observations.
- Produces: either `no_new_task` or exactly one prioritized next Task with
  evidence and prerequisites.

- [ ] **Step 1: Re-run deterministic regression on the published build**

Run in the verified application checkout:

```bash
php artisan test --filter='CatalogPageTest|CatalogPlayerTransitionFactoryTest|CatalogTitlePlaybackQueryTest|CatalogPlayerCopyTest|FrontendAssetContractTest|LivewireWireIgnoreContractTest|PlaybackTransitionDataTest|PlayerEpisodePageDataTest'
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: focused PHP, Vite and player browser matrix pass against the intended
build. A failure is classified before any code edit.

- [ ] **Step 2: Compare bounded runtime observations**

Review only safe low-cardinality evidence: first-party response status,
player initialization count, transition result code, provider error category,
browser/platform and timestamp. Do not introduce analytics or retain source
URLs, grants, account identity, cookies or per-second playback events.

- [ ] **Step 3: Select the next work item**

Priority order:

1. security/privacy/data-integrity defect;
2. reproducible correctness/progress/session defect;
3. accessibility regression;
4. provider/browser compatibility regression;
5. measured performance regression;
6. maintenance need with authoritative evidence;
7. confirmed new capability with complete domain/provider contract.

If no evidence meets Definition of Ready, record `no_new_task` and leave
`Task 20+` uncreated.

- [ ] **Step 4: Add exactly one next numbered Task**

The new task must include exact evidence, files, interfaces, RED/GREEN
commands, compatibility matrix, rollback and delivery gate. It may add more
future triggers to a lane, but it must not pre-approve speculative code,
dependencies, routes, schema, provider access or production action.

---

## Rolling plan self-review

- [x] Existing Tasks 1–15 remain in the same document and retain historical
  implementation details.
- [x] Completed code, remote delivery, production activation and real-device
  evidence are separate statuses.
- [x] Initial Tasks 16–19 are finite, ordered and blocked honestly where
  external prerequisites are absent.
- [x] `Task 20+` has no artificial ceiling and is created only from dated
  evidence through Definition of Ready.
- [x] Permanent lanes cover correctness, security/privacy, browser/platform,
  media capabilities, progress, UX/a11y/localization, performance, provider
  reliability, integration, maintenance and operations.
- [x] No placeholder, fake capability, invented package/update, provider URL,
  production claim or destructive instruction was added.
- [x] No route, schema, cache, queue, service worker, dependency, runtime or
  application behavior changes in this planning update.
