# Playback Progress Controller Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move mobile playback-progress title resolution and event assembly out of the HTTP controller while preserving the existing canonical writer and exact API contract.

**Architecture:** Add one immutable request-event DTO and one `App\Services\Catalog\Api\V1` recorder. The recorder resolves the title through the existing viewer-aware query and adapts the DTO to `CatalogUserStateService::recordProgress()`; the controller retains authentication extraction and HTTP response mapping.

**Tech Stack:** PHP 8.5, Laravel 13.19, Sanctum, Laravel Form Requests/API Resources, Pint 1.29, Larastan.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Preserve every unrelated working-tree change and stage only exact files/hunks owned by this increment.
- Do not change routes, middleware, abilities, rate limits, request/response fields, status/error codes, token format, domain writer semantics, schema, cache keys, sync behavior, or stored data.
- Reuse `CatalogTitleQuery` and `CatalogUserStateService`; do not create a second progress architecture.
- Keep controllers thin and HTTP-aware; keep application services independent of `Request`, `JsonResponse`, Resources and response headers.
- Use strict types and typed signatures; no dependency or environment change.
- Task 12 explicitly prohibits creating or running automated tests for this work. Verification is limited to syntax, exact-file formatting, scoped static analysis, route inspection, DI/container probes and manual equivalence review.

---

### Task 1: Add the typed progress event boundary

**Files:**
- Create: `app/DTOs/PlaybackProgressInput.php`
- Create: `app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php`

**Interfaces:**
- Consumes: `CatalogTitleQuery::visibleTo(?User $user)`, `CatalogUserStateService::recordProgress(User $user, CatalogTitle $catalogTitle, int $episodeId, string $playbackSessionToken, int $eventSequence, int $positionSeconds, int $reportedDurationSeconds, bool $ended): ?EpisodeViewProgress`.
- Produces: immutable `PlaybackProgressInput`; `PlaybackProgressRecorder::record(User $user, string $titleSlug, int $episodeId, PlaybackProgressInput $input): ?EpisodeViewProgress`.

- [x] **Step 1: Create the immutable input DTO**

Create `app/DTOs/PlaybackProgressInput.php` with exactly this transport-only shape:

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackProgressInput
{
    public function __construct(
        public string $playbackSessionToken,
        public int $eventSequence,
        public int $positionSeconds,
        public int $reportedDurationSeconds,
        public bool $ended,
    ) {}
}
```

- [x] **Step 2: Create the application recorder**

Create `app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\DTOs\PlaybackProgressInput;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;

final readonly class PlaybackProgressRecorder
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogUserStateService $states,
    ) {}

    public function record(
        User $user,
        string $titleSlug,
        int $episodeId,
        PlaybackProgressInput $input,
    ): ?EpisodeViewProgress {
        $title = $this->titles->visibleTo($user)
            ->where('slug', $titleSlug)
            ->firstOrFail();

        return $this->states->recordProgress(
            $user,
            $title,
            $episodeId,
            $input->playbackSessionToken,
            $input->eventSequence,
            $input->positionSeconds,
            $input->reportedDurationSeconds,
            $input->ended,
        );
    }
}
```

- [x] **Step 3: Run exact-file syntax checks**

Run:

```bash
php -l app/DTOs/PlaybackProgressInput.php
php -l app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php
```

Expected: both commands report `No syntax errors detected`.

### Task 2: Thin the API controller without changing transport behavior

**Files:**
- Modify: `app/Http/Controllers/Api/V1/PlaybackProgressController.php`

**Interfaces:**
- Consumes: `PlaybackProgressInput::__construct(...)`; `PlaybackProgressRecorder::record(...)`; existing `ApiErrorResponse` and `EpisodeProgressResource`.
- Produces: the unchanged `PUT /api/v1/titles/{titleSlug}/episodes/{episode}/progress` success and rejection responses.

- [x] **Step 1: Replace query/domain dependencies with the recorder**

Change imports and invocation so the complete controller is:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\PlaybackProgressInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordProgressRequest;
use App\Http\Resources\Api\V1\EpisodeProgressResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Catalog\Api\V1\PlaybackProgressRecorder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class PlaybackProgressController extends Controller
{
    public function __invoke(
        RecordProgressRequest $request,
        string $titleSlug,
        int $episode,
        PlaybackProgressRecorder $progressRecorder,
        ApiErrorResponse $errors,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $progress = $progressRecorder->record(
            $user,
            $titleSlug,
            $episode,
            new PlaybackProgressInput(
                playbackSessionToken: $request->playbackSessionToken(),
                eventSequence: $request->eventSequence(),
                positionSeconds: $request->positionSeconds(),
                reportedDurationSeconds: $request->reportedDurationSeconds(),
                ended: $request->ended(),
            ),
        );

        if ($progress === null) {
            return $errors->make(
                $request,
                'invalid_playback_progress',
                'Событие просмотра отклонено.',
                422,
            );
        }

        return (new EpisodeProgressResource($progress))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }
}
```

