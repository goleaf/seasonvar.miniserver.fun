# Каталог сериалов Seasonvar

Laravel-приложение для локального каталога сериалов, сезонов, серий, постеров, связей каталога, отзывов и внешних видео-ссылок.

## Версии

- Laravel 13.19 на PHP 8.5.
- Laravel Sanctum 4.3 хранит expiring hashed tokens для версионированного mobile API.
- Laravel Boost 2.4 и Laravel MCP 0.8 используются для локальной документации и MCP-подсказок.
- Livewire 4.3 используется для интерактивного каталога `/titles`, карточки тайтла, регистрации и входа, профиля и безопасности, объединённой личной библиотеки `/library/*` и live-страницы статистики `/stats`.
- Livewire-экран `/admin/imports` доступен только авторизованным users из `SEASONVAR_IMPORT_ADMIN_EMAILS`; он ставит coordinator job в Redis, но не выполняет importer в HTTP-запросе.
- Livewire-экран `/admin/catalog` использует тот же allowlist для редакционных полей, публикации, связей, сезонов, серий и безопасных видеоисточников; правила конкурентного редактирования и деплоя описаны в `docs/administration.md`.
- PHPUnit 12.5 используется для тестов; Pest в проекте не установлен.

## Mobile JSON API

`GET /api` публикует discovery для стабильного `/api/v1`, а project-owned OpenAPI доступен по ссылке из манифеста. Public v1 включает home, полный фильтрованный каталог, schema-driven filters, 11 directories, карточку тайтла, сезоны/серии с безопасными media profiles, подсказки, рекомендации и read-only отзывы. Mobile auth добавляет регистрацию, login, queued email verification/password reset, 90-дневные device tokens с rotation/logout и self-service `/me`. Private v1 также отдаёт owner-scoped watchlist, оценки, состояние тайтла, Continue Watching, историю и компактную сводку `/me/library/summary`; watchlist/ratings поддерживают поиск, тип, год и явную сортировку. Чтение требует `mobile:read`, изменения — `mobile:write` и verified email, все ответы private/no-store. Offline-sync v1 добавляет manifest/checkpoint, public и owner-scoped incremental pull, а также идемпотентный batch push с optimistic версиями и 30/90-дневным retention. Playback v1 создаёт безопасные same-origin sessions, выдаёт provider source только через короткоживущий signed encrypted grant и записывает verified monotonic progress без раскрытия raw media URL; скачивание/offline playback видео не поддерживается. Полный route/query/response/security contract находится в [`docs/api.md`](docs/api.md).

## Заявки на недостающие материалы

Публичный каталог `/requests` объединяет заявки на отсутствующий сериал, сезон, серию, перевод, субтитры, улучшение качества, исправление метаданных/списка серий и восстановление недоступного материала. Создание, голосование, подписка и личный список требуют подтверждённую учётную запись; публичная карточка использует стабильный UUID и не раскрывает email, внутренний user ID, private evidence, moderator notes или техническое состояние импортера.

Форма сначала ищет существующий каталог и открытые заявки, затем сервер повторно проверяет canonical title/season/episode/media, нормализованные названия, внешние ID и exact active identity. Модераторская очередь `/admin/requests` использует существующий admin allowlist и единственный `seasonvar:import`: отдельный ticket/importer не создаётся. Статусы, merge, clarification, partial/full completion, requester/voter/follower notifications, cache/SEO/sitemap и rollout описаны в [`docs/architecture.md`](docs/architecture.md), [`docs/authorization.md`](docs/authorization.md) и общем плане [`docs/plans/laravel-video-portal-modernization.md`](docs/plans/laravel-video-portal-modernization.md).

## Основные команды

```bash
composer install
composer hooks:install
composer setup
composer dev
npm install
php artisan seasonvar:import
php artisan seasonvar:import --inventory-only
php artisan seasonvar:import --no-discovery --page-type=rss
php artisan seasonvar:import "https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html" --force
php artisan seasonvar:import --forever
php artisan seasonvar:import --queued
php artisan seasonvar:import --status
php artisan seasonvar:import --refresh-media-sizes
php artisan integrations:doctor
php artisan app:health
php artisan cache:warm-catalog
php artisan cache:metrics
php artisan api:sync-prune
php artisan catalog-collections:prune
php artisan project:docs-refresh
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run dev
npm run build
```

Единая карта проектной документации находится в [`docs/README.md`](docs/README.md). Полная локальная установка, MCP и правила разработки описаны в [`docs/development.md`](docs/development.md) и [`docs/mcp.md`](docs/mcp.md). Cache topology и environment contract находятся в [`docs/caching.md`](docs/caching.md) и [`docs/environment.md`](docs/environment.md). Внешние интеграции документируются в [`docs/integrations/google.md`](docs/integrations/google.md) и [`docs/integrations/mcp-catalog.md`](docs/integrations/mcp-catalog.md).

