# Task 105 — evidence удаления публичных исправлений каталога

Дата проверки: 26.07.2026. Ветка: `main`. Baseline перед Task 105:
`6d7d30ed`. Workspace содержит отдельный уже staged PWA-scope; он не является
частью Task 105 и должен быть исключён exact alternate index.

## Проанализированный contract

- Фактический runtime: PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3,
  Laravel Boost 2.4.13, PHPUnit 12.5.32, SQLite, Tailwind CSS 4.3.2 и Vite 8.
- Прослежены `routes`, full-page Livewire form/directory/admin, enum, policy,
  create/status/engagement actions, Eloquent scopes/route binding,
  presenter/SEO/query/notification boundaries, title/player builders и Blade,
  help-center escalation, demo stages, sitemap/cache policy, migrations,
  translations, PHPUnit и Playwright.
- До изменения публичные title/player views строили 11 field-level ссылок,
  обычный verified user мог выбрать correction type или подставить его в
  query/Livewire/action, а privacy зависела преимущественно от `is_public`.
  Help articles, demo rows, engagement и notifications продолжали считать
  исправление пользовательской заявкой.
- Сохранены public route names, enum/storage values, route model binding
  `ContentRequest` по `public_id`, admin moderation/status history, target
  resolver и catalog editor/import boundaries. Новая schema, package, queue,
  environment variable или public route не добавлены.

## Реализованная boundary

- `ContentRequestType` централизует `isAdministrativeOnly()`,
  `publicCases()` и stable administrative values.
- `ContentRequestPolicy`, `ContentRequestFormPage` и
  `CreateContentRequest` проверяют type-aware `manage-content-requests` до
  target resolution и повторно внутри action. Повторное использование
  submission token также authorizes persisted request, поэтому отозванное
  право не раскрывает старую correction-модель.
- Administrative corrections всегда private, не получают requester
  vote/follow и не bump-ят sitemap. Public binding, `publiclyVisible`,
  directory/My Requests, presenter, SEO и notification URL/recipients
  fail closed по type даже для historical `is_public = 1`.
- Admin queue/detail и approved/rejected moderation сохраняются. Resolver
  заново проверяет title/relation/episode ancestry и server-derived current
  value; poster URL не попадает в request context.
- `CatalogTitlePageBuilder`, `CatalogTitlePlayer` и обе public Blade views
  больше не готовят и не выводят correction URLs/controls.
  `CatalogCorrectionLinkBuilder` и
  `resources/views/components/content-requests/correction-link.blade.php`
  удалены; мёртвые action translations удалены.
- Public help allowlist исключает correction types, stored legacy subtype
  fail closed, demo rows private и notification sync их исключает.
- `PublicPageCachePolicy` использует title `response_contract = 3` и requests
  `response_contract = 2`, поэтому старый visitor HTML недостижим без
  store-wide flush.

## База данных и rollback

`2026_07_26_235600_restrict_catalog_corrections_to_administrators.php` —
guarded data-only migration. Она не меняет `content_requests`, schema или
индексы; только при точных content version/text/revision `1` переиздаёт две
исходные help-статьи как revision `2`, отключает calendar correction
escalation и удаляет два legacy alias. `down()` требует собственное
неизменённое состояние и восстанавливает aliases с исходным priority `97`.

Disposable SQLite roundtrip:

| Состояние | Результат |
|---|---|
| Fresh/up | обе статьи version `2`; calendar `none`; legacy aliases `0` |
| Rollback two latest migrations | обе статьи version `1`; calendar `content_request/metadata_correction`; RU/EN aliases priority `97` |
| Forward | versions `2,2`; legacy aliases `0` |

Временный `/tmp/seasonvar-task105-roundtrip-*` файл удалён проверяемым
cleanup; production database, `.env` и tracked data dump не изменялись.

Локальная read-only SQLite-проверка насчитала `633` заявок, `506` строк
public scope, `126` correction rows и `126` historical corrections с public
flag. `EXPLAIN QUERY PLAN` для фактической public фильтрации выбрал
`content_requests_public_status_idx`, затем bounded temporary sort для
20-row page. Новый индекс не добавлен: измеренного чтения, оправдывающего
write amplification, нет; exact correction identity уже использует
`active_identity_key`.