- [x] **Step 2: Verify the controller is transport-only**

Run:

```bash
rg -n "CatalogTitleQuery|CatalogUserStateService|->visibleTo\(|->where\(|->recordProgress\(" app/Http/Controllers/Api/V1/PlaybackProgressController.php
```

Expected: no matches and exit status `1`; the controller contains only authentication extraction, DTO assembly, recorder delegation and response selection.

- [x] **Step 3: Run the controller syntax check**

Run:

```bash
php -l app/Http/Controllers/Api/V1/PlaybackProgressController.php
```

Expected: `No syntax errors detected`.

### Task 3: Verify formatting, types, route and container wiring

**Files:**
- Verify only: `app/DTOs/PlaybackProgressInput.php`
- Verify only: `app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php`
- Verify only: `app/Http/Controllers/Api/V1/PlaybackProgressController.php`
- Inspect unchanged: `app/Http/Requests/Api/V1/RecordProgressRequest.php`
- Inspect unchanged: `app/Services/Catalog/CatalogUserStateService.php`
- Inspect unchanged: `routes/api.php`

**Interfaces:**
- Consumes: Laravel container autowiring and named route `api.v1.titles.episodes.progress.update`.
- Produces: verification evidence only; no source mutation other than Pint formatting of the three exact owned PHP files.

- [x] **Step 1: Format only the owned PHP files**

Run:

```bash
./vendor/bin/pint --format agent app/DTOs/PlaybackProgressInput.php app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php app/Http/Controllers/Api/V1/PlaybackProgressController.php
```

Expected: Pint completes successfully and does not touch unrelated paths.

- [x] **Step 2: Run scoped static analysis**

Run:

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/DTOs/PlaybackProgressInput.php app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php app/Http/Controllers/Api/V1/PlaybackProgressController.php
```

Expected: `[OK] No errors`.

- [x] **Step 3: Inspect the uncached route contract**

Run:

```bash
APP_ROUTES_CACHE=/tmp/codex-seasonvar-no-route-cache.php php artisan route:list --path=api/v1/titles --name=api.v1.titles.episodes.progress.update --json
```

Expected: one `PUT` route to `PlaybackProgressController` with `auth:sanctum`, `abilities:mobile:write`, `verified.api` and `throttle:api-playback-progress`.

- [x] **Step 4: Resolve dependencies through the booted container**

Run:

```bash
APP_ROUTES_CACHE=/tmp/codex-seasonvar-no-route-cache.php php artisan tinker --execute="app(App\\Services\\Catalog\\Api\\V1\\PlaybackProgressRecorder::class); app(App\\Http\\Controllers\\Api\\V1\\PlaybackProgressController::class); dump('playback-progress-boundary-ok');"
```

Expected: `playback-progress-boundary-ok` and no container exception.

- [x] **Step 5: Perform the manual equivalence review**

Confirm from the diff that:

- the same five `RecordProgressRequest` accessors feed the same writer fields;
- `$user`, `$titleSlug` and `$episode` remain server-owned inputs;
- nullable writer result still maps only to `invalid_playback_progress`/`422`;
- success still uses `EpisodeProgressResource` and `private, no-store`;
- the canonical writer, offline sync path, request rules, routes and schema are unchanged;
- no token is logged, cached, returned, or stored by the new DTO/service.

### Task 4: Document the verified increment and publish only owned changes

**Files:**
- Modify: `docs/architecture.md` (mobile API controller/service boundary paragraph)
- Modify: `docs/plans/laravel-video-portal-modernization.md` (Phase 5.3 verified increment)
- Modify: `CHANGELOG.md` (English entry under `2026-07-16`)
- Modify: `docs/superpowers/plans/2026-07-16-playback-progress-boundary.md` (checkboxes and final evidence)

**Interfaces:**
- Consumes: actual verification outputs from Task 3.
- Produces: architecture ownership, living-plan evidence, changelog record and a completed execution checklist.

- [x] **Step 1: Update architecture ownership**

Extend the existing mobile API controller paragraph to state that `PlaybackProgressRecorder` owns viewer-visible title resolution and typed HTTP-event adaptation while `CatalogUserStateService` remains the canonical writer. Do not copy the full playback security contract into this file.

- [x] **Step 2: Add Phase 5.3 evidence**

Add one verified increment paragraph after the comment direct-link increment. Record the three-file boundary, preserved route/API/domain behavior, exact verification commands/results, and that no automated tests or fixture writes were performed under Task 12.

- [x] **Step 3: Add the English changelog entry**

Add one English bullet under `## 2026-07-16` describing the thin controller, immutable input, focused recorder, preserved trusted-session/domain writer behavior, and absence of route/schema/API/data changes.

