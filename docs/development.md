# Разработка

Обновлено: 19.07.2026

## Постоянный execution workflow

### До implementation

1. Подтвердить, что текущая ветка — существующая `main`; другую ветку не создавать.
2. Проверить `git status` и отделить protected pre-existing work от scope задачи.
3. Прочитать `AGENTS.md`, `docs/requirements/index.md`, все обязательные requirement owners и все релевантные Markdown-файлы.
4. Проверить фактические Composer/npm/runtime versions и релевантную официальную version-specific документацию.
5. Проверить existing routes, models, migrations, services/actions/query objects, translations, authorization, caching, frontend components и administration implementation.
6. Выявить migrations, routes, translations, cache keys, permissions, public contracts и backward-compatibility/data/production risks.
7. Обновить `docs/plans/current-task-plan.md`, requirement-compliance matrix, expected changed files и files/contracts that must remain compatible.
8. Начинать application implementation только после подготовки, reread плана и закрытия requirement-file completion gate.

### Во время implementation

1. Немедленно обновлять план, если discovery меняет scope, risk или решение.
2. Сохранять public contracts и делать небольшие coherent changes.
3. Удалять duplication только после полного поиска dependants и подтверждения canonical replacement/adapter.
4. Обновлять translations вместе с UI, authorization вместе с actions, cache invalidation вместе с mutations и documentation вместе с architecture.
5. Не оставлять temporary debugging, fake controls, dead actions, unfinished TODO/FIXME markers или необъяснённые compatibility shims.

### До завершения

1. Перечитать применимые requirement owners и исходную задачу; повторно сверить compliance matrix.
2. Проверить changed files и связанные unchanged files, routes, migrations/schema, queries/indexes, authorization, translations и caches.
3. Проверить responsive behavior, accessibility, security, privacy, loading/empty/error states и backward compatibility.
4. Выполнить scoped automated/static/build/browser/manual verification, предусмотренную текущими requirements; failures не скрывать.
5. Обновить тематические Markdown owners, при применимости `README.md`, русский `CHANGELOG.md` и completed current-task evidence; удалить temporary files.
6. Перед commit/push снова проверить status/branch. Commit выполняется только в `main`, затем отправляется configured remote; внешний отказ фиксируется честно.
7. Финальный report содержит summary, validation evidence, compatibility result, exact commit и push status.

## Обязательный upgrade decision record

До изменения dependency/runtime в [`maintenance/update-decisions.md`](maintenance/update-decisions.md) фиксируются: dependency/runtime, current/proposed version, direct/transitive scope, purpose, reason, security/maintenance relevance, compatibility requirements, affected files/features, config/database/assets/production changes, deprecated/replacement APIs, backward compatibility, rollback, verification и решение `update|retain|replace|remove`.

Обязательный staged workflow:

1. Обновить requirements.
2. Инвентаризировать текущий state и lock hashes.
3. Найти официальное version-specific guidance.
4. Зафиксировать breaking changes.
5. Найти deprecated APIs.
6. Составить affected portal-module map.
7. Обновить current task plan/compliance matrix.
8. Выполнить smallest coherent change.
9. Обновить configuration.
10. Обновить application code.
11. Проверить translations, если затронуты.
12. Проверить cache identities/invalidation.
13. Обновить production requirements.
14. Обновить deployment и rollback.
15. Выполнить доступную разрешённую verification.
16. Повторно найти оставшееся old-API usage.
17. Обновить inventory/compatibility/registries.
18. Обновить changelog.
19. Commit только в `main`.
20. Push configured remote.

Lock files не меняются без анализа причины. Проверяется каждый direct и существенный transitive change; unrelated upgrades не принимаются внутри lock rewrite. Lock files не удаляются для принудительного resolution и не создаются другим package manager без explicit migration plan.

## Обязательный production-impact review

До production-affecting реализации:

1. Проверить `composer.json`, `composer.lock`, `package.json` и фактически используемый frontend lock file.
2. Проверить PHP/Node constraints, требуемые PHP extensions, Vite и build output.
3. Проверить filesystem disks, cache, session, queue, mail, database и logging configuration.
4. Проверить trusted proxies, URL/HTTPS configuration, scheduler, service worker, deployment/backup scripts и server documentation.
5. Зафиксировать writable directories, maintenance decision, rollback, data backup, cache invalidation и external-provider risks в current task plan.

