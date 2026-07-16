# Mobile Token Service Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize owner-scoped mobile device-token reads and return token rotation through an immutable typed result without changing the public API or Sanctum security behavior.

**Architecture:** Extend the existing `MobileTokenService` with the device-list query and a typed rotation result. Keep `TokenController` responsible only for request authentication, API Resource/JSON serialization, and private response headers. Preserve all routes, abilities, transactions, audit events, payload fields, and database structures.

**Tech Stack:** PHP 8.5, Laravel 13.19, Laravel Sanctum, Eloquent, PHPUnit 12.5, Laravel Pint 1.29, Larastan 3.10.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Preserve the shared real Git index and every unrelated staged, unstaged, and committed change.
- Do not add dependencies, migrations, routes, guards, middleware, cache keys, or OpenAPI fields.
- Keep token hashes, abilities, and other users' device data out of API responses.
- Keep every response `private, no-store` and preserve Sanctum `mobile:read` / `mobile:write` enforcement.
- Use RED-GREEN-REFACTOR and run the existing HTTP characterization suite after the service boundary turns green.
- Commit only the exact files named by each task; publish equivalent fast-forward commits to remote `main`.

---

## File map

- Create `app/DTOs/MobileTokenRotationResult.php`: immutable service result for the only newly issued plain-text token and its expiry.
- Modify `app/Services/Auth/MobileTokenService.php`: own owner-scoped device listing and return the rotation DTO.
- Modify `app/Http/Controllers/Api/V1/Auth/TokenController.php`: delegate the device query and read the typed result.
- Create `tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php`: direct service characterization for owner isolation, stable ordering, typed rotation, and preserved device metadata.
- Modify `docs/api.md`: document the canonical mobile-token service boundary.
- Modify `docs/plans/laravel-video-portal-modernization.md`: record this demonstrated Phase 5.3 increment without marking the repository-wide audit complete.
- Modify `CHANGELOG.md`: add one release-note bullet in the current unreleased section using the repository's current language policy.

### Task 1: Add the failing service-boundary contract

**Files:**
- Create: `tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php`
- Characterization: `tests/Feature/Api/V1/DeviceTokenManagementTest.php`

**Interfaces:**
- Consumes: existing `MobileTokenService::rotate(User $user, PersonalAccessToken $current): array` and existing Sanctum token relationships.
- Produces: a failing contract for `MobileTokenService::devices(User $user): Collection` and `MobileTokenRotationResult`.

- [ ] **Step 1: Run the existing HTTP characterization suite before production changes**

Run:

```bash
php artisan test tests/Feature/Api/V1/DeviceTokenManagementTest.php
```

Expected: 5 tests pass; device listing, rotation, logout, owner isolation, abilities, and response headers are green before refactoring.

- [ ] **Step 2: Write the focused failing test**

Create `tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\MobileTokenRotationResult;
use App\Models\User;
use App\Services\Auth\MobileTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class MobileTokenServiceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_lists_only_owner_devices_and_returns_a_typed_rotation_result(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = $user->createToken('Tablet', ['mobile:read'], now()->addDay());
        $current = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDay());
        $foreign = $otherUser->createToken('Foreign', ['mobile:read'], now()->addDay());
        $service = app(MobileTokenService::class);

        $this->assertSame(
            [$current->accessToken->id, $older->accessToken->id],
            $service->devices($user)->modelKeys(),
        );

        $result = $service->rotate($user, $current->accessToken);

        $this->assertInstanceOf(MobileTokenRotationResult::class, $result);
        $this->assertTrue($result->expiresAt->isFuture());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $older->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);

        $replacement = PersonalAccessToken::findToken($result->token);

        $this->assertNotNull($replacement);
        $this->assertSame($user->id, $replacement->tokenable_id);
        $this->assertSame('Pixel 9', $replacement->name);
        $this->assertSame(['mobile:read', 'mobile:write'], $replacement->abilities);
    }
}
```

