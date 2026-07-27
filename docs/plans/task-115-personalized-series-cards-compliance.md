# Task 115 — compliance заметных карточек персональных рекомендаций

Дата: 27.07.2026.

## Scope

Светло-зелёный фон секции «Сериалы каталога» на
`/discover/personalized`, белые карточки и постоянно заметное основное
действие перехода к сериалу.

## Матрица

| Требование | Статус | Evidence |
| --- | --- | --- |
| Requirements и текущая реализация прочитаны до реализации | `completed` | `AGENTS.md`, `docs/requirements/index.md`, UI/frontend/recommendation owners и production render |
| Утверждённый зелёный visual direction | `completed` | [Design](../superpowers/specs/2026-07-27-personalized-series-cards-design.md) |
| Светлая тема без gradient/glass/inline CSS | `completed` | [Task 115 evidence](archive/2026-07-27-personalized-series-cards-evidence.md) |
| Постоянно видимая CTA и touch target не меньше 44 px | `completed` | PHPUnit RED/GREEN и focused Playwright `3/3` |
| Mobile/desktop layout и отсутствие overflow | `completed` | Production `1440×1200`/`390×844`, overflow `0` |
| RU/EN translation parity | `completed` | `TranslationCatalogParityTest` входит в GREEN matrix |
| Routes, API, schema, cache keys и permissions | `already_compliant` | План не меняет public/data/access contracts |
| Производительность и Blade query boundary | `already_compliant` | Новых данных, запросов и JavaScript не планируется |
| Production rollout и rollback | `completed` | Зафиксированы в design-spec; data migration не требуется |
| README, CHANGELOG и canonical frontend docs | `completed` | Visitor history и UI/frontend owners обновлены; полный CHANGELOG gate пройден |
| Commit и push в `main` | `unresolved` | Exact combined commit `cc77728a`; GitHub HTTPS push запросил username при отсутствии credential helper |

## Ожидаемые изменяемые файлы

- `resources/views/livewire/catalog-discovery-page.blade.php`;
- `resources/views/components/catalog/title-card-recommendation.blade.php`;
- `lang/ru/catalog.php`, `lang/en/catalog.php`;
- focused tests карточки и discovery layout;
- `tests/browser/recommendation-ui.spec.js`;
- `docs/UI_STANDARDS.md`, `docs/frontend.md`, `README.md`, `CHANGELOG.md`;
- task design, implementation plan, compliance и archive evidence.

## Защищённые contracts

- routes и route names, включая `titles.show`;
- Livewire actions/state, query string и пагинация discovery;
- recommendation ranking, reasons, feedback и privacy;
- API Resources, schema, model relations и eager-load boundaries;
- cache keys/invalidation и service-worker policy;
- authentication, authorization, premium, region и legal access;
- SEO metadata, canonical URLs и public collection order.

## Риски совместимости

- shared recommendation-card используется вне
  `/discover/personalized`; заметная CTA должна оставаться совместимой с
  title-detail recommendations;
- nested stretched title link не должна перекрыть отдельную CTA;
- card shell не должен поглотить feedback controls или создать horizontal
  overflow;
- staged/unstaged изменения Task 113 и Task 114 нельзя включать в Task 115.
