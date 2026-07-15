# Livewire Auth and User Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить полноценную session-based Livewire-аутентификацию, профиль, security и личную видеотеку, сохранив общий owner state и совместимость mobile API v1.

**Architecture:** Web использует Laravel `web` guard, session/CSRF и class-based Livewire 4; API продолжает использовать Sanctum bearer tokens. Transport-neutral account/library services, policies и queries являются единственным источником бизнес-правил, поэтому Web, API и offline sync не расходятся.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Sanctum 4.3, SQLite, PHPUnit 12.5, Tailwind CSS 4.3, Vite 8, Playwright.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch, worktree или PR branch.
- Не добавлять production dependencies и не редактировать `.env`.
- Следовать `docs/UI_STANDARDS.md`: светлая тема, русский UI, локальные assets/icons, no internal scroll, touch target не меньше 44×44 px.
- Не использовать Volt, inline PHP в Blade, запросы из Blade, raw media URLs или `user_id` как публичное Livewire-состояние.
- Существующие API v1 URL, envelopes, error codes, bearer auth, abilities, sync types и primary action values должны остаться совместимыми.
- Все private Web/API responses получают `noindex`/`private, no-store` в применимой транспортной форме.
- Каждое изменение поведения выполняется TDD: failing test → подтверждённый RED → минимальный код → GREEN → refactor.
- После PHP-изменений запускать `./vendor/bin/pint --dirty --format agent`; после Blade/Tailwind/JS — `npm run build`.
- Каждый task завершается отдельным commit на `main`; перед commit проверять `git status --short --branch`.

## File Structure Map

- `app/Services/Auth/AccountRegistrationService.php` — общая регистрация без token/session concerns.
- `app/Services/Auth/AccountService.php` — profile/password/delete account mutations.
- `app/Services/Auth/AccountPasswordResetService.php` — общий Password Broker reset.
- `app/Services/Auth/AccountEmailVerificationService.php` — идемпотентное подтверждение email.
- `app/Notifications/VerifyAccountEmail.php` и `ResetAccountPassword.php` — universal browser links.
- `app/Livewire/Auth/*` и `resources/views/livewire/auth/*` — guest/session auth UI.
- `app/Livewire/Profile/*` и `resources/views/livewire/profile/*` — self-service profile/security UI.
- `app/Services/Catalog/UserLibraryQuery.php` — общий owner-scoped library filter/pagination query.
- `app/Services/Catalog/UserLibrarySummaryQuery.php` — aggregate counts.
- `app/Livewire/Library/UserLibraryPage.php` и matching view — единый web library route surface.
- `app/Http/Resources/Api/V1/UserLibrarySummaryResource.php` — явный JSON summary contract.
- `tests/Feature/Web/*` — Web/Livewire behavior; `tests/Feature/Api/V1/*` — compatibility/API additions.

---

### Task 1: Transport-neutral account services without API regression

**Files:**
- Create: `app/Services/Auth/AccountRegistrationService.php`
- Create: `app/Services/Auth/AccountService.php`
- Create: `app/Services/Auth/AccountPasswordResetService.php`
- Create: `app/Services/Auth/AccountEmailVerificationService.php`
- Create: `app/Notifications/VerifyAccountEmail.php`
- Create: `app/Notifications/ResetAccountPassword.php`
- Modify: `app/Models/User.php`
- Modify: `app/Services/Auth/MobileAuthenticationService.php`
- Modify: `app/Http/Controllers/Api/V1/AccountController.php`
- Modify: `app/Http/Controllers/Api/V1/Auth/ResetPasswordController.php`
- Modify: `app/Http/Controllers/Api/V1/Auth/VerifyEmailController.php`
- Delete after consumers migrate: `app/Services/Auth/MobileAccountService.php`
- Delete after references migrate: `app/Notifications/VerifyMobileEmail.php`
- Delete after references migrate: `app/Notifications/ResetMobilePassword.php`
- Test: `tests/Feature/Api/V1/AuthenticationTest.php`
- Test: `tests/Feature/Api/V1/AccountManagementTest.php`
- Test: `tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php`

**Interfaces:**
- Produces: `AccountRegistrationService::register(array{name:string,email:string,password:string}): User`.
- Produces: `AccountService::updateProfile(User,array{name?:string,email?:string}): User`.
- Produces: `AccountService::updatePassword(User,string,string,?int): void`.
- Produces: `AccountService::delete(User,string): void`.
- Produces: `AccountPasswordResetService::reset(string,string,string): void`.
- Produces: `AccountEmailVerificationService::verify(int,string): User`.

