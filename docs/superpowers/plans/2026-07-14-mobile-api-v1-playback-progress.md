# Mobile API v1 Playback and Progress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать безопасное создание mobile playback sessions, short-lived opaque same-origin delivery grants и canonical verified-user progress endpoint без raw provider URLs в JSON.

**Architecture:** `MobilePlaybackSessionService` выбирает media через существующие playback/entitlement services. Новый encrypted grant связывает delivery URL с media, optional user и expiry; signed delivery controller восстанавливает identity и повторно вызывает `CatalogPlaybackSourceResolver::response()`. Progress продолжает использовать существующий encrypted progress session и sequence/trusted-duration rules.

**Tech Stack:** PHP 8.5, Laravel 13.19 URL signing/encryption/Sanctum, existing HLS/Plyr playback domain, PHPUnit 12.5, SQLite.

## Global Constraints

- Выполнить foundation, public catalog, auth/account и user-state plans.
- Raw `playback_url`, `path`, `source_url`, storage disk, provider key и grant payload не возвращать в JSON/logs.
- Guest playback разрешён только для public releases; authenticated audience требует valid Bearer на session creation.
- Email verification не требуется для просмотра authenticated content, но требуется для выдачи progress session и записи progress.
- Delivery URL same-origin, signed, opaque, short-lived, `private, no-store`; Bearer token не помещать в query string.
- Повторно проверять title/season/episode/media hierarchy и entitlement при session creation и delivery.
- Existing `/playback/{licensedMedia}` web route/behavior сохранить.
- Progress trusts server media duration and rejects tampered/expired/cross-user/stale events.
- No video downloads or local transcoding.
- Все новые маршруты вставлять перед named `api.fallback`, который остаётся последним statement в `routes/api.php`.
- TDD, focused security tests, frequent commits.

---

### Task 1: Add encrypted mobile playback grants

**Files:**
- Create: `app/DTOs/MobilePlaybackGrantData.php`
- Create: `app/Services/Catalog/MobilePlaybackGrant.php`
- Create: `tests/Unit/MobilePlaybackGrantTest.php`

**Interfaces:**
- Produces `issue(?User $user, LicensedMedia $media, CarbonInterface $expiresAt): string`.
- Produces `resolve(string $grant, LicensedMedia $media): ?MobilePlaybackGrantData`.

- [ ] **Step 1: Write failing grant unit tests**

Create tests under Laravel `Tests\TestCase` with `RefreshDatabase`. Assert valid guest/user grants resolve exact ids/expiry, while empty, oversized, tampered, expired, wrong-media and deleted-user-reference cases are rejected by the consuming service/controller boundary.

Core assertion:

```php
$expiresAt = now()->addMinutes(5);
$grant = $service->issue($user, $media, $expiresAt);
$resolved = $service->resolve($grant, $media);

$this->assertSame($user->id, $resolved?->userId);
$this->assertSame($media->id, $resolved?->mediaId);
$this->assertSame($expiresAt->getTimestamp(), $resolved?->expiresAt);
$this->assertNull($service->resolve($grant, $otherMedia));
```

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Unit/MobilePlaybackGrantTest.php
```

Expected: FAIL because classes do not exist.

- [ ] **Step 3: Implement immutable DTO and encrypted service**

DTO:

```php
final readonly class MobilePlaybackGrantData
{
    public function __construct(
        public ?int $userId,
        public int $mediaId,
        public int $expiresAt,
    ) {}
}
```

Issue payload contains only version, nullable user id, media id and expiry:

```php
return Crypt::encryptString(json_encode([
    'v' => 1,
    'u' => $user?->id,
    'm' => $media->id,
    'x' => $expiresAt->getTimestamp(),
], JSON_THROW_ON_ERROR));
```

`resolve()` caps grant length at 4096, catches every decrypt/JSON throwable, validates `v===1`, integer media/expiry, nullable positive user id, `mediaId === $media->id`, and `expiresAt >= now()->timestamp`. Return null for every failure without logging plaintext.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test tests/Unit/MobilePlaybackGrantTest.php
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/DTOs/MobilePlaybackGrantData.php app/Services/Catalog/MobilePlaybackGrant.php tests/Unit/MobilePlaybackGrantTest.php
git commit -m "feat: add opaque mobile playback grants"
```

