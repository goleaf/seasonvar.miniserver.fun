# Mobile API v1 Authentication and Account Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать открытую регистрацию, email verification, login/password reset, 90-day Sanctum token/device lifecycle и безопасное управление `/me` для мобильного приложения.

**Architecture:** Form Requests валидируют каждый auth/account input; `MobileAuthenticationService` и `MobileAccountService` выполняют транзакционные операции. Sanctum хранит hashed personal tokens с ограниченными abilities. Project-owned queued notifications создают API verification/reset links, а отдельные named limiters защищают только credential endpoints.

**Tech Stack:** PHP 8.5, Laravel 13.19 Authentication/Password Broker/Notifications, Sanctum 4.x, PHPUnit 12.5, SQLite.

## Global Constraints

- Сначала выполнить foundation и public-catalog plans.
- Только `main`; новая auth dependency кроме уже согласованного Sanctum запрещена.
- Registration input: `name`, `email`, `password`, `password_confirmation`, `device_name`.
- Email verification обязательно только для state mutations/progress; authenticated private reads остаются доступны после смены email.
- Token expiry — 90 дней; token plaintext возвращается только register/login/refresh.
- Password/reset/verification/Bearer tokens никогда не логировать и не возвращать после issuance.
- Auth errors не раскрывают существование email; видимые messages русские.
- Notifications queue after commit; notification delivery tests use `Notification::fake()`, while queue execution tests use `Queue::fake()`.
- Admin/import abilities/routes не выдавать mobile token.
- Все новые маршруты вставлять перед named `api.fallback`, который остаётся последним statement в `routes/api.php`.
- RED → GREEN → REFACTOR и commit после каждого testable deliverable.

---

### Task 1: Add registration, login, UserResource, and auth limiters

**Files:**
- Create: `app/Providers/ApiServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `app/Models/User.php`
- Create: `app/Services/Auth/MobileAuthenticationService.php`
- Create: `app/Http/Requests/Api/V1/RegisterRequest.php`
- Create: `app/Http/Requests/Api/V1/LoginRequest.php`
- Create: `app/Http/Controllers/Api/V1/Auth/RegisterController.php`
- Create: `app/Http/Controllers/Api/V1/Auth/LoginController.php`
- Create: `app/Http/Resources/Api/V1/UserResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/AuthenticationTest.php`

**Interfaces:**
- Produces `MobileAuthenticationService::register(array $attributes): array{user: User, token: string, expires_at: CarbonInterface}`.
- Produces `login(string $email, string $password, string $deviceName): array{...}`.
- Produces named limiters `mobile-register` and `mobile-login`.

- [ ] **Step 1: Write failing registration/login tests**

Create `AuthenticationTest` with `RefreshDatabase`:

```php
public function test_user_registers_with_normalized_email_and_receives_one_plain_token(): void
{
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => '  Иван   Иванов  ',
        'email' => 'IVAN@EXAMPLE.COM ',
        'password' => 'Very-Strong-Password-42',
        'password_confirmation' => 'Very-Strong-Password-42',
        'device_name' => 'Pixel 9',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ivan@example.com')
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    $user = User::query()->where('email', 'ivan@example.com')->firstOrFail();
    $this->assertTrue(Hash::check('Very-Strong-Password-42', $user->password));
    $this->assertNotSame($response->json('data.token'), $user->tokens()->value('token'));
}

public function test_login_uses_one_generic_credential_error_and_records_device(): void
{
    $user = User::factory()->create(['email' => 'user@example.com']);

    foreach ([
        ['email' => 'missing@example.com', 'password' => 'password'],
        ['email' => $user->email, 'password' => 'wrong-password'],
    ] as $credentials) {
        $this->postJson('/api/v1/auth/login', [...$credentials, 'device_name' => 'iPhone'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'Указаны неверные данные для входа.');
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone 16',
    ])->assertOk()->assertJsonPath('data.user.id', $user->id);

    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 16']);
}
```

Add validation tests for case-insensitive duplicate email, name/device lengths, password confirmation/strength rules, and rate limit `429` after configured attempts.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/AuthenticationTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement dedicated rate limit provider**

Create `ApiServiceProvider` and register it in `bootstrap/providers.php`. In `boot()`:

```php
RateLimiter::for('mobile-register', fn (Request $request): Limit => Limit::perMinute(5)
    ->by('register|'.$request->ip()));