- [ ] **Step 3: Run the new test and verify RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php
```

Expected: FAIL with `Call to undefined method App\Services\Auth\MobileTokenService::devices()`; no production file has changed yet.

- [ ] **Step 4: Commit only the failing contract**

```bash
git add tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php
git commit -m "test: define mobile token service boundary"
```

Expected: the commit contains exactly the new test file. In the shared workspace, use an alternate index and a compare-and-swap branch update so the real index and concurrent commits remain intact.

### Task 2: Add the typed service boundary

**Files:**
- Create: `app/DTOs/MobileTokenRotationResult.php`
- Modify: `app/Services/Auth/MobileTokenService.php`
- Test: `tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php`

**Interfaces:**
- Consumes: `User::tokens()`, `PersonalAccessToken`, and the existing transaction/audit behavior.
- Produces: `MobileTokenService::devices(User $user): Collection` and `MobileTokenService::rotate(User $user, PersonalAccessToken $current): MobileTokenRotationResult`.

- [ ] **Step 1: Add the immutable rotation DTO**

Create `app/DTOs/MobileTokenRotationResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonInterface;

final readonly class MobileTokenRotationResult
{
    public function __construct(
        public string $token,
        public CarbonInterface $expiresAt,
    ) {}
}
```

- [ ] **Step 2: Add owner-scoped listing and return the DTO from rotation**

In `app/Services/Auth/MobileTokenService.php`, add these imports:

```php
use App\DTOs\MobileTokenRotationResult;
use Illuminate\Database\Eloquent\Collection;
```

Add the device query immediately after the constructor:

```php
/** @return Collection<int, PersonalAccessToken> */
public function devices(User $user): Collection
{
    return $user->tokens()
        ->latest('id')
        ->get();
}
```

Replace the rotation signature and transaction return with:

```php
public function rotate(User $user, PersonalAccessToken $current): MobileTokenRotationResult
{
    $tokenId = (int) $current->getKey();

    return DB::transaction(function () use ($user, $tokenId): MobileTokenRotationResult {
        $lockedToken = $user->tokens()
            ->whereKey($tokenId)
            ->lockForUpdate()
            ->first();

        if (! $lockedToken instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $name = (string) $lockedToken->name;
        $abilities = $lockedToken->abilities ?? [];
        $lockedToken->delete();

        $expiresAt = now()->addDays(self::TOKEN_DAYS);
        $token = $user->createToken($name, $abilities, $expiresAt);

        return new MobileTokenRotationResult(
            token: $token->plainTextToken,
            expiresAt: $expiresAt,
        );
    }, attempts: 3);
}
```

- [ ] **Step 3: Run the service test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php
```

Expected: 1 test passes and verifies owner isolation, descending token IDs, replacement metadata, and typed output.

- [ ] **Step 4: Commit the service boundary**

```bash
git add app/DTOs/MobileTokenRotationResult.php app/Services/Auth/MobileTokenService.php
git commit -m "refactor: type mobile token service boundary"
```

Expected: the commit contains exactly the DTO and service files.

### Task 3: Thin the HTTP controller without changing its contract

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Auth/TokenController.php`
- Test: `tests/Feature/Api/V1/DeviceTokenManagementTest.php`
- Test: `tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php`

**Interfaces:**
- Consumes: `MobileTokenService::devices()` and `MobileTokenRotationResult::$token` / `$expiresAt`.
- Produces: the unchanged mobile devices and refresh JSON responses.

- [ ] **Step 1: Delegate listing to the service**

Replace `TokenController::index()` with:

```php
public function index(Request $request, MobileTokenService $tokens): JsonResponse
{
    return DeviceTokenResource::collection($tokens->devices($this->user($request)))
        ->response()
        ->header('Cache-Control', 'private, no-store');
}
```

- [ ] **Step 2: Read the typed rotation result**

Replace the `data` payload inside `TokenController::refresh()` with:

```php
return response()->json(['data' => [
    'token' => $result->token,
    'token_type' => 'Bearer',
    'expires_at' => $result->expiresAt->toJSON(),
]], headers: ['Cache-Control' => 'private, no-store']);
```

- [ ] **Step 3: Run the direct and HTTP gates together**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php tests/Feature/Api/V1/DeviceTokenManagementTest.php
```

Expected: 6 tests pass; all existing response fields, headers, authorization outcomes, owner isolation, and token lifecycle assertions remain green.

- [ ] **Step 4: Run formatting and scoped static analysis**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php -l app/DTOs/MobileTokenRotationResult.php
php -l app/Services/Auth/MobileTokenService.php
php -l app/Http/Controllers/Api/V1/Auth/TokenController.php
./vendor/bin/phpstan analyse --memory-limit=512M app/DTOs/MobileTokenRotationResult.php app/Services/Auth/MobileTokenService.php app/Http/Controllers/Api/V1/Auth/TokenController.php
```

Expected: Pint succeeds, every syntax check reports no errors, and Larastan reports zero errors.

- [ ] **Step 5: Commit the controller integration**

```bash
git add app/Http/Controllers/Api/V1/Auth/TokenController.php
git commit -m "refactor: thin mobile token controller"
```

Expected: the commit contains exactly the controller file.

### Task 4: Document and verify the demonstrated increment

**Files:**
- Modify: `docs/api.md`
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified test counts and final implementation names.
- Produces: one API-owner note, one Phase 5.3 evidence paragraph, and one changelog entry without duplicating domain documentation.

- [ ] **Step 1: Update the API owner document**

Under `## Mobile token foundation` in `docs/api.md`, add:

```markdown
- `MobileTokenService` is the canonical owner-scoped boundary for listing, rotating and revoking Sanctum device tokens. Rotation returns the immutable `MobileTokenRotationResult`; controllers retain only authenticated request resolution, API Resource/JSON serialization and `private, no-store`. Token hashes, abilities and foreign device rows never cross the public resource boundary.
```

- [ ] **Step 2: Record the Phase 5.3 evidence**

Append under `### 5.3 Controllers/actions/services/DTOs` in `docs/plans/laravel-video-portal-modernization.md`:

```markdown
Verified mobile-token controller increment 16.07.2026: the clean `TokenController` audit found an owner-scoped device query and an implicit token-rotation array shape in the HTTP layer. `MobileTokenService` now owns the ordered device query and returns immutable `MobileTokenRotationResult`; the controller retains authenticated request/current-token resolution, Resource/JSON serialization and `private, no-store`. Sanctum guards and abilities, routes, transactions, audit events, token lifetime, JSON fields, OpenAPI and schema are unchanged. The direct service boundary and existing HTTP characterization gates pass together. This is one demonstrated controller increment, not a claim that the repository-wide audit is complete.
```

- [ ] **Step 3: Add the changelog entry**

Add one bullet to the current unreleased section of `CHANGELOG.md`:

```markdown
- Граница мобильных Sanctum-токенов нормализована без изменения API: `MobileTokenService` теперь владеет выборкой устройств пользователя и возвращает типизированный результат ротации, а контроллер сохраняет только HTTP-оркестрацию, приватные заголовки и прежние JSON-поля.
```

- [ ] **Step 4: Run final focused verification**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php tests/Feature/Api/V1/DeviceTokenManagementTest.php tests/Feature/Api/V1/AuthenticationTest.php
git diff --check -- app/DTOs/MobileTokenRotationResult.php app/Services/Auth/MobileTokenService.php app/Http/Controllers/Api/V1/Auth/TokenController.php tests/Feature/Api/V1/MobileTokenServiceBoundaryTest.php docs/api.md docs/plans/laravel-video-portal-modernization.md CHANGELOG.md
git status --short --branch
```

Expected: 12 tests pass, diff check is clean, branch is `main`, and every unrelated shared-worktree change remains present but absent from this increment's commits.

- [ ] **Step 5: Commit documentation and publish the exact scoped history**

```bash
git add docs/api.md docs/plans/laravel-video-portal-modernization.md CHANGELOG.md
git commit -m "docs: record mobile token boundary"
git push origin main
```

Expected: local and remote `main` contain only the exact scoped commits for this increment on top of their respective existing histories. In the shared divergent workspace, publish equivalent fast-forward remote commits from the remote parent rather than merging or force-pushing unrelated local commits.