- [x] **Step 1: Write failing service-boundary tests**

Add assertions that API registration resolves `AccountRegistrationService`, account controller resolves `AccountService`, reset revokes every token through `AccountPasswordResetService`, and neutral notification classes replace the mobile-named classes:

```php
public function test_registration_uses_the_shared_account_boundary_and_universal_verification_link(): void
{
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Новый пользователь',
        'email' => 'shared@example.com',
        'password' => 'Very-Strong-Password-42!',
        'password_confirmation' => 'Very-Strong-Password-42!',
        'device_name' => 'Pixel 9',
    ])->assertCreated();

    $user = User::query()->where('email', 'shared@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyAccountEmail::class);
}
```

- [x] **Step 2: Run focused tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/AuthenticationTest.php tests/Feature/Api/V1/AccountManagementTest.php tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php
```

Expected: FAIL because `VerifyAccountEmail` and shared service classes do not exist.

- [x] **Step 3: Implement the shared services**

Use these exact public signatures and invariants:

```php
final class AccountRegistrationService
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function register(array $attributes): User;
}

final class AccountService
{
    /** @param array{name?: string, email?: string} $data */
    public function updateProfile(User $user, array $data): User;
    public function updatePassword(User $user, string $current, string $new, ?int $currentTokenId): void;
    public function delete(User $user, string $password): void;
}

final class AccountPasswordResetService
{
    public function reset(string $email, string $token, string $password): void;
}

final class AccountEmailVerificationService
{
    public function verify(int $userId, string $hash): User;
}
```

Registration deletes orphaned reset rows case-insensitively, creates the user in a three-attempt transaction and calls `sendEmailVerificationNotification()` after the transaction. Reset uses `Password::reset`, rotates `remember_token`, deletes all Sanctum tokens and dispatches `PasswordReset`. Verification uses `hash_equals`, `markEmailAsVerified()` and dispatches `Verified` once.

- [x] **Step 4: Migrate API consumers and notification classes**

`MobileAuthenticationService::register()` must delegate user creation and only add `device_name` token issuance:

```php
public function __construct(private readonly AccountRegistrationService $accounts) {}

public function register(array $attributes): array
{
    $user = $this->accounts->register([
        'name' => $attributes['name'],
        'email' => $attributes['email'],
        'password' => $attributes['password'],
    ]);

    return $this->issueToken($user, $attributes['device_name']);
}
```

Update API imports, `User` notification overrides and tests to neutral class names. Until Task 3 installs the Web completion routes, keep the existing API verification/reset URLs so every intermediate commit has valid notification links. Task 3 changes them atomically to:

```php
URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
    'id' => $notifiable->getKey(),
    'hash' => sha1($notifiable->getEmailForVerification()),
]);

route('password.reset', [
    'token' => $this->token,
    'email' => $notifiable->getEmailForPasswordReset(),
]);
```

- [x] **Step 5: Run focused regression tests, format and commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1/AuthenticationTest.php tests/Feature/Api/V1/AccountManagementTest.php tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php
git status --short --branch
git add app tests/Feature/Api/V1
git commit -m "refactor: share account lifecycle services"
```

Expected: all three test files PASS; branch is `main`.

---

### Task 2: Livewire registration, login and logout

**Files:**
- Create: `app/Services/Auth/WebAuthenticationRateLimiter.php`
- Create: `app/Services/Auth/WebAuthenticationService.php`
- Create: `app/Livewire/Forms/Auth/RegistrationForm.php`
- Create: `app/Livewire/Forms/Auth/LoginForm.php`
- Create: `app/Livewire/Auth/RegisterPage.php`
- Create: `app/Livewire/Auth/LoginPage.php`
- Create: `app/Livewire/Auth/LogoutButton.php`
- Create: `app/Livewire/Auth/VerifyEmailPage.php`
- Create: `resources/views/livewire/auth/register-page.blade.php`
- Create: `resources/views/livewire/auth/login-page.blade.php`
- Create: `resources/views/livewire/auth/logout-button.blade.php`
- Create: `resources/views/livewire/auth/verify-email-page.blade.php`
- Create: `resources/views/components/form/field.blade.php`
- Create: `resources/views/components/form/password-field.blade.php`
- Create: `resources/views/components/form/checkbox.blade.php`
- Create: `resources/views/components/form/status-message.blade.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`
- Modify: `resources/views/components/layout/site-header.blade.php`
- Test: `tests/Feature/Web/WebAuthenticationTest.php`
- Test: `tests/Unit/BladeTemplateTest.php`

