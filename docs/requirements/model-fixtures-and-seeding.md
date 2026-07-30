# Канонические требования к factories, fixtures и seeders

Обновлено: 30.07.2026.

Этот файл — единственный постоянный владелец правил создания model fixtures
и seed data. Он не меняет доменную семантику моделей из `docs/models.md` и не
разрешает seeders создавать production-каталог вместо
`php artisan seasonvar:import`.

## Статусы и evidence

Требование считается выполненным только при наличии implementation path и
passing verification. Допустимые task statuses определены в task-specific
compliance matrix: `completed`, `already_compliant`, `not_applicable`,
`unresolved`.

## Требования

| ID | Проверяемое требование | Authorization и validation | Data, security и production | Localization, accessibility и performance | Implementation / test evidence |
| --- | --- | --- | --- | --- | --- |
| `seed-model-001` | Каждая first-party Eloquent model имеет valid-by-default factory либо точное документированное exemption, если самостоятельная запись не является валидным domain scenario. | Factory не обходит policy как application mutation; входные overrides всё равно должны удовлетворять enum/FK/check/unique contracts. | Default factory не использует production DB, сеть, реальные credentials или personal data. Exemption называет модель и причину. | Текстовые fixtures поддерживают Unicode; создание одной записи не порождает скрытый большой graph. | `database/factories`; model factory coverage matrix; factory creation test. |
| `seed-model-002` | Каждое meaningful enum/workflow состояние модели имеет named factory state; бессмысленные generic states не создаются. | State не подменяет authorization и не создаёт невозможный transition. | State сохраняет FK, unique, date ordering, money, tenant/owner и privacy invariants. | Localized storage values не заменяются translated labels; edge states остаются bounded. | Named factory methods; state inventory; per-state tests. |
| `seed-model-003` | Relationship graph создаётся явно через named helpers или `for`/`has`/`recycle`; default factory не скрывает неограниченные children. | Owner/tenant/actor relationship задаётся явно для access-control scenarios. | Повторное использование parent не создаёт случайного второго owner/tenant; fixtures не зависят от public internet. | Графы имеют deterministic bounded size. | Factory relationship helpers; feature tests. |
| `seed-fixture-001` | File/image fixtures генерируются локально на test disk и соответствуют реальному MIME/content contract. | Private fixture download по-прежнему требует policy/signed boundary. | Оригинальные user filenames, EXIF/private metadata и production paths не используются. | Image dimensions соответствуют проверяемому UI scenario без чрезмерного размера. | Test fixture helpers, storage tests. |
| `seed-ref-001` | Fixed reference seeders повторяемы и используют stable natural keys, explicit IDs или safe upsert/update-or-create semantics. | Reference seeder не выдаёт role/permission существующему user без явного contract. | Повторный запуск не создаёт duplicate rows, не truncate/delete и не изменяет imported/user data. | Stable codes не переводятся; localized display data сохраняется по существующей locale architecture. | Reference seeders; idempotency/constraint tests. |
| `seed-demo-001` | Development/demo seeders разрешены только в `local`, `development`, `dev` или `testing` и fail closed в любом другом environment. | Demo accounts не получают production access; роль/permission каждого demo actor явна. | Проверка environment выполняется до первой записи. Known demo credentials никогда не создаются в production. | Demo graph покрывает ru/en, empty/normal/error/archived states и остаётся bounded. | `DatabaseSeeder`/demo orchestrator; production safeguard test. |
| `seed-demo-002` | Demo credentials и synthetic identities не являются реальными personal data и документируются только как non-production. | Login возможен только там, где demo seed явно разрешён; blocked/ordinary/admin scenarios разделены. | Пароли не переиспользуются как production default; seeders не читают secrets. | Русский/английский UI отображает fixtures через обычный translation/presentation path. | Development docs; authentication/render tests. |
| `seed-run-001` | `DatabaseSeeder` оркестрирует безопасный порядок reference → roles/permissions → development/demo и остаётся безопасным при повторном запуске. | Сначала создаются authority records, затем actors/owned data. | Production run не стирает данные и не создаёт demo actors/graph; partial failure не маскируется. | Объём и время seed run измеримы и bounded; optional volume profile не является default. | `DatabaseSeeder`; fresh/repeat/production tests. |
| `seed-test-001` | Автоматическая проверка доказывает factory/exemption coverage, factory/state validity, fresh seed, repeated fixed seed, FK/unique/check integrity и production safeguard. | Critical role/owner/non-owner states проверяются feature tests, а не только factory unit test. | Все tests используют isolated testing database/storage и блокируют stray external HTTP. | Main seeded pages проверяются для поддерживаемых locale и representative viewport только там, где UI зависит от data graph. | PHPUnit factory/seeder/feature tests; applicable Playwright smoke. |

## Валидные exemptions

Exemption допустим только для конкретной модели и одной из причин:

- row создаётся только database engine/migration/trigger и direct factory
  нарушила бы lifecycle;
- модель является read-only projection без independent insert contract;
- модель является internal pivot/ledger child, который можно корректно
  создать только через factory aggregate root, и этот helper/test указан;
- table intentionally отсутствует в current supported schema.

Нельзя использовать exemption только потому, что factory сложна, у модели
много обязательных полей или тесты сейчас создают row вручную.

## Обязательная coverage matrix

Task, меняющий модели или seeding, поддерживает matrix:

| Model | Factory или exemption | Meaningful states | Graph helpers | Seeder | Tests |
| --- | --- | --- | --- | --- | --- |
| Точное имя класса | Точный path/reason | Named methods | Named methods | Seeder path / not applicable | Test path/command |

Matrix должна строиться из фактического model inventory и не отмечать
factory существующей только по имени файла: factory обязана создать валидную
row на fresh test schema.

## Production и rollback

- Seeders никогда не выполняют `truncate`, `migrate:fresh`, массовое delete
  или reset production data.
- Production catalog data не сидируются: единственный public import entry
  остаётся `php artisan seasonvar:import`.
- Schema migration и seeding выполняются отдельными rollout steps.
- Rollback factory/test code не требует data restore. Rollback reference
  data описывает exact rows и не удаляет user/imported data без отдельного
  approved recovery plan.