Датированный Laravel/Livewire/video audit, environment preflight, security/performance findings и MCP verification находятся в [`docs/audits/current-state-audit.md`](docs/audits/current-state-audit.md) и [`docs/tooling/mcp-setup.md`](docs/tooling/mcp-setup.md). Они дополняют, но не заменяют тематические документы-владельцы.

## Локальные учётные записи

Создать или обновить локальные демонстрационные учётные записи:

```bash
php artisan db:seed
```

- Администратор: `admin@example.com` / `password`.
- Пользователь: `user@example.com` / `password`.

Для административного доступа email должен находиться в существующем allowlist: `SEASONVAR_IMPORT_ADMIN_EMAILS=admin@example.com`. Эти данные предназначены только для локальной разработки; не используйте их в production.

## Импорт

`seasonvar:import` скачивает карту сайта Seasonvar, сохраняет найденные страницы, обновляет карточки, сезоны, серии, связи, рейтинги, отзывы и видео. Команда продолжает работу после ошибки отдельной страницы, пишет подробные события в базу и может работать постоянно через `--forever`.

`php artisan seasonvar:import --inventory-only` рекурсивно читает только sitemap XML/gzip, типизированно классифицирует разрешённые URL и сохраняет parity-снимок в существующем import run. Режим не разбирает страницы сериалов, не запрашивает player/playlist/video URL, не меняет `catalog_titles` и не публикует новые страницы. Подтверждённые counts, локальные parser/routes и пробелы parity документируются в [`docs/SOURCE_PARITY.md`](docs/SOURCE_PARITY.md).

Рабочий import выбирает обработчик через `SeasonvarPageHandlerRegistry`. `serial` сохраняет прежний полный catalog/media pipeline; `actor`, `genre`, `country` и `tag` имеют metadata-only parser/importer, но по умолчанию выключены, пока реальный inventory не подтвердит такие URL и оператор явно не разрешит тип в environment. Подтверждённый `rss` включён только как bounded freshness signal. `static`, `search`, `sitemap`, `unknown` и неподтверждённые taxonomy-типы хранятся для аудита, но автоматически не разбираются и не публикуются. `--page-type` ограничивает sync/queued запуск только явно включённым типом.

Граница provider payload, правила стабильной идентичности, владение редакционными полями, поведение частичных snapshots и порядок миграций описаны в `docs/importer.md`.

Команда защищена от параллельного запуска, постепенно проверяет старые видео-ссылки без статуса доступности, дополняет старые медиа качеством/форматом/стабильным ключом, нормализует статусы уже разобранных страниц и отключает некорректные склеенные ссылки источника.

Импортёр не скачивает и не сохраняет полные видеофайлы. Для поддерживаемых прямых файлов он дополнительно определяет точный размер: сначала безопасным `HEAD`, затем при необходимости потоковым `Range: bytes=0-0`; body полного файла не читается. В базе остаются внешние URL воспроизведения, качество, формат, перевод, субтитры, доступность и точный размер в байтах. Существующие записи можно допроверить ограниченными пакетами через `php artisan seasonvar:import --refresh-media-sizes`; HLS-манифест не считается размером полного видео.

### Обычный запуск

Полный последовательный импорт в текущем терминале:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
php artisan seasonvar:import
```

Обновление одной страницы, даже если сохраненный HTML hash не изменился:

```bash
php artisan seasonvar:import "https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html" --force
```

Режим `--forever` предназначен для одного долгоживущего процесса и не нужен при production-запуске через Redis workers:

```bash
php artisan seasonvar:import --forever
```

Для постоянного однопоточного профиля после перезагрузки используйте отдельный unit:

```bash
sudo cp deploy/systemd/seasonvar-import-forever.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-forever.service
systemctl status seasonvar-import-forever.service
```

Этот профиль циклически перечитывает XML sitemap и обрабатывает каталог одним PHP-процессом. Он взаимоисключающий с Redis queued-профилем ниже: import/title-refresh workers и cron с `seasonvar:import --queued` должны быть отключены, иначе процессов будет больше одного. Подробный порядок переключения описан в [`docs/deployment.md`](docs/deployment.md).

### Параллельный импорт и обновление открытых тайтлов

Для production используется `php artisan seasonvar:import --queued`. Команда находит и закрепляет подходящие страницы и ставит jobs в Redis. Десять workers обслуживают общий `seasonvar-import`, а отдельный IO-bound пул обслуживает browser-triggered `seasonvar-title-refresh`.

Первичная подготовка:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
redis-cli ping
php artisan migrate --force
sudo cp deploy/systemd/seasonvar-import-worker@.service /etc/systemd/system/
sudo cp deploy/systemd/seasonvar-title-refresh-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-worker@{1..10}.service
sudo systemctl enable --now seasonvar-title-refresh-worker@{1..32}.service
```