**Interfaces:**
- Consumes: `AccountRegistrationService::register()` from Task 1.
- Produces: named routes `login`, `register`, `library.index`; `LogoutButton::logout(): void` with a Livewire redirect effect.

- [x] **Step 1: Write failing route and Livewire auth tests**

Create tests covering guest rendering, registration, intended login and logout:

```php
public function test_guest_can_register_and_is_sent_to_verification_notice(): void
{
    Notification::fake();

    Livewire::test(RegisterPage::class)
        ->set('form.name', '  Иван   Иванов ')
        ->set('form.email', ' IVAN@EXAMPLE.COM ')
        ->set('form.password', 'Very-Strong-Password-42!')
        ->set('form.passwordConfirmation', 'Very-Strong-Password-42!')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirectToRoute('verification.notice');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['name' => 'Иван Иванов', 'email' => 'ivan@example.com']);
}

public function test_login_regenerates_the_session_and_returns_to_intended_route(): void
{
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->get('/library')->assertRedirect(route('login'));

    Livewire::test(LoginPage::class)
        ->set('form.email', ' OWNER@EXAMPLE.COM ')
        ->set('form.password', 'password')
        ->set('form.remember', true)
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect('/library');

    $this->assertAuthenticatedAs($user);
}
```

- [x] **Step 2: Run test and confirm RED**

Run:

```bash
php artisan test tests/Feature/Web/WebAuthenticationTest.php
```

Expected: FAIL because Web auth components and routes do not exist.

- [x] **Step 3: Implement routes, rate limiters and form objects**

Add guest routes and protected placeholders for the already-approved route names. Task 2 installs the basic authenticated `verification.notice` page and a temporary named `/library` redirect to the existing authenticated viewing page; Task 3 adds verification actions and Task 6 replaces the redirect with `UserLibraryPage`. Because Livewire submits actions through its shared update route, `WebAuthenticationRateLimiter` applies separate action-level keys instead of rate-limiting only the GET form routes. Login key is normalized email plus IP; registration is limited per IP.

`RegistrationForm` and `LoginForm` use these exact property names:

```php
final class RegistrationForm extends Form
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $passwordConfirmation = '';
}

final class LoginForm extends Form
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
}
```

Use the API password contract `Password::min(12)->letters()->mixedCase()->numbers()->symbols()` and Russian messages. Normalize before validation.

- [x] **Step 4: Implement full-page components and accessible views**

`RegisterPage::register()` validates, calls `AccountRegistrationService`, logs in, regenerates the session and redirects to `verification.notice`. `LoginPage::login()` rate-limits, performs case-insensitive user lookup plus `Auth::attempt(['email' => $storedEmail, 'password' => $this->form->password], $this->form->remember)`, regenerates and returns `redirect()->intended(route('library.index'))`. Failure adds only `form.email` generic error.

`LogoutButton::logout()` delegates session teardown to `WebAuthenticationService`, which uses:

```php
Auth::guard('web')->logout();
request()->session()->invalidate();
request()->session()->regenerateToken();

$this->redirectRoute('home');
```

Views extend `layouts.app`, set `robots=noindex,nofollow`, use visible labels, correct autocomplete and 44 px controls. No `wire:navigate`.

- [x] **Step 5: Update header and guest redirects**

Guest header shows `Войти` and `Регистрация`; authenticated header shows `Моя библиотека`, profile/security links and `<livewire:auth.logout-button />`. `bootstrap/app.php` keeps `redirectGuestsTo(route('login'))` and sets authenticated guest-route redirect to `library.index`.