## Security, privacy и performance review

- SQL injection: все новые predicates используют enum values/query builder;
  raw user input в SQL не добавлен.
- XSS: correction prose проходит существующий `PlainText` factory и escaped
  Blade; regression удаляет script/tag и проверяет отсутствие raw HTML.
- CSRF/double submit: write остаётся Livewire POST и transactional UUID
  submission boundary; revoked permission повторно проверяется на idempotent
  lookup.
- IDOR/authorization: query string и Livewire state не являются правом;
  title/target ancestry и type проверяются до resolver и в action.
- Privacy: public route/query/cache/SEO/sitemap/My Requests/notifications
  блокируют административный type независимо от mutable public flag.
- Mass assignment: action собирает явный normalized DTO; requester, status,
  priority и publication client не задаёт.
- Производительность: удалена публичная подготовка URL для taxonomy/episode
  loops; новые queries в title/player render не добавлены. Directory остаётся
  bounded/paginated, admin resolver выполняет один точный target lookup.

## Verification

| Команда/проверка | Фактический результат |
|---|---|
| Initial RED focused run | 16 tests: 6 passed, 8 expected failures, 2 fixture setup errors; setup исправлен без production code |
| First GREEN focused run | 16/16, 73 assertions |
| Expanded focused/cache/help/demo | 22/22, 667 assertions |
| Related + translation parity | 59/59, 81 414 assertions |
| Final security/plan focused matrix | 43/43, 729 assertions |
| Scoped `Pint --format agent` | passed |
| `COMPOSER_ALLOW_SUPERUSER=1 composer analyse -- --no-progress` | passed, PHPStan errors `0` |
| `COMPOSER_ALLOW_SUPERUSER=1 composer rector:check` | Task 105 files clean; global dry-run exit `2` only из-за foreign `void → never` в двух collection files |
| `npm run build` | passed; Vite 8.1.4, 28 modules; player release ready, 29 sources/19 assets |
| Playwright correction spec | после замены хрупкого текста `403` на accessible H1: 3/3 passed — Desktop, Mobile, Tablet Chromium |
| Migration fresh/down/forward | passed; versions/escalations/aliases/priority проверены выше |
| `php artisan route:list --name=requests --except-vendor` | 10 existing request/admin/localized/sitemap routes |
| RU/EN request catalogs | `php -l` passed; parity включена в related suite |
| `php artisan project:docs-refresh --check` | exit `1`: требует foreign staged PWA update `docs/MAINTENANCE_LOG.md`; Task 105 его не переписывает |

Default full suite завершился раньше assertion summary из-за XML-enforced
`memory_limit=256M` в foreign `DemoRasterAsset`. Эквивалентный временный
PHPUnit config с `1G` выполнил 2 191 тест: 2 162 passed, 206 888 assertions,
11 skipped, 17 failures и 1 error. Единственный task-related failure был
`CurrentPlanPolicyScriptTest`; после восстановления обязательных registry
sections он и `CatalogFieldCorrectionTest` прошли 36/36. Оставшийся baseline
совпадает с предыдущим Task 102 evidence: 16 foreign failures в offline PWA,
catalog/search/legacy tags/rate limit/title merge/account session surfaces и
foreign error отсутствующего `SeasonvarImportDispatchBatcher`. Они не
исправлялись несвязанным refactor.

## Rollout и recovery

Перед production migration нужны verified SQLite backup и короткое
stopped-writer window. После code/migration/assets применяются обычные
config/route/view cache rebuild и graceful process reload. Store-wide
`cache:clear`, новая queue или data rewrite не нужны. При stale HTML новая
response-contract dimension делает старый envelope недостижимым. При partial
deploy возвращается совместимый code; historical correction rows не удаляются
и `is_public` вручную не меняется. Guarded `down()` пропускает редакторски
изменённые статьи; предпочтителен roll-forward с сохранением evidence.

Git exact index, reviewer, commit и обычный push выполняются после последней
проверки diff. Remote/auth или clean-tree отказ должен остаться
`unresolved`, а не выдаваться за успешную доставку.