Перед production completion:

1. Проверить совместимость config, route, view и event caches только доступными безопасными командами.
2. Проверить Vite manifest/assets, writable storage и наличие application key без вывода значения.
3. Проверить database/cache/session/storage через существующие side-effect-free diagnostics; mail/payment/webhook — только configuration state без несанкционированной отправки или оплаты.
4. Проверить service-worker versioning/exclusions, maintenance/rollback runbooks, документацию и changelog.
5. Commit/push выполнять только из `main` после полного `git status` review.

## Обязательный cross-feature impact checklist

До implementation для каждой затронутой возможности обновите current task plan и проверьте: routes; models; migrations; relationships; actions; services; queries; policies; gates; Livewire state; Blade presentation; JavaScript modules; translations; cache keys; cache invalidation; search indexes; SEO; sitemap; structured data; notifications; audit; administration; mobile и accessibility; authentication; sessions; premium; payments; region; legal restrictions; advertiser rules; imports; account merge; account deletion; data export; backward compatibility.

Для каждого пункта фиксируется `affected`, `unaffected`, `not_applicable` или `unresolved` с причиной. Текстовый поиск, успешная установка dependency или отсутствие runtime error сами по себе не являются доказательством совместимости.

## Локальная установка

Требования для разработки:

- PHP 8.5 с `pdo_sqlite`, `sqlite3`, `mbstring`, `dom`, `fileinfo`, `redis` и `memcached`.
- Composer 2.
- Node 26 и npm 12.
- SQLite для локальной базы и тестов.
- Redis и Memcached для production-like integration tests, health и cache benchmarks; обычный PHPUnit остаётся воспроизводимым с array store.

`config/livewire.php` явно фиксирует `make_command.type=class`: новые компоненты продолжают существующий `app/Livewire` + Blade pattern. Package defaults SFC/MFC и Volt не используются; generator также не создаёт отдельные JS/CSS/test files без осознанного task-specific решения.

Базовая ручная установка:

```bash
composer install
composer hooks:install
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

Если `.env` уже существует, не перезаписывайте его без причины. Для локального SQLite можно оставить `DB_DATABASE` пустым: Laravel использует `database/database.sqlite`.

`composer setup` доступен как укрупненный wrapper для установки зависимостей, генерации ключа, миграций и frontend build. Для полностью чистого SQLite checkout ручной вариант выше явно создает файл базы перед миграциями.

## Локальный запуск

```bash
composer dev
```

`composer dev` запускает Laravel server, `queue:listen --tries=3 --timeout=900`, Pail logs и Vite. Если нужен только фронтенд, используйте `npm run dev`.

Laravel Debugbar установлен только через `require-dev`. В доверенной локальной среде панель автоматически отображается при `APP_DEBUG=true`; при `APP_DEBUG=false`, а также в `production` и `testing`, package guard не регистрирует диагностические routes/listeners. Отдельные `DEBUGBAR_ENABLED` и `DEBUGBAR_FORCE_ALLOW_ENABLE` проектом не поддерживаются: единственный переключатель — `APP_DEBUG`, а `force_allow_enable` зафиксирован как `false` в `config/debugbar.php`.

После изменения `APP_DEBUG` пересоберите config cache и локальный route cache в том же окружении либо удалите локальный route cache перед запуском, затем перезапустите длительно работающий PHP process. Route cache, созданный при выключенном Debugbar, намеренно не содержит его служебных маршрутов. Реальный `.env` не перезаписывается приложением. Debugbar может показывать SQL bindings, request/session context и замедлять ответы, поэтому его нельзя открывать на публичном endpoint или использовать как production monitoring.

### Полное демонстрационное наполнение

В среде `dev` обычная команда `php artisan db:seed` после локальных `admin@example.com` и `user@example.com` запускает `PortalDemoSeeder`. Он создаёт или обновляет ровно 100 подтверждённых пользователей `user1@example.com`–`user100@example.com` с паролем `password` и детерминированно заполняет пользовательские состояния ровно для половины опубликованного каталога на каждого пользователя.

Корпус включает профили и настройки, устройства, личные теги и коллекции, оценки и все состояния просмотра, прогресс, рецензии, комментарии и диалоги, 3–10 заявок на контент, жалобы и ограничения, технические обращения, уведомления и квитанции автономной синхронизации. Avatar и cover профиля сохраняются как приватные WebP `320×320` и `1280×360`, обложки коллекций — как WebP `960×540` в путях, разрешённых соответствующими responders. Повторный запуск с той же версией идемпотентен и может продолжить прерванное наполнение без удаления импортированного каталога или provider-рецензий.

Перед первой записью большого корпуса проверяются среда, обязательная схема и свободное место: к расчётной ёмкости добавляется резерв `demo-data.minimum_free_bytes` из `config/demo-data.php`. После всех этапов агрегатный аудит проверяет половинное покрытие, дубли, взаимодействия с собственным контентом, enum-наборы, хронологию, заявки/теги/коллекции каждого владельца, фактический WebP MIME и разрешённые storage prefixes. Полный запуск разрешён только в `dev|testing`; `production` отклоняется до записи. Для уменьшенного тестового профиля используется тот же код с переопределёнными значениями config.

Прямой запуск и повторная проверка идемпотентности:

```bash
php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
```

Состояние уже существующего набора проверяется без записи командой `php artisan demo:repair-user-portal --dry-run --json`. Ограниченный repair разрешён только точному набору `user1@example.com`–`userN@example.com`; в production он дополнительно требует проверенный backup, остановленных writers и явные `--force --backup-confirmed --writers-paused`. Прогрев owner-scoped snapshots одного пользователя выполняется синхронно через `php artisan cache:warm-user-portal <public-id|username|email> --refresh`, а два и более идентификатора немедленно ставятся в `cache-warm-v2`. Опция `--all-demo` использует тот же точный allowlist `user1@example.com`–`userN@example.com` из `demo-data.user_count`, а не wildcard по похожим адресам.

## Git workflow

- Единственная рабочая ветка проекта — существующая `main`.
- Не создавать feature branches, временные ветки, worktree-ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя.
- Если локальный checkout оказался на другой ветке, сначала безопасно перенесите незакоммиченные изменения на `main`, затем продолжайте работу только в `main`.
- Перед commit или push выполните `git status --short --branch` и проверьте, что текущая ветка — `main`.
- Не коммитьте и не отправляйте изменения из веток, отличных от `main`.
- Не оставляйте рабочее дерево грязным после задачи: разрешенные изменения должны быть закоммичены, а чужие/посторонние незакоммиченные изменения нужно явно отметить как блокер.
- Установить версионируемые hooks: `composer hooks:install`. Команда локально задаёт `core.hooksPath=.githooks`; `composer setup` выполняет её автоматически.
- `pre-commit` блокирует commit вне `main`, unresolved conflicts, staged временные/debug-файлы, staged `.env`/credential paths, unstaged tracked changes и untracked files.
- После проверок чистоты `pre-commit` запускает общий read-only профиль `bash scripts/ci-check.sh docs`. Профиль фиксирует публичную базу управляемых ссылок через `PROJECT_DOCS_PUBLIC_BASE_URL`, поэтому временный `APP_URL` тестового сервера не меняет sitemap-origin в tracked Markdown. Устаревшие управляемые блоки исправляются только явным `php artisan project:docs-refresh` и повторным review; hook не меняет и не stage-ит файлы.
- `pre-commit` также проверяет, что обычный текст `README.md` написан по-русски, содержит дорожную карту, заканчивается пользовательской историей и включён в staged diff при изменении кода, конфигурации, маршрутов, миграций, интерфейса или зависимостей.
- `pre-commit` проверяет staged-версию `CHANGELOG.md`: обычный текст подробного технического журнала должен быть русским, а точные технические обозначения могут сохраняться в исходном написании. Сокращать, объединять или удалять прежние записи нельзя.
- После каждого запроса нужно проверять актуальность `README.md`; датированная запись добавляется только при реальном изменении возможностей или дорожной карты, без пустых записей ради даты.
- `pre-push` повторно проверяет `main`, unresolved conflicts и уже tracked временные/credential paths, требует clean working tree, затем запускает общий профиль `bash scripts/ci-check.sh pre-push` для backend и frontend.
- Проверки только читают Git state и печатают причину отказа. Они не добавляют файлы, не исправляют код, не удаляют изменения и не зависят от персональных абсолютных путей. `.env.example` явно разрешён; реальные `.env`, private keys и credential JSON должны храниться вне Git.
- Проверка secrets намеренно лёгкая и основана на очевидных именах путей; она не заменяет review staged diff или полноценный secret scanner CI для произвольно названных файлов.
- `post-commit` запускает только управляемое обновление Markdown через `project:docs-refresh`; исходный PHP/Blade/JS код hook не редактирует, auto-push выключен без явного `SEASONVAR_DOCS_AUTO_PUSH=1`.

Проверить установку без изменения файлов:

```bash
git config --local --get core.hooksPath
bash -n .githooks/pre-commit .githooks/pre-push .githooks/post-commit .githooks/lib/git-guard.sh scripts/check-readme-policy.sh scripts/check-changelog-policy.sh
php -l scripts/check-changelog-policy.php
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
```

## Команды проекта

- `php artisan seasonvar:import` — единственная публичная команда импорта Seasonvar.
- `php artisan seasonvar:import --forever --sleep=60` — непрерывный локальный цикл импорта.
- `php artisan seasonvar:import "https://seasonvar.ru/..." --force` — принудительное обновление одной страницы.
- `php artisan catalog-collections:sync-hdrezka --dry-run` — bounded read-only обход внешних редакционных подборок и проверка сопоставления с локальными тайтлами; требует включённый `HDREZKA_COLLECTION_SYNC_ENABLED` и не относится к Seasonvar import.
- `php artisan integrations:doctor` — read-only диагностика MCP, Google, CLI tools и проектных skills без вывода секретов.
- `php artisan app:health` — operational health по DB, Redis workloads, Memcached, workers и прогреву; `degraded`/`failed` возвращают ненулевой exit, даже если HTTP traffic readiness ещё true.
- `php artisan app:failed-job-audit` — bounded read-only сопоставление historical finalizer rows с текущими run/group/claim states; `--json` даёт safe evidence, `--samples=0..10` ограничивает ID-only примеры на состояние. Команда не retry-ит, не забывает, не очищает и не dispatch-ит jobs.
- `php artisan cache:warm-catalog` — синхронный bounded warm; `--queue` ставит unique job в `cache-warm-v2`, а `--refresh` планово пересобирает текущие warmable keys под lock, не удаляя читаемый snapshot до успеха. Историческая `cache-warm` не используется новым worker и не очищается автоматически.
- `php artisan cache:metrics` — low-cardinality hit/miss/rebuild/failure snapshot без raw keys.
- `php artisan api:sync-prune` — bounded очистка transport-журнала offline-sync: changes старше 30 дней и idempotency receipts старше 90 дней; до additive migration завершается безопасным no-op.
- `php artisan google:search-console:summary` — read-only сводка Search Console, если Google credentials настроены вне Git.
- `php artisan google:analytics:summary` — read-only сводка GA4, если Google credentials настроены вне Git.
- `php artisan project:docs-refresh` — обновляет управляемые блоки документации.
- `php artisan project:docs-refresh --check` — проверяет документацию без записи изменений, включая repository-relative Markdown links и migration inventory.
- `composer rector:check` — обязательный read-only Rector-профиль для всего собственного PHP-кода; ненулевой exit означает новый неприменённый diff или ошибку анализа.
- `composer rector:fix` — явно применяет только обязательный профиль; после него нужно просмотреть diff, запустить Pint, Larastan и релевантные тесты.
- `composer rector:max` — максимальный стабильный dry-run с PHP, Laravel 13, PHPUnit, type/dead-code/quality/style/naming/privatization наборами; команда намеренно может завершиться с exit `2`, если показывает плановый долг.

Каждый `rector:*` script сначала вызывает `Composer\\Config::disableProcessTimeout`: это снимает только стандартный 300-секундный лимит дочернего Composer-процесса. Ограничения Rector worker, GitHub Actions job и внешнего runner сохраняются; для других Composer scripts тайм-аут не меняется.
- `composer analyse` — запускает bounded Larastan/PHPStan по DTO, enums и критичным operational/admin boundaries без baseline и ignored errors.

## Проверки

```bash
composer ci:check
bash scripts/ci-check.sh backend
bash scripts/ci-check.sh docs
bash scripts/ci-check.sh frontend
bash scripts/ci-check.sh browser
composer test
composer rector:check
composer rector:max
composer analyse
php artisan test --compact
./vendor/bin/pint --dirty --format agent
npm run build
php artisan project:docs-refresh --check
```

Для изменений синхронизации редакционных подборок сначала запускайте focused contract `php artisan test --filter=HdRezkaCollection`, затем recommendation tests, полный backend suite и frontend build. Внешний HTTP в PHPUnit всегда закрывается через `Http::preventStrayRequests()` и fixtures/`Http::fake()`; тесты не должны обращаться к живому HDRezka.

`scripts/ci-check.sh` является единым источником команд для локальной проверки, `pre-commit`, `pre-push` и GitHub Actions. Профили `docs`, `backend`, `frontend`, `browser`, `pre-push` и `full` используют одинаковые шаги в каждой среде; Laravel config/route/view cache проверяется только в ignored `output/ci`, поэтому локальный запуск не заменяет рабочий production cache. `docs` использует SQLite `:memory:` и проверяет managed Markdown без записи. Все профили изолируют maintenance state через process-local `cache` driver и `array` store: существующий `storage/framework/down` не даёт ложные `503` в тестах, но файл режима обслуживания не изменяется и production из него не выводится. Backend выполняет `composer rector:check` после Pint и до синтаксиса/Larastan; CI никогда не запускает `rector:fix`. Производный файловый кеш находится в ignored `output/rector`, не содержит исходных данных и может быть удалён без изменения приложения. GitHub Actions выполняет три отдельных задания на явном `ubuntu-24.04`, использует immutable action SHA без сохранения checkout credentials и загружает Playwright-отчёт даже при ошибке браузерной проверки.

`EagerLoadProjectionContractTest` статически проверяет все literal `with()`, `load()` и `loadMissing()` в `app/`: новая связь должна использовать colon projection или closure `select()` с ключами сопоставления. Runtime `EagerLoadProjectionTest` дополнительно проверяет SQL публичных тегов, account export подборок и tag administration. Полный aggregate `SeasonvarTitleMerger` — явное mutation-only исключение; расширять allowlist без отдельного архитектурного обоснования нельзя.

Pest и `npm run lint` сейчас не установлены. Rector 2 использует `rector.php` как нулевой обязательный gate, а `rector-max.php` сохраняет весь текущий модернизационный backlog видимым без baseline. Правила из первого полного аудита, затрагивающие сотни файлов (`readonly`, типы констант, PHP callables и Laravel property attributes), перечислены по классам только в skip обязательного профиля и остаются активными в maximum; их следует применять отдельными небольшими партиями. Larastan/PHPStan применяется как ограниченный high-value gate; расширять paths нужно постепенно и только с нулём ignored errors.

### Добавление interface translations

1. Используйте существующий semantic domain (`home.*`, `catalog.*` и т. п.); не добавляйте JSON catalog, package или DB row для source-code UI text.
2. Добавляйте key во все locale из `config/catalog-collections.php`. Named placeholders и plural structure должны совпадать; dynamic/provider/user text никогда не передаётся как translation key.
3. Для homepage dates используйте `AccountDateTimeFormatter`, для чисел `Number::format(..., locale: app()->currentLocale())`, для nouns `trans_choice` с уже форматированным named `:count`.
4. Статически выполните `php -l` для изменённых catalogs, рекурсивную key/placeholder parity сверку, scan изменённых Blade/PHP/JS на hardcoded user copy, route inspection с актуальным route source, Pint, Vite build и `project:docs-refresh --check`. Missing runtime key безопасно падает в configured `ru`, но новая parity-проверка не должна оставлять такой долг.
5. Изменение PHP translation, используемого public home/layout, автоматически меняет translation fingerprint в full-response key; DB content translation инвалидируется через существующий Homepage/domain version path. После translation deploy выполняется обычный bounded catalog warm, который строит обе locale variants; store-wide flush не нужен.

### Изменение центра помощи

Полные help articles редактируются в canonical DB/admin boundary, а не в Blade/PHP translation. Initial immutable corpus меняют только до первого production rollout; после него используется новая revision через `/admin/help`. Interface key добавляется синхронно в `lang/ru/help.php` и `lang/en/help.php` с parity placeholders.

Перед завершением проверьте enum/category/route allowlists, sanitizer/link validation, draft/internal exclusion, locale fallback, cache/SEO/sitemap и Task 19/20 links по [`help-center.md`](help-center.md). Новую очередь, cron, external search/CMS или article media package без отдельного решения не добавлять.
