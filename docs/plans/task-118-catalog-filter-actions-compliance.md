# Task 118 — compliance панели действий фильтров каталога

Дата: 27.07.2026.

## Scope

Вертикальная полноширинная компоновка действий «Показать … сериалов»,
«Отменить изменения» и «Сбросить фильтры» в единой форме `/titles`.

## Матрица

| Требование | Статус | Evidence |
| --- | --- | --- |
| Root instructions, requirement index и существующая реализация проверены до реализации | `completed` | `AGENTS.md`, `docs/requirements/index.md`, `/titles` Blade/Livewire/JS/tests |
| Вариант 1 утверждён пользователем | `completed` | [Design](../superpowers/specs/2026-07-27-catalog-filter-actions-design.md) |
| Подробный TDD/verification/delivery plan | `completed` | [Implementation plan](../superpowers/plans/2026-07-27-catalog-filter-actions.md) |
| Один mobile-first HTML/Livewire tree без второго filter draft | `already_compliant` | Изменяется только существующая action panel одной формы |
| Действия вертикальны и не зависят от viewport breakpoint внутри узкого sidebar | `unresolved` | Ожидает RED/GREEN реализации |
| Touch targets не меньше 44 px, keyboard/focus и длинный текст | `unresolved` | Ожидает PHPUnit и Playwright evidence |
| RU/EN interface parity | `already_compliant` | Новые строки и translation keys не добавляются |
| Routes, API, schema, cache keys и permissions | `already_compliant` | Публичные/data/access contracts не меняются |
| Производительность и passive Blade boundary | `already_compliant` | Новых данных, запросов, services или client state нет |
| Production rollout и rollback | `completed` | Зафиксированы в design-spec; data migration не требуется |
| README, CHANGELOG и canonical frontend docs | `unresolved` | Обновляются после GREEN verification |
| Commit и push в `main` | `unresolved` | Ожидает завершённый implementation commit |

## Ожидаемые изменяемые файлы

- `resources/views/components/catalog/unified-title-filters.blade.php`;
- focused PHPUnit-тест каталога;
- `tests/browser/catalog.spec.js`;
- `docs/UI_STANDARDS.md`, `docs/frontend.md`, `README.md`, `CHANGELOG.md`;
- task design, implementation plan, compliance и archive evidence.

## Защищённые contracts

- routes `titles.*`, query parameters, canonical/noindex и GET fallback;
- `CatalogSeries`, `CatalogSeriesFilters`, Livewire actions/state и islands;
- `data-catalog-filter-cancel`, `data-catalog-filter-submit-label` и
  существующий `mobile-runtime.js`;
- filter semantics, result count, pagination и browser history;
- translations, API Resources, schema, models, queries и cache identities;
- authentication, authorization, premium, region, legal, importer, SEO,
  sitemap, notifications и PWA media/cache policy.

## Риски совместимости

- `sm:flex-row` привязан к viewport и не учитывает фиксированную ширину
  `18rem`, поэтому его нельзя сохранять в action container;
- длинный динамический count и browser text scaling должны переноситься
  внутри primary CTA, не расширяя sidebar;
- cancel/reset/apply attributes нельзя потерять при изменении presentation;
- посторонние изменения `composer.lock` и `storage/debugbar/.gitignore`
  принадлежат пользователю и не входят в Task 118.