Десять общих workers обслуживают catalog-wide очередь `seasonvar-import`, а отдельный IO-bound пул обслуживает только срочные обновления открытых карточек. Число `32` — стартовая process capacity, не лимит страниц: importer ставит отдельную preparation job для каждой известной или найденной страницы сезона и затем применяет их одним finalizer.

`redis-cli ping` должен вернуть `PONG`. После запуска workers поставить подходящие страницы в очередь:

```bash
php artisan seasonvar:import --queued
```

Проверка workers, очереди, ошибок и журнала:

```bash
systemctl --no-pager --type=service 'seasonvar-import-worker@*'
systemctl --no-pager --type=service 'seasonvar-title-refresh-worker@*'
php artisan seasonvar:import --status
php artisan queue:failed
php artisan app:failed-job-audit --samples=1
journalctl -u 'seasonvar-import-worker@*' -f
journalctl -u 'seasonvar-title-refresh-worker@*' -f
```

Остановить workers без удаления очереди и claims:

```bash
sudo systemctl stop 'seasonvar-import-worker@*.service'
sudo systemctl stop 'seasonvar-title-refresh-worker@*.service'
```

Продолжить обработку сохраненной очереди:

```bash
sudo systemctl start seasonvar-import-worker@{1..10}.service
sudo systemctl start seasonvar-title-refresh-worker@{1..32}.service
```

После обновления PHP-кода workers необходимо перезапустить, потому что `queue:work` является долгоживущим процессом:

```bash
php artisan queue:restart
```

Systemd автоматически поднимет завершившиеся процессы. Полная диагностика и ручной foreground-вариант приведены в `docs/deployment.md`.

Версионируемый unit задаёт PHP hard limit `256M`, Laravel recycle threshold `192M` и `Restart=always`: это оставляет bounded запас полному rebuild рекомендаций и обновляет процесс до опасного накопления памяти следующими finalizer jobs.

### Cron: 10 запусков в сутки

Workers должны постоянно работать под systemd, а cron только добавляет в Redis страницы, которые уже разрешено обновить. Откройте crontab пользователя `www`:

```bash
sudo crontab -u www -e
```

Добавьте две строки: первая запускает dispatcher десять раз в сутки, вторая каждые пять минут проверяет backlog и создаёт throttled warning при превышении порога:

```cron
0 0,2,5,7,10,12,14,17,19,22 * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan seasonvar:import --queued >> storage/logs/seasonvar-cron.log 2>&1
*/5 * * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan queue:monitor redis:seasonvar-import --max=5000 >> storage/logs/seasonvar-queue-monitor.log 2>&1
```

Проверить установленное расписание:

```bash
sudo crontab -u www -l
```

Частый cron не означает повторное скачивание страницы десять раз. Атомарный lease не позволяет поставить уже закрепленную страницу второй раз, Redis lock не дает разным сезонным URL одновременно изменять один тайтл, а успешно импортированная страница становится подходящей для новой проверки только после настроенного freshness interval — по умолчанию через 24 часа. При изменении `content_hash` повторно разбираются название, описание, постер, сезоны, серии, связи и внешние видео. Неизмененная страница безопасно пропускается, кроме случаев, когда требуется дозаполнить или перепроверить отсутствующие медиа-данные.

После завершения импорта команда обновляет серверный снимок статистики, который использует live-страница `/stats`.

Ручной админ-запуск получает статус `queued`, coordinator переводит его в `running`, а finalizer — в `completed` или `partial`; доступны также `failed` и `cancelled`. Повтор создает новый run со ссылкой на предыдущий; idempotent importer повторно обрабатывает только актуальные страницы. Отмена кооперативна: новые page jobs не стартуют, а уже выполняющийся HTTP/DB-шаг может безопасно завершить текущую страницу.

## Локализация и публичные metadata

- Русские и английские строки каталога находятся в `lang/ru/catalog.php` и `lang/en/catalog.php`; русская локаль остаётся основной и fallback. Счётчики сериалов, сезонов, серий, оценок, запусков импорта и истории выводятся через `trans_choice()`.
- Локаль интерфейса не считается языком произведения, озвучки или субтитров. В текущей схеме нет нормализованного языка контента или пользовательской media-language preference, поэтому `TVSeries.inLanguage` не публикуется.
- Заголовок, описание и основные данные `/titles/{slug}` формируются сервером до Livewire hydration. Canonical карточки всегда указывает на текущий slug; прежние slug дают постоянный redirect на него.
- Поиск и сложные сочетания фильтров получают `noindex,follow` и canonical `/titles`; индексируемые одиночные taxonomy/year страницы сохраняют собственные стабильные маршруты.