---

### Task 2: Create mobile playback sessions and safe Resources

**Files:**
- Create: `app/DTOs/MobilePlaybackSessionData.php`
- Create: `app/Services/Catalog/MobilePlaybackSessionService.php`
- Create: `app/Http/Requests/Api/V1/CreatePlaybackSessionRequest.php`
- Create: `app/Http/Controllers/Api/V1/PlaybackSessionController.php`
- Create: `app/Http/Resources/Api/V1/PlaybackSessionResource.php`
- Create: `app/Http/Resources/Api/V1/EpisodeNavigationResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/PlaybackSessionTest.php`

**Interfaces:**
- Produces `MobilePlaybackSessionService::create(CatalogTitle $title, ?User $user, ?int $episodeId, ?int $mediaId, PlaybackPreferencesData $preferences): MobilePlaybackSessionData`.
- Produces `POST /api/v1/titles/{titleSlug}/playback-sessions` (the public URL segment remains the title slug).

- [ ] **Step 1: Write failing creation tests**

Create public/authenticated/hidden title graphs and multiple media variants. Assert guest public session is 201, authenticated title is 401 for guest and 201 for valid user, invalid Bearer is 401, exact preferred quality/format/variant is selected, foreign episode/media is 404, hidden/future/failed media is unavailable, and response contains only same-origin URL plus safe profile.

Assert verified response has `progress_session_token`; guest and unverified response omit it:

```php
$response->assertCreated()
    ->assertJsonPath('data.status', 'ready')
    ->assertJsonPath('data.media.quality', '1080p')
    ->assertJsonPath('data.playback_url', fn (string $url): bool => str_starts_with($url, url('/api/v1/playback/')))
    ->assertJsonMissingPath('data.media.playback_url')
    ->assertDontSee($rawProviderUrl, false);
```

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/PlaybackSessionTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement validated request**

Rules:

```php
'episode_id' => ['nullable', 'integer', 'min:1'],
'media_id' => ['nullable', 'integer', 'min:1'],
'variant' => ['nullable', 'string', 'max:120'],
'audio_language' => ['nullable', 'string', 'max:80'],
'quality' => ['nullable', 'string', Rule::in((array) config('playback.supported_qualities', []))],
'format' => ['nullable', 'string', Rule::in((array) config('playback.allowed_formats', []))],
```

Expose nullable typed accessors; do not use global `exists` rules because service must enforce parent visibility without leaking other ids.

- [ ] **Step 4: Implement session DTO/service**

Service flow:

1. Re-resolve title through `CatalogTitlePlaybackQuery::visibleTitle($title->id, $user)`.
2. Resolve optional episode via `watchableEpisode($title, $user, $episodeId)`; abort 404 when requested but absent.
3. Call `CatalogPlaybackSourceResolver::resolve()` with exact requested media id and preferences.
4. For non-ready status, return DTO carrying status/message only; controller maps `status->httpStatus()`.
5. Re-fetch selected media through `findAvailableMedia()` and assert its title/episode hierarchy.
6. Generate expiry from configured TTL, encrypted grant, and `URL::temporarySignedRoute('api.v1.playback.source', $expiresAt, ['licensedMedia' => $media->id, 'grant' => $grant])`.
7. Build previous/next navigation via `episodeNavigation()` when an episode exists.
8. Issue existing `CatalogPlaybackProgressSession` only when `$user?->hasVerifiedEmail() === true` and episode/media exist.

`MobilePlaybackSessionData` contains status/message, selected models/public media profile, same-origin playback URL, MIME/format/quality/variant, expiresAt, navigation and nullable progress token.

- [ ] **Step 5: Implement controller/resource**

Controller gets optional Sanctum user from middleware, creates DTO, and returns Resource with 201 for ready or `ApiErrorResponse` using playback status value/message/http status for blocked state. Resource explicitly enumerates safe selected objects and never serializes DTO/model recursively.

- [ ] **Step 6: Register route and run GREEN**

