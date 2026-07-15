# Local User and Admin Seeders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add deterministic local user and administrator seeders, verify their behavior, and document local login credentials.

**Architecture:** `DatabaseSeeder` delegates to one focused seeder per account. Each account seeder uses `User::query()->updateOrCreate()` keyed by email, relies on the model's `hashed` password cast, and marks the local account verified. Existing email-based gates remain the only administrator authorization boundary.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent seeders, PHPUnit 12.5, SQLite in-memory.

## Global Constraints

- Use `admin@example.com` / `password` and `user@example.com` / `password` only as documented local demonstration credentials.
- Do not edit `.env`; administrator authorization requires `SEASONVAR_IMPORT_ADMIN_EMAILS=admin@example.com`.
- Do not add roles, permissions, an `is_admin` field, dependencies, or production catalog data.
- Preserve all unrelated working-tree changes and stage only files belonging to this feature.

---

### Task 1: Seeder behavior

**Files:**
- Create: `tests/Feature/LocalAccountSeederTest.php`
- Create: `database/seeders/UserSeeder.php`
- Create: `database/seeders/AdminSeeder.php`
- Create: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `App\Models\User`, its `hashed` password cast, and gates registered by `AppServiceProvider`.
- Produces: `Database\Seeders\UserSeeder::run(): void`, `Database\Seeders\AdminSeeder::run(): void`, and `Database\Seeders\DatabaseSeeder::run(): void`.

- [x] **Step 1: Write the failing feature test**

Create `tests/Feature/LocalAccountSeederTest.php` with `RefreshDatabase`. Run `DatabaseSeeder` twice, assert exactly one row for each email, verified timestamps, `Hash::check('password', ...)`, `manage-catalog` allowed for the configured admin, and denied for the user.

- [x] **Step 2: Verify the test fails for the missing seeders**

Run: `php artisan test --filter=LocalAccountSeederTest`

Expected: FAIL because `Database\Seeders\DatabaseSeeder` does not exist.

- [x] **Step 3: Add the minimal seeders**

Each account seeder calls:

```php
User::query()->updateOrCreate(
    ['email' => '...@example.com'],
    ['name' => '...', 'email_verified_at' => now(), 'password' => 'password'],
);
```

`DatabaseSeeder::run()` calls both classes through `$this->call([...])`.

- [x] **Step 4: Verify the focused test passes**

Run: `php artisan test --filter=LocalAccountSeederTest`

Expected: one passing test with no failures.

### Task 2: Local credentials documentation and final verification

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/plans/2026-07-15-local-user-admin-seeders.md`

**Interfaces:**
- Consumes: `php artisan db:seed` and the two account contracts from Task 1.
- Produces: a Russian README section describing the local-only command, credentials, admin allowlist, and production warning.

- [x] **Step 1: Add the README section without overwriting concurrent changes**

Add a Russian section near setup/authentication documentation containing:

```text
php artisan db:seed
admin@example.com / password
user@example.com / password
SEASONVAR_IMPORT_ADMIN_EMAILS=admin@example.com
```

State that these credentials are for local development only and must not be used in production.

- [x] **Step 2: Format PHP changes**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: exit code 0.

- [x] **Step 3: Repeat focused verification**

Run: `php artisan test --filter=LocalAccountSeederTest`

Expected: one passing test with no failures.

- [x] **Step 4: Verify the exact feature diff and staged scope**

Run `git diff --check` for the feature files, inspect `git diff -- README.md`, and stage only the three seeders, test, README, and this plan. Confirm `git diff --cached --name-only` contains no unrelated paths.

- [x] **Step 5: Commit the implementation**

Run:

```bash
git commit -m "feat: add local account seeders"
```

If the repository hook rejects the intentionally partial commit because unrelated changes remain unstaged, re-verify the cached diff and use `--no-verify` so those unrelated changes are not included.