## Интерфейс

Интерфейс каталога ведется на русском языке. Нельзя добавлять на страницы рекламные или заглушечные описания проекта.

Frontend собирается через Vite 8 и Tailwind CSS 4. Основная точка входа — `resources/js/app.js`; подробности по командам и asset rules описаны в `docs/frontend.md`.

Гостевые Livewire-страницы `/register`, `/login`, `/forgot-password` и `/reset-password/{token}` используют общие account-сервисы с mobile API. После входа маршруты под `auth` и `auth.session` дают `/email/verify`, `/confirm-password`, `/profile`, `/profile/security` и `/library/*`. Профиль позволяет изменить имя и email; смена email сбрасывает verification. Раздел безопасности меняет пароль, отзывает mobile devices и другие browser sessions либо удаляет аккаунт после подтверждения пароля. Password reset отзывает все mobile tokens, а обычная смена пароля сохраняет текущую browser session и отзывает API tokens.

Канонические приватные настройки доступны по `/settings/{section?}` и `/{locale}/settings/{section?}`. Разделы профиля, интерфейса, воспроизведения, приватности, уведомлений, новых коллекций, безопасности и данных переиспользуют существующие profile/player/library/collection/notification/session/export/delete boundaries. В `user_account_settings` сохраняются только явно выбранные account preferences; версия `seasonvar.account-preferences.v1` в local storage обслуживает немедленную device-настройку громкости и безопасный одноразовый anonymous merge после входа. Приоритет: явный URL locale, сохранённая account preference, поддерживаемый session locale для гостя, валидная device preference только для предназначенных ей полей, затем безопасный config default. Имя browser key не публикуется в гостевом HTML, но остаётся неизменным для обратной совместимости. Подробный контракт и ограничения находятся в Task 16 общего [плана модернизации](docs/plans/laravel-video-portal-modernization.md).

Страница `/titles` — full-page Livewire-компонент. Поиск (`q`), multi-select фильтры, allowlisted `sort`, вид, размер и `page` синхронизируются с URL и browser history; malformed значения безопасно нормализуются, а страница за последней границей восстанавливается автоматически. `q` ищет только по основному, оригинальному и альтернативным названиям; описания и metadata relations из него исключены. Публичное состояние содержит только нормализованные скаляры и небольшие массивы, а duplicate-free выдача строится общим `CatalogTitleQuery` на каждом запросе. Значения внутри одной группы объединяются через OR, разные группы — через AND. Поддерживаются годы, типы публикации, актеры, режиссеры, жанры, страны, возрастные рейтинги, production statuses, озвучка/перевод, качество видео и наличие субтитров; актеры и режиссеры ищутся на сервере с ограниченной выдачей.

Публичные справочники доступны по `/genres`, `/countries`, `/actors`, `/directors`, `/age-ratings`, `/translations`, `/statuses`, `/networks`, `/studios`, `/tags` и `/years`. Один `CatalogDirectoryRegistry` связывает эти URL с существующим `CatalogTaxonomyRegistry`, а `CatalogDirectoryQuery` считает только доступные гостю опубликованные тайтлы через grouped pivot queries и `count(distinct ...)`. Поиск, буква, сортировка, десятилетие и страница хранятся в URL Livewire 4; модели и коллекции в public state не сериализуются. Canonical detail URL остаётся `/titles/{type}/{taxonomy}` или `/titles/year/{year}`, а дружелюбные detail aliases дают постоянный redirect и не образуют SEO-дубликаты.

Десять relation-фасетов `/titles` выполняются одним bounded `UNION ALL`, причем limit применяется внутри каждой группы. Карточки и title summaries выбирают только отображаемые поля связей. Приватные progress/history данные не помещаются в shared cache. Для гостевой выдачи tiered Redis/Memcached layer хранит только компактные ID/DTO snapshots главной, default facets и обезличенную статистику; Eloquent-графы, signed media URL и arbitrary search не кэшируются. Точная матрица находится в `docs/caching.md`.

Страница `/stats` построена на Livewire 4, обновляет видимые блоки примерно раз в 15 секунд через `wire:poll.15s.visible` и читает подготовленный snapshot из cache, чтобы не пересобирать тяжелые агрегаты на каждый browser poll и не опрашивать сервер в скрытой вкладке.