Register `POST /api/v1/titles/{titleSlug}/playback-sessions` under `auth.optional.sanctum` with a raw string route parameter; resolve it through `visibleTo($request->user())` only after optional authentication. The optional middleware enforces `mobile:read` when a token is present. Do not apply public cache or general throttle. Run:

```bash
php artisan test tests/Feature/Api/V1/PlaybackSessionTest.php tests/Feature/SecurityHardeningTest.php --filter='playback|entitlement'
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run Pint and commit:

```bash
git commit -m "feat: create mobile playback sessions"
```

---

### Task 3: Add signed same-origin playback delivery

**Files:**
- Create: `app/Http/Controllers/Api/V1/PlaybackSourceController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/PlaybackDeliveryTest.php`

**Interfaces:**
- Produces signed `GET /api/v1/playback/{licensedMedia}` route `api.v1.playback.source`.
- Consumes `MobilePlaybackGrant::resolve()` and existing `CatalogPlaybackSourceResolver::response()`.

- [ ] **Step 1: Write failing delivery security tests**

Create session through the real API, extract playback URL and GET it. Assert allowed external source returns 302 to the fixture provider only at delivery time and headers contain private/no-store, no-referrer, nosniff. Assert unsigned, expired signature, missing/tampered grant, other media id, deleted user and entitlement revoked after session creation return 403/404 without provider URL/body leakage. Assert copying an authenticated grant to a different media fails.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/PlaybackDeliveryTest.php
```

Expected: FAIL because signed route/controller is missing.

- [ ] **Step 3: Implement delivery controller**

Controller:

```php
public function __invoke(
    Request $request,
    LicensedMedia $licensedMedia,
    MobilePlaybackGrant $grants,
    CatalogPlaybackSourceResolver $sources,
): Response {
    $grant = $grants->resolve((string) $request->query('grant'), $licensedMedia);
    abort_if($grant === null, 403);

    $user = $grant->userId === null
        ? null
        : User::query()->find($grant->userId);
    abort_if($grant->userId !== null && ! $user instanceof User, 403);

    return $sources->response($licensedMedia, $user);
}
```

Register numeric media route with `signed` middleware. The grant, not a Bearer header, authorizes this short-lived delivery request. Preserve the existing web playback controller and route unchanged.

- [ ] **Step 4: Run GREEN and both playback route regressions**

```bash
php artisan test tests/Feature/Api/V1/PlaybackDeliveryTest.php tests/Feature/Api/V1/PlaybackSessionTest.php tests/Feature/CatalogPageTest.php --filter='signed_playback|playback_source|player'
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run Pint and commit:

```bash
git commit -m "feat: deliver mobile playback through signed grants"
```

---

### Task 4: Add canonical progress recording endpoint

**Files:**
- Create: `app/Http/Requests/Api/V1/RecordProgressRequest.php`
- Create: `app/Http/Controllers/Api/V1/PlaybackProgressController.php`
- Create: `app/Http/Resources/Api/V1/EpisodeProgressResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/PlaybackProgressTest.php`

**Interfaces:**
- Produces `PUT /api/v1/titles/{titleSlug}/episodes/{episode}/progress` protected by `auth:sanctum`, `abilities:mobile:write`, and `verified.api`.
- Consumes `CatalogUserStateService::recordProgress()` unchanged.

- [ ] **Step 1: Write failing progress matrix**

Create a playback session via API to obtain a real progress token. Assert first event creates progress, duplicate sequence is idempotent, larger sequence advances, older session/out-of-order event cannot overwrite, completion uses server duration, replay does not clear completion, `ended` handles missing duration, and tampered/expired/cross-user/foreign episode/negative/oversized values are rejected. Assert unverified user gets `email_not_verified`.

Canonical success shape:

```php
$this->putJson("/api/v1/titles/{$title->slug}/episodes/{$episode->id}/progress", [
    'playback_session_token' => $progressToken,
    'event_sequence' => 1,
    'position_seconds' => 90,
    'reported_duration_seconds' => 600,
    'ended' => false,
])->assertOk()
    ->assertJsonPath('data.position_seconds', 90)
    ->assertJsonPath('data.duration_seconds', 600)
    ->assertJsonPath('data.completed', false);
