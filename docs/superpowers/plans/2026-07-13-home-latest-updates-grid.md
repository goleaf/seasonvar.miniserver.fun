# Home Latest Updates Grid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать блок «Последние обновления» на главной странице естественным по высоте и адаптивным до пяти карточек в строке.

**Architecture:** Изменение остаётся внутри существующего Blade-шаблона главной страницы. Отдельный `data-*` marker делает UI-контракт тестируемым, а локальный дочерний Tailwind selector отменяет `sm:h-full` общего компонента только в этой сетке.

**Tech Stack:** Laravel 13, Blade, Tailwind CSS 4, PHPUnit 12, Vite 8.

## Global Constraints

- Работать только в существующей ветке `main`.
- Не менять данные, порядок тайтлов и другие блоки главной страницы.
- На телефоне показывать одну карточку, затем 2 / 3 / 4 / 5 на `sm` / `md` / `lg` / `xl`.
- Не использовать `auto-rows-fr` и не растягивать карточки до одинаковой высоты.
- После Blade-правки запустить focused test и `npm run build`.

---

### Task 1: Проверить и изменить сетку «Последние обновления»

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `resources/views/catalog/index.blade.php`

**Interfaces:**
- Consumes: существующую коллекцию `$featuredTitles` и компонент `x-title-card`.
- Produces: контейнер `data-home-latest-updates-grid` с естественной высотой и responsive-классами до `xl:grid-cols-5`.

- [x] **Step 1: Write the failing test**

Добавить в `CatalogVisualSystemTest`:

```php
public function test_home_latest_updates_uses_a_five_column_natural_height_responsive_grid(): void
{
    $response = $this->get(route('home'));

    $response->assertOk();

    $matched = preg_match(
        '/<div data-home-latest-updates-grid class="([^"]+)"/',
        $response->getContent(),
        $matches,
    );

    $this->assertSame(1, $matched);

    $classes = explode(' ', $matches[1]);

    foreach ([
        'items-start',
        'sm:grid-cols-2',
        'md:grid-cols-3',
        'lg:grid-cols-4',
        'xl:grid-cols-5',
        '[&>[data-catalog-card]]:h-auto',
    ] as $class) {
        $this->assertContains($class, $classes);
    }

    $this->assertNotContains('auto-rows-fr', $classes);
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test --filter=test_home_latest_updates_uses_a_five_column_natural_height_responsive_grid
```

Expected: FAIL because `data-home-latest-updates-grid` is absent.

- [x] **Step 3: Write minimal implementation**

Заменить контейнер карточек блока на:

```blade
<div data-home-latest-updates-grid class="grid items-start gap-3 p-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 [&>[data-catalog-card]]:h-auto">
```

Оставить цикл `$featuredTitles` и `x-title-card` без изменений.

- [x] **Step 4: Run focused checks**

Run:

```bash
php artisan test --filter=test_home_latest_updates_uses_a_five_column_natural_height_responsive_grid
php artisan test --filter=CatalogVisualSystemTest
npm run build
git diff --check
```

Expected: PHPUnit PASS, Vite production build successful, no whitespace errors.

- [x] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-07-13-home-latest-updates-grid-design.md \
    docs/superpowers/plans/2026-07-13-home-latest-updates-grid.md \
    tests/Feature/CatalogVisualSystemTest.php \
    resources/views/catalog/index.blade.php
git commit -m "fix: improve home updates grid"
```