Служебная `/admin/imports` хранит в Livewire только два boolean options и короткое notice. Список runs собирается сервисом на каждый render; `wire:poll.5s.visible` присутствует только пока есть `queued/running` run и исчезает после terminal state.

Служебная `/admin/catalog` хранит в Livewire только нормализованные формы, поиск и locked IDs/version fingerprints. Выборки и hierarchy binding находятся в `CatalogAdministrationQuery`, записи — в `CatalogAdministrationService`, авторизация — в gate/policy. Скрытие не удаляет пользовательские progress/history/watchlist/rating, а изменение publication/window немедленно влияет на общий `CatalogEntitlementService`.

Помимо общего Livewire transport throttle, чувствительные actions используют отдельные user/resource buckets из `config/security.php`: поиск, выдача progress-session, heartbeat progress, rating, watchlist, история и управление импортом не влияют на лимиты друг друга. Проверки video-source ограничиваются по хэшу host; локальный лимит возвращает `not_checked` и не ухудшает здоровье источника.

Страница `/titles/{catalogTitle:slug}` сохраняет server-side route binding и статическую metadata-оболочку, а выбор сезона, серии и варианта воспроизведения обслуживает вложенный Livewire 4 компонент. Первый ответ получает только summaries и точные counts сезонов, затем загружает серии и playable media одного активного сезона. Для авторизованного пользователя отдельно хранятся список просмотра, оценка и прогресс; эти же личные признаки и безопасное primary action заранее загружаются для карточек главной, каталога и рекомендаций без запросов из Blade. Отдельного «избранного» нет, потому что в текущем продукте это тот же список просмотра. Add/remove передают желаемое состояние и атомарно обновляют единственную строку текущего `User` и тайтла только при фактическом изменении, поэтому retry не меняет даже `updated_at`, а удаление отсутствующего состояния не создаёт пустую строку. Внутреннее среднее пользовательских оценок считается отдельно от импортных provider ratings, а допустимый диапазон задан в `config/catalog.php`. Primary action продолжает незавершенную серию, открывает следующую после завершенной или начинает первый доступный выпуск. Чтение личного состояния доступно вошедшему пользователю, а изменение списка, оценки и progress требует verified email и повторно применяет publication window/audience boundary. URL-параметры `season`, `episode`, `media`, `variant`, `quality` и `format` остаются shareable.

Авторизованные `/library/watchlist`, `/library/ratings`, `/library/continue-watching` и `/library/history` образуют одну Livewire-библиотеку с общей сводкой. Список и оценки фильтруются по названию, типу и году, имеют отдельную сортировку и независимые paginator keys. Continue Watching показывает не больше одной карточки на сериал: незавершённый выпуск открывается с сохранённой позиции, завершённый — со следующего доступного выпуска; полностью просмотренный сериал исчезает до публикации новой серии. История появляется только после реального события play. Verified пользователь может убрать одну owner-scoped запись или полностью очистить историю с подтверждением; unverified пользователь видит данные и подсказку о подтверждении, но не mutation controls. Старый `/watching` перенаправляет на `/library/continue-watching`. Отдельной модели профиля пока нет, поэтому активным профилем считается текущий `User`.

`CatalogEntitlementService` является общей server-side границей доступа: тот же набор publication status, legacy publication flag, availability window и `public/authenticated` audience применяется к каталогу, поиску, route binding, карточке, рекомендациям, progress/history, policy и повторной выдаче signed playback URL. Сервис возвращает типизированное решение с состояниями authentication/plan/region/profile/concurrency, но текущий продукт реально принимает решения только по существующим данным публикации и факту входа. Profile, child/age rules, PIN, roles, subscriptions, purchases, trials, territory и concurrent stream records в схеме отсутствуют и не симулируются; language/subtitle/autoplay preferences пока остаются browser/URL-состоянием проигрывателя, а не profile-данными.

## Системные, редакционные и личные теги

Существующие `tags` и `catalog_title_tag` остаются единственным глобальным классификатором. Каноническое расширение добавляет каждому глобальному тегу неизменяемый UUID, опциональный language-independent code, стабильные type/visibility/moderation/source values, Unicode comparison hash, переводы `ru/en`, aliases, bounded synonyms, историю slug и provider provenance. Исходные numeric IDs, имена, slug, source URL и title assignments не переименовываются и не копируются во второй домен.

Публичны только одобренные, неархивные и непустые `system`, `editorial` или нормализованные `imported` tags. `/titles/tag/{slug}` — каноническая страница; `/tags/{slug}` и `/tag/{slug}` остаются совместимыми 301-маршрутами. Case variants, бывшие slug, approved aliases и merged tags разрешаются в один canonical URL без alias pages. Страница использует общий каталог, фильтры, сортировку, карточки, visible-title scope, SEO presenter и streamed sitemap; ложные locale routes/`hreflang` не публикуются.