```

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/PlaybackProgressTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement Form Request and controller**

Request rules mirror existing service bounds:

```php
'playback_session_token' => ['required', 'string', 'max:2048'],
'event_sequence' => ['required', 'integer', 'min:1'],
'position_seconds' => ['required', 'integer', 'min:0', 'max:'.config('playback.progress.max_duration_seconds')],
'reported_duration_seconds' => ['required', 'integer', 'min:0', 'max:'.config('playback.progress.max_duration_seconds')],
'ended' => ['required', 'boolean'],
```

Controller resolves the raw `{titleSlug}` through `visibleTo($request->user())` after Sanctum authentication and passes the numeric route episode plus validated values to `recordProgress()`. If service returns null, return stable 422 code `invalid_playback_progress` and message `Событие просмотра отклонено.`. Otherwise return explicit Progress Resource.

Resource returns ids, position/duration/percent, first/last timestamps, completed boolean/timestamp and no session id/token/media source.

- [ ] **Step 4: Register route and run GREEN**

Register exact `PUT /api/v1/titles/{titleSlug}/episodes/{episode}/progress` with numeric `episode` constraint and middleware `auth:sanctum,abilities:mobile:write,verified.api`.

```bash
php artisan test tests/Feature/Api/V1/PlaybackProgressTest.php tests/Feature/CatalogPageTest.php --filter='record_progress|persistent_playback|completion'
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run Pint and commit:

```bash
git commit -m "feat: record mobile playback progress"
```

---

### Task 5: Complete playback privacy, OpenAPI, docs, and final verification

**Files:**
- Modify: `resources/api/openapi.json`
- Modify: `tests/Feature/Api/V1/PlaybackSessionTest.php`
- Modify: `tests/Feature/Api/V1/PlaybackDeliveryTest.php`
- Modify: `tests/Feature/Api/V1/PlaybackProgressTest.php`
- Modify: `docs/api.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/security.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces the complete mobile API v1 release candidate.

- [ ] **Step 1: Add exhaustive secret and revocation tests**

Seed unique markers into playback_url/path/source_url/storage disk/health error and encrypted tokens. Assert no session/progress/error/OpenAPI response contains them. Revoke title/season/episode/media availability between session creation and delivery/progress and assert the second boundary fails closed.

- [ ] **Step 2: Add cache/header/status tests**

Assert session/progress are private/no-store; delivery is private/no-store/no-referrer/nosniff; signed failures are JSON-safe where API renderer owns them; availability enum maps exactly to documented 401/402/403/404/410/425/451/503 statuses.

- [ ] **Step 3: Expand OpenAPI and documentation**

Document playback request preferences, safe response, grant expiry, status codes, delivery semantics, progress sequence contract and verification requirement. Explicitly state that provider redirects occur only after signed delivery and raw URLs never appear in JSON.

- [ ] **Step 4: Run complete verification**

```bash
php artisan project:docs-refresh --check
composer audit
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/MobilePlaybackGrantTest.php tests/Feature/Api/V1 tests/Feature/ApiCatalogTitleTest.php tests/Feature/CatalogPageTest.php tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/SecurityHardeningTest.php
php artisan test
./vendor/bin/phpunit
```

Expected: every command PASS with no warnings. Run `npm run build` only if implementation changed frontend assets/Blade assumptions; pure API work does not require it.

- [ ] **Step 5: Review routes and production-safe migration state**

```bash
php artisan route:list --path=api
php artisan migrate:status
git diff --check
git status --short --branch
```

Expected: every planned v1 route exists, legacy routes remain, the additive Sanctum migration is the only new API schema migration, and branch is `main`.

- [ ] **Step 6: Commit final API contract**

```bash
git add resources/api/openapi.json tests/Feature/Api/V1 docs/api.md docs/architecture.md docs/authorization.md docs/security.md docs/DATA_RELATIONS.md docs/frontend.md docs/testing.md README.md CHANGELOG.md
git commit -m "docs: finalize mobile API v1 contract"
git status --short --branch
```

Expected: clean worktree; mobile API v1 is fully implemented and verified.