- [x] **Step 6: Run tests/build, format and commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Web/WebAuthenticationTest.php tests/Unit/BladeTemplateTest.php
npm run build
git status --short --branch
git add app bootstrap routes resources/views tests/Feature/Web tests/Unit/BladeTemplateTest.php
git commit -m "feat: add Livewire registration and login"
```

Expected: focused tests and build PASS.

---

### Task 3: Web verification, password recovery and session authentication

**Files:**
- Create: `app/Http/Controllers/Auth/VerifyEmailController.php`
- Create: `app/Livewire/Forms/Auth/ForgotPasswordForm.php`
- Create: `app/Livewire/Forms/Auth/ResetPasswordForm.php`
- Create: `app/Livewire/Forms/Auth/ConfirmPasswordForm.php`
- Create: `app/Livewire/Auth/ForgotPasswordPage.php`
- Create: `app/Livewire/Auth/ResetPasswordPage.php`
- Modify: `app/Livewire/Auth/VerifyEmailPage.php`
- Create: `app/Livewire/Auth/ConfirmPasswordPage.php`
- Create: forgot/reset/confirm matching views in `resources/views/livewire/auth`
- Modify: `resources/views/livewire/auth/verify-email-page.blade.php`
- Modify: `app/Notifications/VerifyAccountEmail.php`
- Modify: `app/Notifications/ResetAccountPassword.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Web/WebEmailVerificationTest.php`
- Test: `tests/Feature/Web/WebPasswordRecoveryTest.php`

**Interfaces:**
- Consumes: `AccountPasswordResetService::reset()` and `AccountEmailVerificationService::verify()`.
- Produces: standard routes `verification.notice`, `verification.verify`, `password.request`, `password.reset`, `password.confirm`.

- [ ] **Step 1: Write failing verification and recovery tests**

Tests must cover valid/tampered/expired/already-used links, non-enumerating forgot response, reset token consumption, API token revocation and password confirmation timestamp:

```php
public function test_signed_web_verification_works_without_an_active_session(): void
{
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Адрес электронной почты подтверждён.');

    $this->assertNotNull($user->fresh()->email_verified_at);
}
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
php artisan test tests/Feature/Web/WebEmailVerificationTest.php tests/Feature/Web/WebPasswordRecoveryTest.php
```

Expected: FAIL because routes/components are absent.

- [ ] **Step 3: Implement signed handler and verification page**

The thin controller calls the shared verifier and redirects authenticated matching owner to `library.index`; every other valid link redirects to `login`. `VerifyEmailPage::resend()` checks `hasVerifiedEmail()`, applies `web-verification`, sends queued notification and returns Russian status. In the same change, update `VerifyAccountEmail` to `verification.verify` and `ResetAccountPassword` to `password.reset`, so notification URLs only change after both Web routes exist.

- [ ] **Step 4: Implement forgot/reset/confirm forms**

Forgot always exposes this text regardless of broker result:

```php
public const STATUS = 'Если аккаунт существует, письмо для восстановления отправлено.';
```

Reset form properties are `email`, `token`, `password`, `passwordConfirmation`; `mount(string $token)` copies route/query values without placing password in URL. Confirm form validates `current_password`, calls `request()->session()->passwordConfirmed()` and redirects intended.

- [ ] **Step 5: Apply `auth.session` and Russian signed-link rendering**

Protected profile/library routes use `['auth', 'auth.session']`. An expired/tampered Web verification link renders a Russian 403 response without exception details; API signed link retains JSON behavior.

- [ ] **Step 6: Run regression tests, format and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Web/WebEmailVerificationTest.php tests/Feature/Web/WebPasswordRecoveryTest.php tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php
npm run build
git status --short --branch
git add app bootstrap routes resources/views tests/Feature/Web tests/Feature/Api/V1/EmailVerificationAndPasswordResetTest.php
git commit -m "feat: add web account verification and recovery"
```

Expected: Web and API recovery tests PASS.

---

### Task 4: Livewire profile, security, devices and account deletion

**Files:**
- Create: `app/Livewire/Profile/ProfilePage.php`
- Create: `app/Livewire/Profile/SecurityPage.php`
- Create: `resources/views/livewire/profile/profile-page.blade.php`
- Create: `resources/views/livewire/profile/security-page.blade.php`
- Modify: `app/Services/Auth/AccountService.php`
- Modify: `app/Services/Auth/MobileTokenService.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/WebAccountManagementTest.php`
- Test: `tests/Feature/Api/V1/AccountManagementTest.php`

**Interfaces:**
- Consumes: `AccountService` and `MobileTokenService`.
- Produces: `ProfilePage::saveProfile()`, `SecurityPage::updatePassword()`, `revokeDevice(int)`, `revokeAllDevices()`, `logoutOtherDevices()`, `deleteAccount()`.

- [ ] **Step 1: Write failing owner/security tests**

Cover profile normalization, email reverification, current-password errors, token revocation, foreign token 404, other browser sessions and cascade deletion:

