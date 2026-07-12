# Central Playback Authorization Implementation Plan

> Living plan for inline execution on the existing `main` branch. No new test files or production dependencies.

**Goal:** Replace raw media URLs in the title player with a centralized, publication-aware source resolver and short-lived signed application URL.

**Architecture:** `CatalogPlaybackSourceResolver` owns access-state evaluation, source selection and final source responses. `PlaybackSourceUrlGuard` owns provider allowlisting and public-address validation. Livewire receives only `PlaybackSourceData`; the existing catalog query remains responsible for visible release retrieval.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite, PHPUnit 12.5.

## Global constraints

- Preserve the existing publication/audience/window scopes and locked Livewire title ID.
- Do not invent territory, profile, entitlement, subscription or concurrent-stream data that the repository does not store.
- Never place raw external playback URLs, storage paths or provider credentials in HTML, Livewire state or the safe DTO.
- Keep provider requests redirect-free, bounded by connect/request timeouts and body-free where only availability headers are required.

---

### Task 1: Lock the security contract with existing tests

**Files:**

- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/SecurityHardeningTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

- [x] Add a failing title-player assertion proving raw provider URLs are absent and a signed application URL is present.
- [x] Add failing direct-source assertions for invalid signatures, foreign episode/media pairs, guest access to authenticated media, unavailable media and successful authorized redirect.
- [x] Add failing URL-guard and availability-checker assertions for non-allowlisted/private hosts, no redirects and bounded streaming options.
- [x] Run focused tests and confirm RED failures come from the missing resolver/route.

### Task 2: Add safe playback contracts and URL policy

**Files:**

- Create: `app/Enums/PlaybackAvailability.php`
- Create: `app/DTOs/PlaybackSourceData.php`
- Create: `app/DTOs/PlaybackPreferencesData.php`
- Create: `app/Services/Media/PlaybackSourceUrlGuard.php`
- Create: `config/playback.php`
- Modify: `.env.example`

**Interfaces:**

- `PlaybackSourceData::playable(...)` contains only signed app URL, MIME type, format, quality, variant label and expiration.
- `PlaybackSourceData::blocked(PlaybackAvailability $status)` contains no URL.
- `PlaybackSourceUrlGuard::safeExternalUrl(mixed $url): ?string` requires HTTPS, an allowlisted hostname and only public resolved addresses.

- [x] Implement the smallest immutable contracts needed by the RED tests.
- [x] Configure the current `*.11cdn.org` provider family, signed URL lifetime, supported formats/qualities and provider priority.

### Task 3: Centralize authorization and source selection

**Files:**

- Create: `app/Services/Catalog/CatalogPlaybackSourceResolver.php`
- Create: `app/Http/Controllers/PlaybackSourceController.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**

- `resolve(CatalogTitle $title, ?User $user, ?Episode $episode, ?int $requestedMediaId, PlaybackPreferencesData $preferences): PlaybackSourceData`.
- `response(LicensedMedia $media, ?User $user): Response` rechecks title/season/episode/media access before redirecting or streaming.

- [x] Resolve only media belonging to the requested episode and visible title hierarchy.
- [x] Exclude known failures and unsupported formats, then rank requested media, provider priority, preferred variant/translation, quality and stable ID.
- [x] Generate a five-minute signed route and register playback throttling.
- [x] Map authentication, future window, expiry, unavailable and not-found states to clear Russian messages and safe HTTP responses.

### Task 4: Integrate Livewire and harden provider checks

**Files:**

- Modify: `app/Livewire/CatalogTitlePlayer.php`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: `app/Services/Seasonvar/SeasonvarMediaAvailabilityChecker.php`

- [x] Replace raw `selectedMediaUrl` construction with `PlaybackSourceData`.
- [x] Keep media choice/profile metadata server-side while rendering only the signed application URL to the player.
- [x] Render the resolver's Russian denial state without adding database logic to Blade.
- [x] Reuse the URL guard in availability checks, disable redirects, request only one byte and avoid reading/logging response bodies or full URLs.

### Task 5: Verify, document and publish

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/security.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this plan

- [ ] Run focused tests, relevant catalog/security/importer tests, full PHPUnit, Pint, syntax, Composer/npm audits, docs check and frontend build.
- [ ] Inspect the complete authorized diff, verify `main`, commit, push without force and confirm a clean tree.