RateLimiter::for('mobile-login', function (Request $request): Limit {
    $email = Str::lower(Str::squish((string) $request->input('email')));

    return Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip());
});
```

Do not add a limiter to public catalog GET routes.

- [ ] **Step 4: Implement Form Requests**

Both requests normalize with `Str::squish`; email additionally uses `Str::lower`. Registration rules:

```php
'name' => ['required', 'string', 'min:2', 'max:120'],
'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
'device_name' => ['required', 'string', 'min:2', 'max:120'],
```

Login uses required email/password/device name without leaking unique/existence validation.

Because SQLite's plain unique comparison is case-sensitive, add a Form Request `after()` validator that performs a bound `whereRaw('lower(email) = ?', [$normalizedEmail])` existence check and adds the same Russian `email` validation error. Keep `Rule::unique` as the race-safe check for normalized new writes. In `login()`, resolve the user with the same bound case-insensitive predicate rather than `where('email', ...)` so legacy mixed-case rows remain usable.

- [ ] **Step 5: Implement authentication service and controllers**

`MobileAuthenticationService` constants:

```php
private const ABILITIES = ['mobile:read', 'mobile:write'];
private const TOKEN_DAYS = 90;
```

`issueToken()`:

```php
$expiresAt = now()->addDays(self::TOKEN_DAYS);
$token = $user->createToken($deviceName, self::ABILITIES, $expiresAt);

return [
    'user' => $user,
    'token' => $token->plainTextToken,
    'expires_at' => $expiresAt,
];
```

`register()` creates the unverified User inside `DB::transaction(..., attempts: 3)`, then dispatches verification notification after commit and issues the token. `login()` selects by normalized email, uses `Hash::check`, throws `ValidationException::withMessages(['email' => ['Указаны неверные данные для входа.']])`, then issues the token.

Controllers return `{data: {user: UserResource, token, token_type: Bearer, expires_at}}` with 201/200.

- [ ] **Step 6: Register routes and run GREEN**

```php
Route::post('/auth/register', RegisterController::class)
    ->middleware('throttle:mobile-register')
    ->name('api.v1.auth.register');
Route::post('/auth/login', LoginController::class)
    ->middleware('throttle:mobile-login')
    ->name('api.v1.auth.login');
```

Run:

```bash
php artisan test tests/Feature/Api/V1/AuthenticationTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run Pint, inspect `main`, stage task files and commit:

```bash
git commit -m "feat: add mobile registration and login"
```

---

### Task 2: Add queued email verification and password reset

**Files:**
- Create: `app/Notifications/VerifyMobileEmail.php`
- Create: `app/Notifications/ResetMobilePassword.php`
- Modify: `app/Models/User.php`
- Create: `app/Http/Controllers/Api/V1/Auth/VerifyEmailController.php`
- Create: `app/Http/Controllers/Api/V1/Auth/ResendVerificationController.php`
- Create: `app/Http/Controllers/Api/V1/Auth/ForgotPasswordController.php`
- Create: `app/Http/Controllers/Api/V1/Auth/ResetPasswordController.php`
- Create: `app/Http/Requests/Api/V1/ForgotPasswordRequest.php`
- Create: `app/Http/Requests/Api/V1/ResetPasswordRequest.php`
- Modify: `app/Providers/ApiServiceProvider.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php`

**Interfaces:**
- User implements `MustVerifyEmail` and overrides both notification methods.
- Produces `mobile-forgot-password`, `mobile-reset-password`, `mobile-verification` named limiters.

- [ ] **Step 1: Write failing verification tests**

Use `Notification::fake()` and assert registration queues/sends `VerifyMobileEmail`. Create a signed URL with `URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())])`; GET it without Bearer, assert verified and `Verified` event. Assert tampered/expired URL is 403. Assert resend requires Sanctum and rate-limits.

- [ ] **Step 2: Write failing password reset tests**

Use `Notification::fake()`. Both existing and missing email must return the exact same `200` body. Pull the reset token from the captured `ResetMobilePassword` notification, POST email/token/new confirmed password, assert password changed and all personal access tokens deleted. Invalid token returns stable 422 without raw broker status.

- [ ] **Step 3: Run RED**

```bash
php artisan test tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php
```

Expected: FAIL because User does not implement email verification and endpoints do not exist.

- [ ] **Step 4: Implement queued notifications and User hooks**

`VerifyMobileEmail extends Illuminate\Auth\Notifications\VerifyEmail implements ShouldQueue` and uses `Queueable`. `ResetMobilePassword extends Illuminate\Auth\Notifications\ResetPassword implements ShouldQueue`. In `User`:

```php
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, MustVerifyEmailBehavior, Notifiable;

    public function sendEmailVerificationNotification(): void
    {
        $this->notify((new VerifyMobileEmail)->afterCommit());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetMobilePassword((string) $token))->afterCommit());
    }
}
```