```php
public function test_profile_email_change_requires_reverification_and_sends_one_message(): void
{
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    Livewire::actingAs($user)->test(ProfilePage::class)
        ->set('name', '  Иван   Иванов ')
        ->set('email', ' NEW@EXAMPLE.COM ')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('email', 'new@example.com');

    $this->assertNull($user->fresh()->email_verified_at);
    Notification::assertSentToTimes($user, VerifyAccountEmail::class, 1);
}
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
php artisan test tests/Feature/Web/WebAccountManagementTest.php
```

Expected: FAIL because profile components do not exist.

- [ ] **Step 3: Implement ProfilePage**

Use public properties `name` and `email`, initialize only from `Auth::user()`, normalize before validation and call `AccountService::updateProfile`. Validation mirrors `UpdateProfileRequest`, including case-insensitive duplicate check excluding current user.

- [ ] **Step 4: Implement SecurityPage**

Keep passwords only in `currentPassword`, `password`, `passwordConfirmation`, reset them after action, and never render them back. Devices are loaded render-locally from `$user->tokens()->latest('id')`. Revoke uses `MobileTokenService::revoke($user,$tokenId)`. Other sessions use `Auth::logoutOtherDevices($currentPassword)`. Account deletion calls `AccountService::delete`, logs out, invalidates session and redirects home.

- [ ] **Step 5: Run tests/build, format and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Web/WebAccountManagementTest.php tests/Feature/Api/V1/AccountManagementTest.php
npm run build
git status --short --branch
git add app routes resources/views tests/Feature/Web tests/Feature/Api/V1/AccountManagementTest.php
git commit -m "feat: add Livewire account management"
```

Expected: Web/API account tests PASS.

---

### Task 5: Shared library query, summary API and indexes

**Files:**
- Create: `app/Services/Catalog/UserLibraryQuery.php`
- Create: `app/Services/Catalog/UserLibrarySummaryQuery.php`
- Create: `app/DTOs/UserLibrarySummary.php`
- Create: `app/DTOs/UserLibraryFilters.php`
- Create: `app/Http/Resources/Api/V1/UserLibrarySummaryResource.php`
- Create: `database/migrations/2026_07_15_000000_add_user_library_query_indexes.php`
- Modify: `app/Http/Requests/Api/V1/UserLibraryIndexRequest.php`
- Modify: `app/Http/Controllers/Api/V1/UserLibraryController.php`
- Modify: `routes/api.php`
- Delete: `app/Services/Catalog/Api/V1/UserLibraryQuery.php`
- Test: `tests/Feature/Api/V1/UserLibraryTest.php`
- Test: `tests/Feature/Api/V1/UserLibrarySummaryTest.php`

**Interfaces:**
- Produces: `UserLibraryQuery::watchlist(User,UserLibraryFilters): LengthAwarePaginator`.
- Produces: `UserLibraryQuery::ratings(User,UserLibraryFilters): LengthAwarePaginator`.
- Produces: `UserLibrarySummaryQuery::get(User): UserLibrarySummary`.

- [ ] **Step 1: Write failing API summary/filter tests**

Test `GET /api/v1/me/library/summary`, `q`, `type`, `year`, sort/direction, invalid values, pagination, private caching and constant query budget:

```php
public function test_library_summary_is_owner_scoped_and_private(): void
{
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $ownerTitle = CatalogTitle::factory()->create();
    $foreignTitle = CatalogTitle::factory()->create();
    CatalogTitleUserState::query()->create([
        'user_id' => $owner->id,
        'catalog_title_id' => $ownerTitle->id,
        'in_watchlist' => true,
    ]);
    CatalogTitleUserState::query()->create([
        'user_id' => $foreign->id,
        'catalog_title_id' => $foreignTitle->id,
        'in_watchlist' => true,
    ]);
    Sanctum::actingAs($owner, ['mobile:read']);

    $this->getJson('/api/v1/me/library/summary')
        ->assertOk()
        ->assertJsonPath('data.watchlist_count', 1)
        ->assertHeader('Cache-Control', 'no-store, private');
}
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
php artisan test tests/Feature/Api/V1/UserLibraryTest.php tests/Feature/Api/V1/UserLibrarySummaryTest.php
```

Expected: FAIL because summary route and filter validation do not exist.

- [ ] **Step 3: Implement filter value object and shared query**

Use an immutable filter shape:

```php
final readonly class UserLibraryFilters
{
    public function __construct(
        public string $query = '',
        public ?string $type = null,
        public ?int $year = null,
        public string $sort = 'updated',
        public string $direction = 'desc',
        public int $perPage = 20,
    ) {}
}
```

Apply filters through `whereHas('catalogTitle')`, preserve visibility scope/eager card loads, and use explicit allowlisted order clauses. Rating sort is accepted only by ratings query/request context.

- [ ] **Step 4: Implement summary DTO/resource/route**

Return exact fields from the spec and named links. Aggregate counts are scoped to owner and current title visibility; history counts only rows with `first_started_at`; Continue Watching count uses the existing query semantics and maximum safe collection size rather than exposing hidden releases.

After `UserLibrarySummaryQuery` exists, update `ProfilePage` from Task 4 to render the same summary DTO instead of issuing independent count queries.

- [ ] **Step 5: Add reversible indexes and verify schema test**

Migration adds named indexes:

```php
$table->index(['user_id', 'in_watchlist', 'updated_at', 'id'], 'catalog_user_state_watchlist_order_idx');
$table->index(['user_id', 'updated_at', 'id'], 'catalog_user_state_updated_order_idx');
$table->index(['user_id', 'rating', 'updated_at', 'id'], 'catalog_user_state_rating_order_idx');
```

`down()` drops those exact names. Do not edit existing migrations.

- [ ] **Step 6: Run tests, format and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1/UserLibraryTest.php tests/Feature/Api/V1/UserLibrarySummaryTest.php
git status --short --branch
git add app database/migrations routes/api.php tests/Feature/Api/V1
git commit -m "feat: expand private library API"
```

