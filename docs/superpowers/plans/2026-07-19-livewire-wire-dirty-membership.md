# Livewire `wire:dirty` Membership Draft Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Показывать локализованный индикатор неприменённых изменений только для deferred checkbox-draft состава подборок.

**Architecture:** Существующий `selectedCollectionPublicIds` остаётся единственным draft state, `apply()` — единственной mutation boundary. Blade добавляет presentation-only `wire:dirty` с точным property target; компонент, сервисы, routes и persistence не меняются.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree.
- Не перестраивать общий staged snapshot и не захватывать несвязанные изменения.
- Использовать class-based Livewire; Volt, `@php`, inline business JavaScript и новые dependencies запрещены.
- RU/EN translation keys добавляются синхронно; translated text не становится identity.
- Authorization и persistence остаются server-side; `wire:dirty` является только presentation feedback.

---

### Task 1: Focused dirty-state contract

**Files:**
- Create: `tests/Feature/LivewireWireDirtyContractTest.php`
- Modify: `resources/views/livewire/collections/catalog-collection-membership-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Interfaces:**
- Consumes: `CatalogCollectionMembershipManager::$selectedCollectionPublicIds`, `collections.membership.unsaved`.
- Produces: rendered `wire:dirty` targeted only at `selectedCollectionPublicIds`; no PHP signature or data contract changes.

- [x] **Step 1: Write the failing feature test**

Create a real Livewire render fixture with one owner collection. After `openSelector()`, assert the Russian dirty message, `wire:dirty`, `wire:target="selectedCollectionPublicIds"`, deferred `wire:model` and absence of `wire:model.live`.

- [x] **Step 2: Verify RED**

Run: `php artisan test --filter=LivewireWireDirtyContractTest`

Expected: FAIL because the rendered membership form has no `wire:dirty` indicator.

- [x] **Step 3: Add minimal translations and Blade binding**

Add `membership.unsaved` to both PHP catalogs and render:

```blade
<span
    role="status"
    aria-live="polite"
    wire:dirty
    wire:target="selectedCollectionPublicIds"
    class="text-sm font-bold text-amber-800"
>{{ __('collections.membership.unsaved') }}</span>
```

Keep `wire:model="selectedCollectionPublicIds"`, the existing `wire:text` count and `apply()` unchanged.

- [x] **Step 4: Verify GREEN**

Run: `php artisan test --filter=LivewireWireDirtyContractTest`

Expected: PASS with all contract assertions.

- [x] **Step 5: Update documentation and compliance evidence**

Update collection frontend/view/architecture owners, `README.md`, Russian `CHANGELOG.md` and `docs/plans/current-task-plan.md` with exact scope, cross-feature impact, rollback and verification evidence.

- [x] **Step 6: Run final gates**

```bash
./vendor/bin/pint tests/Feature/LivewireWireDirtyContractTest.php --format agent
php -l lang/ru/collections.php
php -l lang/en/collections.php
php artisan test --filter=LivewireWireDirtyContractTest
php artisan test --filter=LivewireWireTextContractTest
php artisan test --filter=CatalogCollection
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Expected: task-scoped commands exit `0`; any full-suite failure from the parallel shared snapshot is recorded separately.

Факт: все перечисленные task-scoped команды завершились с exit code `0`. Полный `php artisan test --compact` выполнил 1 351 тест: 1 334 passed, 11 skipped, четыре assertion failures и один error остались в параллельно меняемых administration/catalog-island contracts; новый focused test в полном suite не падал.

- [x] **Step 7: Deliver only if Git scope is separable**

Run `git status --short --branch`, confirm `main`, inspect staged and unstaged overlap, and commit/push only if task files can be isolated without changing another task's index. Otherwise leave the shared snapshot untouched and record `unresolved` delivery evidence.

Факт: проверена ветка `main`, но общий index/worktree содержит большой staged/unstaged snapshot и пересекающиеся `README.md`, `CHANGELOG.md`, canonical docs и membership Blade. Task-only commit/push небезопасен и остаётся `unresolved`; чужой index не перестраивался.