Alias imports explicitly: `Illuminate\Auth\MustVerifyEmail as MustVerifyEmailBehavior` and `Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract`.

In `VerifyMobileEmail::verificationUrl()`, generate `api.v1.auth.verify` with 60-minute temporary signature, user id and SHA-1 verification hash. In `ResetMobilePassword::resetUrl()`, generate `url('/api/v1/auth/reset-password').'?'.Arr::query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()])`; this HTTPS URL is the future universal-link target and no custom app scheme is hardcoded.

- [ ] **Step 5: Implement verification/reset controllers**

Verification controller loads `User` by route id, checks `hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))`, marks verified once and fires `Verified`. Resend calls current user's notification only when not verified.

Forgot controller always returns:

```php
return response()->json(['data' => [
    'status' => 'Если аккаунт существует, письмо для восстановления отправлено.',
]]);
```

It calls `Password::sendResetLink()` but does not branch response on returned status. Reset uses `Password::reset()`, hashes password, rotates remember token, fires `PasswordReset`, then deletes `$user->tokens()`.

- [ ] **Step 6: Register routes/limiters and run GREEN**

Register exact signed route `GET /api/v1/auth/email/verify/{id}/{hash}` named `api.v1.auth.verify`; `POST /api/v1/auth/email/verification-notification` under `auth:sanctum` and `throttle:mobile-verification`; `POST /api/v1/auth/forgot-password` with `throttle:mobile-forgot-password`; and `POST /api/v1/auth/reset-password` with `throttle:mobile-reset-password`. Configure forgot/reset as 3 attempts per 10 minutes keyed by normalized email plus IP, and verification as 3 attempts per minute keyed by user id. The signed verification URL must work from email without a Bearer header.

Run:

```bash
php artisan test tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php tests/Feature/Api/V1/AuthenticationTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run Pint and commit:

```bash
git commit -m "feat: add mobile account verification and recovery"
```

---

### Task 3: Add token rotation, logout, and device management

**Files:**
- Create: `app/Services/Auth/MobileTokenService.php`
- Create: `app/Http/Controllers/Api/V1/Auth/TokenController.php`
- Create: `app/Http/Resources/Api/V1/DeviceTokenResource.php`
- Modify: `app/Providers/ApiServiceProvider.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/DeviceTokenManagementTest.php`

**Interfaces:**
- Produces `rotate(User $user, PersonalAccessToken $current): array{token: string, expires_at: CarbonInterface}`.
- Produces `revoke(User $user, int $tokenId): void` owner-scoped.

- [ ] **Step 1: Write failing device lifecycle tests**

Create real tokens with `$user->createToken()` and call API using their plain token. Assert device list returns only current user's token id/name/last_used_at/expires_at/current boolean and no hash. Assert refresh returns a new plaintext token, deletes old row and leaves new valid. Assert logout current deletes only current, logout-all deletes all, and deleting another user's token id returns 404 without deleting it.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/DeviceTokenManagementTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement token service**

`rotate()` requires `$current instanceof PersonalAccessToken`, then in `DB::transaction` creates a token with the current name, abilities and 90-day expiry, deletes current only after creation, and returns plaintext/expiry. `revoke()` queries `$user->tokens()->whereKey($tokenId)->firstOrFail()->delete()`.

`DeviceTokenResource` explicitly omits `token` and computes `current` by comparing id with `$request->user()->currentAccessToken()?->id`.

- [ ] **Step 4: Implement controller methods and rate limiter**

`index`, `refresh`, `logout`, `logoutAll`, `destroy` are protected by `auth:sanctum` and `abilities:mobile:read`; destructive token operations additionally require `abilities:mobile:write`. Refresh also uses `throttle:mobile-token-refresh` at 20/min by current token id. Return 204 for revoke/logout and 200 with token only for refresh.

Register exact routes: `GET /auth/devices`, `DELETE /auth/devices/{token}`, `POST /auth/token/refresh`, `POST /auth/logout`, and `POST /auth/logout-all`, all inside the `/api/v1` group.

- [ ] **Step 5: Run GREEN and commit**

```bash
php artisan test tests/Feature/Api/V1/DeviceTokenManagementTest.php
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/Services/Auth/MobileTokenService.php app/Http/Controllers/Api/V1/Auth/TokenController.php app/Http/Resources/Api/V1/DeviceTokenResource.php app/Providers/ApiServiceProvider.php routes/api.php tests/Feature/Api/V1/DeviceTokenManagementTest.php
git commit -m "feat: manage mobile API device tokens"
```

---

### Task 4: Add profile, password change, email reverification, and account deletion

**Files:**
- Create: `app/Services/Auth/MobileAccountService.php`
- Create: `app/Http/Controllers/Api/V1/AccountController.php`
- Create: `app/Http/Requests/Api/V1/UpdateProfileRequest.php`
- Create: `app/Http/Requests/Api/V1/UpdatePasswordRequest.php`
- Create: `app/Http/Requests/Api/V1/DeleteAccountRequest.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/AccountManagementTest.php`

**Interfaces:**
- Produces `updateProfile(User $user, array{name?: string, email?: string} $data): User`.
- Produces `updatePassword(User $user, string $current, string $new, ?int $currentTokenId): void`.
- Produces `delete(User $user, string $password): void`.

- [ ] **Step 1: Write failing `/me` tests**

Assert `GET /me` needs token and returns UserResource. Assert name change retains verification. Email change lowercases email, clears `email_verified_at`, sends verification, and retains private read access. Password change rejects wrong current password, changes hash and revokes every token except current. Account delete rejects wrong password, then deletes user/tokens/private state and returns 204.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/AccountManagementTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement Form Requests and service**

Profile uses `sometimes` name/email rules and `Rule::unique(User::class, 'email')->ignore($request->user())`. Its `after()` validator also performs a bound `lower(email)` lookup excluding the current numeric user id, so a legacy differently-cased address cannot be claimed. Password request uses `current_password` plus the same 12-character strong confirmed new-password rule. Delete request uses required `password` and returns one generic validation error when Hash check fails.

`updateProfile()` compares normalized email before fill; on change sets `email_verified_at=null`, saves, and notifies after commit. `updatePassword()` transactionally updates hashed password/remember token and deletes `tokens()->where('id', '!=', $currentTokenId)`. `delete()` verifies Hash, then transactionally deletes tokens and User; rely on declared FK cascades and test them.

- [ ] **Step 4: Register routes and run GREEN**

Register `GET /api/v1/me` under `auth:sanctum,abilities:mobile:read`; register `PATCH /api/v1/me`, `PATCH /api/v1/me/password`, and `DELETE /api/v1/me` under `auth:sanctum,abilities:mobile:write`. Do not apply `verified` because profile/reverification must remain reachable.

Run:

```bash
php artisan test tests/Feature/Api/V1/AccountManagementTest.php tests/Feature/Api/V1/DeviceTokenManagementTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run Pint and commit:

