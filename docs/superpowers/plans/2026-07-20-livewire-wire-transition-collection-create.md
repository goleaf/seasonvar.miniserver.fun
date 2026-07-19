# Livewire `wire:transition` Collection Create Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить доступный optional transition при появлении и закрытии существующей формы создания подборки.

**Architecture:** Реализация остаётся внутри существующего class-based `CatalogCollectionDashboard`: server-owned boolean `showCreate` продолжает условно добавлять и удалять панель, а безымянный `wire:transition` подключает нативный View Transitions contract Livewire 4. `x-ui.panel` уже передаёт attribute bag на корневой `section`, поэтому новые state, methods, CSS и JavaScript не создаются.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5, Vite 8, Tailwind CSS 4.3.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Не изменять dependencies, lock-файлы, `.env`, routes, schema, persistent data, cache и queue configuration.
- Сохранить class-based Livewire, русский UI, RU/EN translation parity и существующие authorization/validation boundaries.
- Не добавлять custom transition CSS/JavaScript; использовать built-in reduced-motion и unsupported-browser fallback Livewire.
- Сначала получить RED на реальном компоненте, затем внести минимальную Blade-правку и получить GREEN.
- Не захватывать в commit существующий чужой staged/unstaged snapshot; при невозможности task-only commit честно оставить доставку `unresolved`.

---

### Task 1: Real-component transition contract

**Files:**
- Create: `tests/Feature/LivewireWireTransitionContractTest.php`
- Modify: `resources/views/livewire/collections/catalog-collection-dashboard.blade.php:26`

**Interfaces:**
- Consumes: `CatalogCollectionDashboard::$showCreate`, computed `canCreate`, `Livewire::actingAs()`, `x-ui.panel` attribute forwarding.
- Produces: безымянный HTML attribute `wire:transition` только внутри условной create-panel; PHP/API interface не создаётся.

- [x] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireTransitionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_create_panel_transitions_only_while_it_is_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogCollectionDashboard::class)
            ->assertDontSeeHtml('wire:transition')
            ->set('showCreate', true)
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('wire:transition')
            ->assertSeeHtml('wire:submit="create"')
            ->set('showCreate', false)
            ->assertDontSeeHtml('wire:transition');
    }
}
```

- [x] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test --filter=LivewireWireTransitionContractTest
```

Expected: one failing test because the open create panel does not contain `wire:transition`; authentication, component rendering and `aria-expanded` assertions reach the intended boundary.

- [x] **Step 3: Add the minimal transition attribute**

Replace the opening create-panel component with:

```blade
<x-ui.panel wire:transition :title="__('collections.actions.create')" icon="fa-solid fa-folder-plus">
```

Do not change `showCreate`, submit/cancel actions, form fields, validation, pagination islands or Tailwind utilities.

- [x] **Step 4: Run the test and verify GREEN**

Run:

```bash
php artisan test --filter=LivewireWireTransitionContractTest
```

Expected: `1 passed`, with all assertions passing.

- [x] **Step 5: Format the PHP test and rerun the focused contract**

Run:

```bash
./vendor/bin/pint tests/Feature/LivewireWireTransitionContractTest.php --format agent
php artisan test --filter=LivewireWireTransitionContractTest
```

Expected: Pint exits zero and the focused test remains green.

### Task 2: Permanent documentation and compliance evidence

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/specs/2026-07-20-livewire-wire-transition-collection-create-design.md`
- Modify: `docs/superpowers/plans/2026-07-20-livewire-wire-transition-collection-create.md`

**Interfaces:**
- Consumes: repository documentation ownership map, Russian changelog/README rules, completed RED/GREEN evidence.
- Produces: permanent rule limiting `wire:transition` to bounded state changes and task compliance statuses backed by executed commands.

- [x] **Step 1: Update canonical owners**

Add a concise rule to the Livewire/UI sections of `docs/architecture.md`, `docs/frontend.md` and `docs/views.md`:

```markdown
- `wire:transition` применяется только к ограниченным add/remove/change boundaries; встроенные `prefers-reduced-motion` и мгновенный fallback браузеров без View Transitions API не переопределяются custom animation.
```

Document that the collection dashboard create form is the first concrete boundary. Do not duplicate domain policy, validation or storage contracts.

- [x] **Step 2: Update visitor and technical histories**

Add `### 20 июля 2026 года` as the newest visitor-history date in `README.md` with one visitor-facing bullet explaining that the create-collection form appears/closes smoothly where supported and remains instant with reduced motion or an older browser.