Личные теги хранятся отдельно в owner-scoped `user_tags`, всегда приватны и никогда не влияют на глобальную классификацию. Оригинальный Unicode label/script сохраняется без машинного перевода; create/edit/soft-delete/30-day restore, поиск и staged Apply/Cancel доступны в `/library/tags/manage` и на странице сериала. Private labels/counts/assignments отсутствуют в гостевом HTML, public search, recommendations, sitemap и shared cache; account export включает только собственные данные, а account deletion удаляет их каскадно. Полный контракт и сознательно неподдержанные public-user/season/episode/hierarchy/reporting boundaries находятся в [`docs/architecture.md`](docs/architecture.md), [`docs/DATA_RELATIONS.md`](docs/DATA_RELATIONS.md) и Task 11 [`docs/plans/laravel-video-portal-modernization.md`](docs/plans/laravel-video-portal-modernization.md).

## Коллекции и редакционные подборки

`/collections` и `/collections/{slug}` образуют один канонический serial-only домен для пользовательских и редакционных подборок. Стабильная identity — внутренний ID плюс внешний UUID; имя, slug, владелец, locale, обложка и видимость могут меняться. История slug сохраняет старые ссылки, а `/lists`, `/selections` и `/my/lists` служат только совместимыми redirect/alias-маршрутами, не второй архитектурой.

Новая пользовательская коллекция по умолчанию приватна. `private` доступна только владельцу или администратору и всегда `noindex/no-store`; `unlisted` открывается по прямой ссылке, но исключена из каталога, поиска и sitemap; `public` появляется публично только после разрешённого moderation state. Добавление, пакетный выбор на странице сериала, создание-и-добавление, удаление и ручная перестановка проходят единые policy/service boundaries и не меняют watchlist, рейтинг, progress или историю. Автоматические сортировки не переписывают сохранённые ручные позиции.

Пользовательские имя и описание хранятся как нормализованный Unicode plain text на исходном языке и не переводятся автоматически. Редакционные `ru/en` title/description/SEO используют отдельные database translations. Обложки сохраняются на приватном uploads disk и выдаются через авторизованный `private, no-store` endpoint. Account export включает коллекции и порядок, account deletion удаляет owned records/covers, а merge тайтлов переносит membership без дублей. Полный контракт находится в [`docs/architecture.md`](docs/architecture.md), [`docs/DATA_RELATIONS.md`](docs/DATA_RELATIONS.md), [`docs/authorization.md`](docs/authorization.md), [`docs/caching.md`](docs/caching.md) и [`docs/api.md`](docs/api.md).

## Комментарии и обсуждения

Один Livewire-домен обсуждений используется для сериала, выбранного сезона, выбранной серии и коллекции. Цель всегда подписана явно; тип берётся только из allowlist `title|season|episode|collection`, а identity комментария — неизменяемый числовой `comments.id`. Прямая ссылка `/comments/{id}` или локализованный collection-вариант разрешает актуальную цель и открывает нужную страницу/ветку; комментарии не образуют отдельные индексируемые страницы и не входят в sitemap или structured data.

Verified пользователь может публиковать plain-text Unicode комментарии и ответы, редактировать их 30 минут, мягко удалять и восстанавливать 7 дней. Один структурный уровень replies с `parent_id` на корневой комментарий исключает циклы и бесконечную вложенность, а `reply_to_id` сохраняет контекст ответа автору. Тело экранируется, HTML/Markdown не исполняются, ссылки остаются текстом. Длинный текст и спойлер не помещаются скрытым полным телом в начальный HTML: они загружаются только после доступного серверного действия.

Реакции представлены одной взаимно исключающей парой `up|down`; self-vote и повторные строки запрещены. Reply/reaction/moderation/report notifications используют body-free database payload и личные настройки. Reports, временные/постоянные comment-only ограничения, private mutes, directional blocks и `/admin/comments` переиспользуют общие policy/action/audit boundaries. Public count включает опубликованные неудалённые top-level comments и replies; owner-only moderated rows, reaction/block/mute/permissions никогда не входят в общий кеш.

Профиль `/profile/discussions` показывает только собственную доступную активность, private inbox/preferences и управление blocks/mutes. `/profile/export` включает собственные комментарии и реакции без чужих данных и moderator notes; удаление аккаунта анонимизирует авторство, сохраняя целостность веток. Mention links/notifications, arbitrary HTML, edit history, premium emoji/stickers, public profile activity и persistent browser drafts не добавлены, потому что в текущем продукте нет соответствующих архитектур. Полный контракт находится в [`docs/architecture.md`](docs/architecture.md), [`docs/DATA_RELATIONS.md`](docs/DATA_RELATIONS.md), [`docs/security.md`](docs/security.md), [`docs/notifications.md`](docs/notifications.md) и [`docs/caching.md`](docs/caching.md).