Expected: library API tests PASS.

---

### Task 6: Unified Livewire library interface

**Files:**
- Create: `app/Livewire/Forms/Library/LibraryFilters.php`
- Create: `app/Livewire/Library/UserLibraryPage.php`
- Create: `resources/views/livewire/library/user-library-page.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layout/site-header.blade.php`
- Modify: `app/Livewire/ViewingActivity.php`
- Test: `tests/Feature/Web/UserLibraryPageTest.php`
- Test: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Consumes: shared `UserLibraryQuery`, `UserLibrarySummaryQuery`, `CatalogViewingActivityQuery`, `CatalogUserStateService`, `CatalogViewingActivityService`.
- Produces: route sections `watchlist|ratings|continue-watching|history` and backward-compatible `/watching` redirect.

- [ ] **Step 1: Write failing Livewire library tests**

Cover auth redirect, sections, URL filters, separate paginator names, watchlist/rating mutations, history deletion/clear confirmation and `/watching` redirect:

```php
public function test_verified_user_filters_watchlist_and_removes_an_item(): void
{
    $user = User::factory()->create();
    $title = CatalogTitle::factory()->create(['title' => 'Нужный сериал']);
    CatalogTitleUserState::factory()->for($user)->for($title)->create(['in_watchlist' => true]);

    Livewire::actingAs($user)->test(UserLibraryPage::class, ['section' => 'watchlist'])
        ->set('filters.query', 'Нужный')
        ->assertSee('Нужный сериал')
        ->call('setWatchlist', $title->id, false)
        ->assertHasNoErrors()
        ->assertDontSee('Нужный сериал');
}
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
php artisan test tests/Feature/Web/UserLibraryPageTest.php
```

Expected: FAIL because the component and routes are absent.

- [ ] **Step 3: Implement filters and page component**

`LibraryFilters` exposes URL-backed `query`, `type`, `year`, `sort`, `direction`. `UserLibraryPage` validates section in `mount`, resolves current user privately, prepares render-local summary/list data, and exposes owner-safe actions:

```php
public function setWatchlist(int $catalogTitleId, bool $inWatchlist): void;
public function setRating(int $catalogTitleId, ?int $rating): void;
public function removeHistoryItem(int $progressId): void;
public function clearHistory(): void;
```

Catalog titles are re-resolved through an entitlement-scoped query; no model or `user_id` is a public property.

- [ ] **Step 4: Implement responsive view and compatibility redirect**

Use existing UI/poster components, one `h1`, wrapping section navigation, search/filter form, independent empty/loading/error states and standard Livewire pagination. No nested scroll or fake content. `/watching` redirects under `auth` to `library.section` with `continue-watching`; remove the component-level guest `abort_unless` once route middleware owns authentication.