- [x] **Step 4: Re-run documentation and diff checks**

Run:

```bash
php artisan project:docs-refresh --check
git diff --check
git diff -- app/DTOs/PlaybackProgressInput.php app/Services/Catalog/Api/V1/PlaybackProgressRecorder.php app/Http/Controllers/Api/V1/PlaybackProgressController.php docs/architecture.md docs/plans/laravel-video-portal-modernization.md CHANGELOG.md docs/superpowers/plans/2026-07-16-playback-progress-boundary.md
```

Expected: docs check succeeds, no whitespace errors, and the diff contains only the described boundary and documentation hunks.

- [x] **Step 5: Commit and publish with concurrency safety**

Before each commit/publish, verify `main`, fetch the current remote, stage only exact owned files or exact documentation hunks through an alternate index, and use compare-and-swap ref updates. If remote `main` advances, reapply only the owned content on top of the new remote tree; never overwrite or include unrelated working-tree changes.

Expected commit subjects:

```text
refactor: isolate playback progress controller boundary
docs: complete playback progress boundary plan
```

### Execution evidence

- Design was published on `main` as `4afef2b` and this implementation plan as `10afa89`.
- The three-file application boundary was published as `e208d2d`; the architecture, living-plan evidence and English changelog entry were published as `10dab6a`.
- Fresh completion verification reported no PHP syntax errors, Pint pass and scoped Larastan 0 errors.
- Uncached Laravel route inspection returned one named `PUT` route with Sanctum authentication, `mobile:write`, verified-email and `api-playback-progress` middleware; direct container resolution returned `playback-progress-boundary-ok`.
- `project:docs-refresh --check` reported current documentation, `git diff --check` was clean, and local/remote commit trees matched after each publication.
- No automated test, fixture write, migration, database mutation, cache clear, dependency update, branch or worktree was created or run for this increment, as required by Task 12 and the project workflow.

### Self-review

- Spec coverage: DTO meaning, title lookup ownership, canonical writer reuse, HTTP separation, route/API/security compatibility, rollback and no-test verification all have explicit tasks.
- Placeholder scan: no deferred implementation or unspecified code step remains.
- Type consistency: `PlaybackProgressInput` property names and `PlaybackProgressRecorder::record()` signature are identical in Tasks 1 and 2; the controller uses those exact names.
- Scope: offline sync and `CatalogUserStateService::recordProgress()` deliberately remain unchanged because the spec identifies them as the proven canonical boundary.

## Execution choice

The user's standing instruction authorizes the recommended implementation without another question. Execute inline in this session with `superpowers:executing-plans`; subagents, branches and worktrees are prohibited by the active project instructions.