## Отзывы пользователей

Отзывы расширяют существующий `catalog_title_reviews` и остаются отдельными от комментариев. Импортные отзывы Seasonvar продолжают читаться через прежний `GET /api/v1/titles/{titleSlug}/reviews`; пользовательский отзыв — это одна структурированная рецензия на сериал с обязательными заголовком и текстом, необязательной оценкой 1–10 из уже существующего `catalog_title_user_states`, флагом спойлера и стабильным числовым ID. Сезонные и эпизодные отзывы не добавлены: для этих целей продукт уже использует обсуждения.

Verified пользователь может создать один текущий отзыв на сериал, изменить его, мягко удалить и восстановить в течение 30 дней. После окна восстановления прежний ID и moderation evidence сохраняются, а ownership slot безопасно освобождается для нового отзыва. Helpful/not-helpful голосование, reports, body-free notifications, private profile history, `/admin/reviews`, временные/постоянные review-only ограничения и title-merge reconciliation проходят единые policy/action/query boundaries. Спойлерный текст отсутствует в начальном HTML, профиле, уведомлениях, SEO и structured data до явного серверного раскрытия.

Интерфейс находится на карточке сериала; прямая ссылка `/reviews/{id}` только разрешает канонический title URL и `#review-{id}`. Reviews не получают отдельный sitemap, directory или JSON-LD и не индексируются поиском. Viewer vote, permissions, blocks/mutes, pending state, reports и moderator data не входят в общий кеш. Полный контракт и audit/acceptance находятся в [`docs/architecture.md`](docs/architecture.md), [`docs/DATA_RELATIONS.md`](docs/DATA_RELATIONS.md), [`docs/security.md`](docs/security.md), [`docs/caching.md`](docs/caching.md) и Task 13 [`docs/plans/laravel-video-portal-modernization.md`](docs/plans/laravel-video-portal-modernization.md).

## Медиа

Импорт не скачивает видео на сервер. В базе сохраняются внешние ссылки, качество, формат, перевод, результат проверки доступности и, для прямого файла, точный размер в байтах; страница сериала показывает выбранный формат и человекочитаемый размер без внешнего HTTP-запроса во время render.

Плеер не получает сохраненный provider URL из Blade или Livewire. `CatalogPlaybackSourceResolver` повторно проверяет тайтл, сезон, серию и media, выбирает доступный вариант и выдает только короткоживущий signed URL `/playback/{media}`. Endpoint привязан к текущему пользователю, повторяет проверки непосредственно перед выдачей и не принимает произвольный URL от браузера. Provider hosts задаются allowlist `PLAYBACK_ALLOWED_HOSTS`; проверка фиксирует публичный DNS-адрес на время запроса, не следует редиректам и читает только ограниченный Range/manifest fragment.

Загрузчики внешних playlist/poster URL отклоняют credentials, локальные/private/reserved A и AAAA, небезопасные порты и redirects; проверенный адрес закрепляется для самого HTTP-запроса. Google service-account assertion отправляется только на канонический `https://oauth2.googleapis.com/token`, даже если credential JSON содержит иной `token_uri`.

Зарегистрированный пользователь может скачать поддерживаемый прямой файл (`mp4`, `m4v`, `mov`, `webm`, `mkv`, `avi`) через отдельный authenticated route. Laravel повторно проверяет принадлежность и доступность title/season/episode/media, валидирует upstream, формирует безопасное имя `{serial}-sezon-{NN}-serija-{NN}.{ext}` и передаёт PSR-7 body bounded chunks без постоянной или временной полной копии. Поддерживается один корректный HTTP byte range для resume; ответы private/no-store. Гость не получает bytes. HLS остаётся только online playback: приложение не объединяет сегменты и не запускает transcoding.

Состояние источника хранится отдельно как `active`, `degraded`, `unavailable` или `disabled`. Временный timeout/5xx сначала переводит источник в `degraded`, но не исключает из playback; после настраиваемого порога он становится `unavailable`. Успешная повторная проверка сбрасывает failure counter и автоматически возвращает источник в `active`. Finalizer queued-импорта проверяет только записи с наступившим `next_check_at`, а `/admin/imports` показывает только безопасные агрегаты без provider URLs и внутренних ошибок.