- [ ] **Step 5: Run tests/build, format and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Web/UserLibraryPageTest.php tests/Feature/CatalogPageTest.php
npm run build
git status --short --branch
git add app routes/web.php resources/views tests/Feature/Web tests/Feature/CatalogPageTest.php
git commit -m "feat: add Livewire user library"
```

Expected: library and catalog regression tests PASS; build succeeds.

---

### Task 7: Verified mutation parity and personalized catalog cards

**Files:**
- Modify: `app/Policies/CatalogTitlePolicy.php`
- Modify: `app/Policies/EpisodeViewProgressPolicy.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php`
- Create: `app/Services/Catalog/CatalogUserCardStateLoader.php`
- Modify: `app/View/Components/Catalog/TitleCard.php`
- Modify: `resources/views/components/catalog/title-card-grid.blade.php`
- Modify: `resources/views/components/catalog/title-card-horizontal.blade.php`
- Modify: `app/Http/Controllers/CatalogController.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify: `app/Services/Catalog/CatalogDirectoryPageBuilder.php`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/AuthorizationTest.php`
- Test: `tests/Feature/Api/V1/UserTitleStateTest.php`
- Test: `tests/Feature/Api/V1/PlaybackProgressTest.php`

**Interfaces:**
- Produces: `CatalogTitlePolicy::interact()` requires verified email plus entitlement.
- Produces: query-provided attributes `user_in_watchlist`, `user_rating`, `user_progress_percent`, `user_primary_action` on card titles for authenticated requests.

- [ ] **Step 1: Write failing parity and query-budget tests**

Add a Livewire test proving an unverified user can read but cannot mutate, a verified user can mutate, and catalog card query count is constant as card count grows:

```php
public function test_unverified_web_user_sees_verification_prompt_and_cannot_change_title_state(): void
{
    $user = User::factory()->unverified()->create();
    $title = CatalogTitle::factory()->create();

    Livewire::actingAs($user)->test(CatalogTitlePlayer::class, ['catalogTitleId' => $title->id])
        ->call('setWatchlist', true)
        ->assertForbidden();

    $this->assertDatabaseMissing('catalog_title_user_states', [
        'user_id' => $user->id,
        'catalog_title_id' => $title->id,
    ]);
}
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
php artisan test tests/Feature/AuthorizationTest.php tests/Feature/CatalogPageTest.php --filter='unverified|personal|query'
```

Expected: FAIL because Web policies do not require verification and cards lack personal attributes.

- [ ] **Step 3: Harden policies and UI state**

Implement:

```php
public function interact(User $user, CatalogTitle $catalogTitle): bool
{
    return $user->hasVerifiedEmail()
        && $this->entitlements->constrain(CatalogTitle::query(), $user)->whereKey($catalogTitle->id)->exists();
}

public function deleteAny(User $user): bool
{
    return $user->hasVerifiedEmail();
}
```

`EpisodeViewProgressPolicy::delete` also requires owner plus verified email. API keeps `verified.api` so JSON error code remains unchanged. Player shows a linked verification prompt instead of a dead hint.

- [ ] **Step 4: Add query-prepared personal card state**

`CatalogUserCardStateLoader::load(Collection $titles, ?User $user): Collection` performs grouped owner-state/progress queries, sets `user_in_watchlist`, `user_rating`, `user_progress_percent` and a precomputed `user_primary_action` array, and never runs one query per title. Home, catalog, directory and recommendation page builders call it after retrieving their bounded title collections. `TitleCard` only reads already-present attributes and exposes query-free display properties. Library passes its known state directly. Do not query inside the component or Blade.

- [ ] **Step 5: Run regression tests/build, format and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/AuthorizationTest.php tests/Feature/CatalogPageTest.php tests/Feature/Api/V1/UserTitleStateTest.php tests/Feature/Api/V1/PlaybackProgressTest.php
npm run build
git status --short --branch
git add app resources/views tests/Feature
git commit -m "feat: align verified catalog interactions"
```

Expected: Web/API verified matrices and query budgets PASS.

---

### Task 8: OpenAPI and project documentation

