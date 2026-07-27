# План восстановления иерархии категорий подборок

Дата: 27.07.2026.

**Цель:** вернуть полный двухуровневый справочник на
`/discover/popular#collections`, не меняя public eligibility и данные.

**Стек:** Laravel 13.22, Livewire 4.3, Blade, Tailwind CSS 4.3, PHPUnit 12.5,
Playwright.

## Task 1: Закрепить новый persistent UI contract

**Files:**

- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/performance.md`
- Modify: `docs/UI_STANDARDS.md`

- [x] Заменить dropdown/sidebar-only contract на единый responsive nested list.
- [x] Зафиксировать видимые неинтерактивные zero-count labels.
- [x] Сохранить bounded query/no-extra-SQL contract.

## Task 2: Написать regression RED

**Files:**

- Modify: `tests/Feature/CatalogCollectionExplorerCategoryTest.php`

- [x] Создать сценарий с полным active tree и нулевыми counts.
- [x] Проверить root и child names без выбранного фильтра.
- [x] Проверить nested-list marker и отсутствие кнопки у zero child.
- [x] Запустить focused test и подтвердить ожидаемый RED.

## Task 3: Реализовать минимальный presenter/Blade fix

**Files:**

- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Modify: `resources/views/livewire/collections/catalog-collection-explorer.blade.php`

- [x] Не фильтровать active roots/children из prepared navigation.
- [x] Добавить scalar `is_filterable`.
- [x] Добавить нормализованный child selection action.
- [x] Рендерить один nested list на всех ширинах.
- [x] Не менять search/sort/results/pagination/public query.
- [x] Запустить focused GREEN.

## Task 4: Browser contract

**Files:**

- Modify: `tests/browser/discovery-collections.spec.js`

- [x] Проверить постоянную видимость hierarchy на mobile/desktop.
- [x] Сохранить URL interaction для узлов с положительным count.
- [x] Проверить overflow и headings.

## Task 5: Документация и verification

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: task plan/compliance/evidence files

- [x] Запустить related PHPUnit matrix и query-budget checks.
- [x] Запустить task-scoped Pint и `npm run build`.
- [x] Выполнить Playwright desktop/mobile и production SSR smoke.
- [x] Перечитать canonical requirements, проверить README last-H2 rule.
- [x] Выполнить targeted legacy/duplicate scan.
- [x] Обновить evidence и compliance statuses.

## Task 6: Exact delivery

- [x] Проверить `git status --short --branch` и branch `main`.
- [x] Сравнить exact task paths с lease manifest.
- [x] Не изменять и не смешивать foreign Task 107 index.
- [ ] Если exact isolated index возможен: stage explicit paths, review staged
  diff, `approve-index`, commit и push.
- [x] Если shared index/remote не позволяют безопасную доставку: сохранить
  `unresolved` с точным evidence без обхода hooks.
