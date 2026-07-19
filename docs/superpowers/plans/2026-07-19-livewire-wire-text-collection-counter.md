# Livewire `wire:text` Collection Counter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Обновлять локализованный счётчик выбранных коллекций немедленно через `wire:text`, сохраняя deferred server mutation.

**Architecture:** Существующий Livewire component и `selectedCollectionPublicIds` остаются единственными владельцами состояния. Blade добавляет presentation-only expression к уже существующему SSR fallback; PHP services, persistence и authorization не меняются.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Работать только в существующей ветке `main` и сохранять посторонние изменения рабочего дерева.
- Не добавлять Volt, inline PHP, inline business JavaScript, dependency, route, migration или отдельный network request.
- Видимый текст остаётся локализованным через `lang/{ru,en}/collections.php`.
- Сохранять SSR/no-JavaScript fallback и server-side authorization/persistence.
- Production rollback — возврат одной Blade-директивы и её contract test; schema/data/cache rollback отсутствует.

---

### Task 1: Contract test и минимальная Blade-интеграция

**Files:**
- Create: `tests/Feature/LivewireWireTextContractTest.php`
- Modify: `resources/views/livewire/collections/catalog-collection-membership-manager.blade.php:63`
- Modify: `docs/frontend.md`
- Modify: `docs/architecture.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: `CatalogCollectionMembershipManager::$selectedCollectionPublicIds`, translation key `collections.membership.selected`.
- Produces: rendered `wire:text` expression based on `selectedCollectionPublicIds.length`; server method signatures remain unchanged.

- [x] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Collections\CatalogCollectionMembershipManager;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireTextContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_membership_counter_uses_local_wire_text_with_server_fallback(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogCollectionMembershipManager::class, ['catalogTitleId' => $title->id])
            ->call('openSelector')
            ->assertSeeText('Выбрано: 0')
            ->assertSeeHtml('wire:model="selectedCollectionPublicIds"')
            ->assertSeeHtml('wire:text=')
            ->assertSeeHtml('selectedCollectionPublicIds.length')
            ->assertDontSeeHtml('wire:model.live="selectedCollectionPublicIds"');
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LivewireWireTextContractTest`

Expected: FAIL because the rendered view does not contain `wire:text` or `selectedCollectionPublicIds.length`.

- [x] **Step 3: Write minimal implementation**

Replace the selected-count span with:

```blade
<span
    class="text-sm font-bold text-slate-500"
    aria-live="polite"
    wire:text="@js(__('collections.membership.selected', ['count' => '__livewire_count__'])).replace('__livewire_count__', selectedCollectionPublicIds.length)"
>{{ $selectedCountLabel }}</span>
```

- [x] **Step 4: Run focused verification**

Run: `php artisan test --filter=LivewireWireTextContractTest`

Expected: PASS with all assertions.

- [x] **Step 5: Update documentation and compliance evidence**

Document the exact presentation boundary, no-request behavior, SSR fallback, cross-feature impact, rollback and `#[Async]` non-applicability in the listed canonical documents without changing product contracts outside collection membership UI.

- [x] **Step 6: Run final verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=LivewireWireTextContractTest
php artisan test --filter=CatalogCollection
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Expected: all available commands exit `0`; any absent matching `CatalogCollection` tests are reported honestly rather than treated as executed coverage.

- [ ] **Step 7: Commit only scoped changes — unresolved**

Before commit run `git status --short --branch` and confirm `main`. Commit only when pre-existing staged/unstaged changes can be separated safely; otherwise preserve them and report the Git blocker.

Фактический blocker: общий index уже содержит несвязанные staged изменения других активных задач, включая те же `README.md`, `CHANGELOG.md`, `docs/architecture.md` и `docs/plans/current-task-plan.md`. Task-only commit нельзя создать без захвата или перестройки чужого staged snapshot.

### Повторный официальный аудит 20.07.2026

- [x] Подтвердить, что `wire:text` принимает Alpine-compatible expression, меняет только text content без roundtrip и не имеет modifiers.
- [x] Закрепить ровно один application target, локальную производную deferred draft и SSR fallback отдельным inventory test.
- [x] Проверить отсутствие `wire:model.live`, duplicate `x-text` и нового persistence boundary.