**Files:**
- Modify: `resources/api/openapi.json`
- Modify: `README.md`
- Modify: `docs/authorization.md`
- Modify: `docs/api.md`
- Modify: `docs/frontend.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `CHANGELOG.md`
- Test: `tests/Feature/Api/V1/UserLibrarySummaryTest.php`
- Test: `tests/Feature/RefreshProjectDocsCommandTest.php`

**Interfaces:**
- Documents: summary operation ID `getMobileLibrarySummary` and library filter query parameters.
- Documents: Web route/session/verification/profile/library contract.

- [ ] **Step 1: Write failing OpenAPI assertions**

```php
public function test_openapi_describes_library_summary_and_filters(): void
{
    $document = $this->getJson('/api/openapi.json')->assertOk();

    $document
        ->assertJsonPath('paths./api/v1/me/library/summary.get.operationId', 'getMobileLibrarySummary')
        ->assertJsonPath('paths./api/v1/me/library/summary.get.security.0.bearerAuth', [])
        ->assertJsonPath('components.schemas.UserLibrarySummary.properties.watchlist_count.type', 'integer');
}
```

- [ ] **Step 2: Run documentation tests and confirm RED**

```bash
php artisan test tests/Feature/Api/V1/UserLibrarySummaryTest.php tests/Feature/RefreshProjectDocsCommandTest.php
```

Expected: FAIL because OpenAPI/docs do not describe the new surface.

- [ ] **Step 3: Update OpenAPI and thematic documentation**

Add the exact route, security, parameters, schemas, 401/403/422/429 responses and private cache description. Update each thematic owner with actual implemented behavior; do not copy the design verbatim and do not edit managed `project-docs` blocks manually.

- [ ] **Step 4: Refresh generated documentation and commit**

```bash
php artisan project:docs-refresh
php artisan test tests/Feature/Api/V1/UserLibrarySummaryTest.php tests/Feature/RefreshProjectDocsCommandTest.php
git diff --check
git status --short --branch
git add README.md CHANGELOG.md docs resources/api/openapi.json tests/Feature
git commit -m "docs: document authenticated user portal"
```

Expected: docs/OpenAPI tests PASS and only intended docs/generated files are staged.

---

### Task 9: Browser QA, full verification and clean handoff

**Files:**
- Create or modify: `tests/browser/auth-portal.spec.js`
- Modify: `tests/browser/prepare-fixtures.php`
- Modify: `playwright.config.js`
- Modify only if failures prove necessary: auth/profile/library Blade, JS or CSS files from earlier tasks
- Test: complete PHPUnit and Playwright suites

**Interfaces:**
- Consumes: all prior tasks.
- Produces: verified responsive browser flows and clean `main` worktree.

- [ ] **Step 1: Add browser flow tests before UI fixes**

The Playwright file must exercise:

```javascript
test('guest authentication and private library navigation are responsive', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByRole('heading', { level: 1, name: 'Вход' })).toBeVisible();
  await expect(page.locator('main')).toHaveCount(1);
  await expect(page.locator('body')).not.toHaveCSS('overflow-x', 'scroll');
});
```

Extend `tests/browser/prepare-fixtures.php` with a verified `browser@example.com` user whose password is `Browser-Strong-Password-42!`, plus one watchlist item and one real playback-progress row. Change Playwright server env to `SESSION_DRIVER=database` so authentication persists across HTTP requests. Add a Tablet Chromium project with viewport 768×1024. The test logs in through `/login`, then verifies profile, library tabs, logout, player progress and Continue Watching. Run at 390×844, 768×1024 and 1440×1200; assert no console/page errors and no failed same-origin requests.

- [ ] **Step 2: Run Playwright and confirm any RED is behavior-specific**

```bash
npx playwright test tests/browser/auth-portal.spec.js
```

Expected before final UI fixes: any failure names the exact missing/incorrect auth or geometry behavior, not a test syntax/setup error.

- [ ] **Step 3: Apply minimal UI fixes and rerun focused checks**

Fix only failures demonstrated by the browser test. Preserve light theme, no internal scroll, full text and 44×44 controls.

- [ ] **Step 4: Run full verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test
npm run build
npx playwright test tests/browser/auth-portal.spec.js
composer audit
npm audit --audit-level=high
git diff --check
git status --short --branch
```

Expected: Pint leaves no unexpected changes; PHPUnit, build and browser suite exit 0; audits report no unresolved high-severity production vulnerability introduced by this work; branch is `main`.

- [ ] **Step 5: Commit final browser coverage and verify clean tree**

```bash
git add tests/browser resources/views resources/js resources/css
git commit -m "test: cover authenticated portal flows"
git status --short --branch
```

Expected: status reports branch `main` ahead of `origin/main` and contains no modified or untracked paths.
