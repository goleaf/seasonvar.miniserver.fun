# Site Footer Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полностью заменить компактный footer адаптивной трёхчастной навигационной структурой.

**Architecture:** Существующий Blade-компонент остаётся единственной точкой разметки footer и использует только уже доступные route helpers и auth state. Отдельный feature-тест фиксирует семантическую структуру, реальные ссылки и удаление старого компактного контракта.

**Tech Stack:** Laravel 13, Blade, Tailwind CSS 4, FontAwesome, PHPUnit 12, Vite 8, Playwright.

## Global Constraints

- Работать только в существующей ветке `main`.
- Не добавлять backend-запросы, зависимости, фиктивные ссылки или маркетинговый текст.
- Сохранить светлую тему и русские видимые подписи.
- Использовать только существующие маршруты `home`, `titles.index`, `viewing-activity`, `stats`, `sitemap` и `feed`.
- Проверить мобильный и desktop viewport без горизонтального overflow.

---

### Task 1: Зафиксировать семантический контракт footer

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: rendered home response.
- Produces: regression contract for `data-site-footer-brand`, two labelled navigation groups and `data-site-footer-bottom`.

- [x] **Step 1: Add the failing test**

Добавить тест, который проверяет новые footer markers, русские заголовки, ссылки на каталог/статистику/sitemap/RSS и отсутствие старого `aria-label="Техническая навигация"`.

- [x] **Step 2: Verify RED**

```bash
php artisan test --filter=test_site_footer_has_responsive_brand_navigation_and_service_regions
```

Expected: FAIL because `data-site-footer-brand` is absent.

### Task 2: Заменить footer-компонент

**Files:**
- Modify: `resources/views/components/layout/site-footer.blade.php`

**Interfaces:**
- Consumes: `$siteName`, request route state and optional authenticated user state.
- Produces: responsive three-column footer with existing public routes only.

- [x] **Step 1: Implement the minimal Blade structure**

Добавить brand region, labelled catalog navigation, labelled service navigation and bottom bar. Использовать `md:grid-cols-2` для двух групп ссылок и `xl:grid-cols-[minmax(0,1.5fr)_minmax(0,0.75fr)_minmax(0,0.75fr)]` для desktop.

- [x] **Step 2: Verify GREEN and surrounding behavior**

```bash
php artisan test --filter=test_site_footer_has_responsive_brand_navigation_and_service_regions
php artisan test --filter=CatalogVisualSystemTest
npm run build
git diff --check
```

Expected: all commands exit successfully.

### Task 3: Responsive browser QA and Git

**Files:**
- Artifacts: `output/playwright/site-footer-mobile.png`
- Artifacts: `output/playwright/site-footer-desktop.png`

**Interfaces:**
- Consumes: live `/` page.
- Produces: viewport evidence for footer layout, overflow and browser errors.

- [x] **Step 1: Run Playwright at 390×844 and 1440×1200**

For both viewports verify HTTP 200, footer visibility, one/three computed columns, no horizontal overflow, no page errors and no failed local requests.

- [x] **Step 2: Commit and push on `main`**

```bash
git add docs/superpowers/specs/2026-07-13-site-footer-redesign-design.md \
    docs/superpowers/plans/2026-07-13-site-footer-redesign.md \
    tests/Feature/CatalogVisualSystemTest.php \
    resources/views/components/layout/site-footer.blade.php
git commit -m "feat: redesign site footer"
git push origin main
```