```bash
git commit -m "feat: add mobile account management"
```

---

### Task 5: Complete auth OpenAPI, security tests, and documentation

**Files:**
- Modify: `resources/api/openapi.json`
- Modify: `tests/Feature/LocalRateLimitRemovalTest.php`
- Modify: `tests/Feature/Api/V1/AuthenticationTest.php`
- Modify: `tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php`
- Modify: `tests/Feature/Api/V1/DeviceTokenManagementTest.php`
- Modify: `tests/Feature/Api/V1/AccountManagementTest.php`
- Modify: `docs/api.md`
- Modify: `docs/authorization.md`
- Modify: `docs/security.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces fully documented auth/account contract for user-state and playback plans.

- [ ] **Step 1: Update the local-rate-limit regression contract**

Keep assertions that public catalog routes have no throttle. Add assertions that credential routes do contain their exact named limiter. Replace the blanket documentation assertion against the substring `throttle:` with assertions against removed legacy limiter names only, so `docs/api.md` can truthfully document auth throttling.

- [ ] **Step 2: Add auth privacy matrix**

Exercise guest, invalid token, unverified, verified and cross-user device ids. Assert every auth error is private/no-store, has request id, contains no password/token/hash/SQL/stack trace, and forgot-password responses are byte-for-byte identical for existing/missing email.

- [ ] **Step 3: Expand OpenAPI and owner docs**

Add all auth/account paths, request/response schemas, Bearer security, 401/403/422/429 examples, 90-day/rotation semantics and verified boundary. Document queued email requirements without editing production `.env`.

- [ ] **Step 4: Verify**

Run:

```bash
php artisan project:docs-refresh --check
composer audit
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1 tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/RouteFallbackTest.php tests/Feature/SecurityHardeningTest.php
php artisan test
```

Expected: all PASS; no dependency advisories.

- [ ] **Step 5: Commit**

```bash
git status --short --branch
git add resources/api/openapi.json tests/Feature docs/api.md docs/authorization.md docs/security.md docs/DATA_RELATIONS.md docs/testing.md README.md CHANGELOG.md
git commit -m "test: harden mobile authentication contract"
```