Add `## 2026-07-20` to `CHANGELOG.md` with one Russian technical bullet naming `CatalogCollectionDashboard`, `wire:transition`, focused test coverage, reduced-motion/fallback behavior and unchanged state/security/data boundaries.

- [x] **Step 3: Finalize compliance matrix**

Update `docs/plans/current-task-plan.md` from design/RED pending to actual executed evidence. Use only `completed`, `already_compliant`, `not_applicable` and `unresolved`; keep full-suite and Git delivery unresolved unless their fresh commands prove success.

### Task 3: Regression, build and delivery verification

**Files:**
- Verify: `resources/views/livewire/collections/catalog-collection-dashboard.blade.php`
- Verify: `tests/Feature/LivewireWireTransitionContractTest.php`
- Verify: all documentation files listed in Task 2

**Interfaces:**
- Consumes: green focused contract and completed documentation.
- Produces: fresh verification evidence and an explicitly scoped Git delivery decision.

- [x] **Step 1: Run focused collection regressions**

Run:

```bash
php artisan test --filter=LivewireWireTransitionContractTest
php artisan test --filter=CatalogCollectionDashboard
php artisan test --filter=RussianOnlyAuthoringTest
```

Expected: all applicable tests pass; if no test name matches `CatalogCollectionDashboard`, record the zero-test result rather than claiming coverage.

- [x] **Step 2: Build frontend and validate managed docs**

Run:

```bash
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Expected: Vite build, managed documentation and whitespace checks exit zero.

- [x] **Step 3: Scan for competing or stale implementations**

Run:

```bash
rg -n "wire:transition|showCreate|startViewTransition|prefers-reduced-motion" app resources tests docs README.md CHANGELOG.md
```

Expected: the new directive is limited to the chosen create-panel, documentation/test evidence is consistent, and no duplicate custom animation is attached to the same state.

- [x] **Step 4: Run the full backend suite**

Run:

```bash
php artisan test --compact
```

Expected: full suite passes. If unrelated shared-snapshot tests fail, diagnose them without changing out-of-scope code and record exact failures as `unresolved`.

Actual: `1 360` tests, `1 342` passed, `11` skipped, `7` errors. Systematic reproduction traced all errors to the shared-snapshot `AdminAuditRecorder::record()` access to an absent `public_id` on existing `CatalogTitle`/`Season` models; the transition contract did not fail and no out-of-scope fix was made.

- [x] **Step 5: Re-read requirements and inspect final diff**

Run:

```bash
git diff -- resources/views/livewire/collections/catalog-collection-dashboard.blade.php tests/Feature/LivewireWireTransitionContractTest.php docs/architecture.md docs/frontend.md docs/views.md docs/plans/current-task-plan.md docs/superpowers/specs/2026-07-20-livewire-wire-transition-collection-create-design.md docs/superpowers/plans/2026-07-20-livewire-wire-transition-collection-create.md README.md CHANGELOG.md
git status --short --branch
```

Expected: branch is `main`, the task diff matches this plan, and unrelated existing modifications remain untouched.

- [x] **Step 6: Commit and push only if the snapshot is task-only**

Run only when `git status` proves no unrelated staged or unstaged paths overlap delivery:

```bash
git add resources/views/livewire/collections/catalog-collection-dashboard.blade.php tests/Feature/LivewireWireTransitionContractTest.php docs/architecture.md docs/frontend.md docs/views.md docs/plans/current-task-plan.md docs/superpowers/specs/2026-07-20-livewire-wire-transition-collection-create-design.md docs/superpowers/plans/2026-07-20-livewire-wire-transition-collection-create.md README.md CHANGELOG.md
git diff --cached --check
git commit -m "feat: animate collection create panel"
git push origin main
```

Expected: commit and push succeed from `main`. In the current shared snapshot, overlapping staged/unstaged files are expected to block safe task-only staging; in that case do not mutate the index and leave commit/push `unresolved` with exact evidence.

Actual: ветка `main` подтверждена, но `README.md`, `CHANGELOG.md`, canonical docs и target Blade уже содержат overlapping staged/unstaged изменения общего snapshot. Index не изменялся; commit/push оставлены `unresolved`, потому что task-only staging потребовал бы захватить или перестроить чужие изменения.