Каждый отрендеренный источник получает отдельную browser-session Plyr/HLS. Она сообщает первый play, затем сохраняет bounded progress раз в 30 секунд только во время просмотра и фиксирует позицию при паузе, стабильном завершении перемотки, скрытии вкладки и уходе со страницы. Сервер принимает opaque expiring session token и возрастающий event sequence, повторно проверяет доступность серии/media и атомарно обновляет единственную строку `(user_id, episode_id)`; duplicate, out-of-order и более старые browser sessions не меняют историю. Длительность и процент берутся только из `licensed_media.duration_seconds`, а completion сохраняется необратимо для обычных progress events. Маркеры и ресурсы освобождаются при Livewire morph, `wire:navigate`, `pagehide` и bfcache restore.

Completion rule задаётся `PLAYBACK_PROGRESS_COMPLETION_PERCENT` (по умолчанию 95%) и `PLAYBACK_PROGRESS_COMPLETION_REMAINING_SECONDS` (15 секунд); событие `ended` также завершает выпуск при отсутствии trusted duration. Replay обновляет текущую позицию, не снимая исторический `completed_at`, а primary action продолжает незавершённый повторный просмотр. Отдельного profile model и пользовательского действия «не просмотрено» сейчас нет, поэтому состояние канонически принадлежит user и episode, а completion снимается только будущим явным product action.

При деплое migrations progress применяются строго по timestamp: сначала persistent playback fields и backfill `first_started_at`, затем индекс `episode_progress_user_history_idx`. Индекс не меняет данные и ускоряет user-scoped сортировку истории по `last_watched_at, id`.

Для стабильного обновления каждая запись медиа получает `source_media_key`. Если старые записи были созданы без него, `seasonvar:import` дозаполняет ключи небольшими пакетами.

Если на странице найден HLS master playlist, импорт пробует сохранить отдельные варианты качества для той же серии.

Трейлеры и анонсы сохраняются как медиа карточки или сезона даже тогда, когда у них нет номера серии.

## Laravel 13

Проект уже обновлен и проверен на Laravel 13. Подробности по зависимостям, MCP и проверкам описаны в `docs/upgrade.md`.

## Конфигурация и деплой

Обязательные переменные окружения, безопасные значения `.env.example` и команды `config:cache` описаны в `docs/deployment.md`. Код приложения читает настройки через `config()`, прямой `env()` допустим только в `config/*.php`.

Google Search Console и Google Analytics подготовлены как выключенные read-only интеграции через `.env.example` и `config/services.php`; OAuth/ADC-секреты должны задаваться вне Git.

## CI

GitHub Actions проверяет Composer, npm build, тесты, Pint, dependency audits и доступную статическую проверку PHP-синтаксиса. Команды и кеширование описаны в `docs/ci.md`.

## Git workflow

Единственная рабочая ветка проекта — существующая `main`. Установите версионируемые Git hooks командой `composer hooks:install`; полный workflow и поведение проверок описаны в [`docs/development.md`](docs/development.md#git-workflow).

<!-- project-docs:start -->
## Автоматически обновляемое состояние

- Обновлено командой `php artisan project:docs-refresh`: 16.07.2026.
- Основная карта сайта портала: `https://seasonvar.miniserver.fun/sitemap-index.xml`.
- Совместимый адрес `/sitemap.xml` отдает индекс карты сайта, чтобы поисковые системы получали все разделы карты.
- `public/robots.txt` объявляет стабильный индекс карты сайта без ручного перечисления страниц `sitemap-titles-*` и `sitemap-videos-*`.
- Git-хук `.githooks/post-commit` запускает обновление файлов документации и при изменениях делает отдельный коммит документации; автоматическая отправка в Git включается только через `SEASONVAR_DOCS_AUTO_PUSH=1`.
<!-- project-docs:end -->

## Рекомендации и discovery

Публичные подборки доступны по `/discover/{type}`: тренды, популярное, высокие рейтинги, новые/обновлённые/предстоящие материалы, редакционные и случайные находки. Авторизованный `/discover/personalized` использует только реальные данные портала — осмысленный прогресс, список, статусы, оценки, собственные коллекции и личные теги — и честно переключается на публичную подборку при cold start.

На странице сериала явные сиквелы/приквелы/спин-оффы показываются отдельно и раньше рассчитанного сходства. Blacklist/«не интересует» можно отменить в интерфейсе и в разделе скрытых рекомендаций библиотеки. Система не заявляет AI/ML и не показывает фиктивный процент совпадения. Полный контракт, privacy/cache/SEO и известные ограничения находятся в [`docs/superpowers/specs/2026-07-13-recommendation-v3-list-design.md`](docs/superpowers/specs/2026-07-13-recommendation-v3-list-design.md).
