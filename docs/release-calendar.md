# Календарь релизов

Обновлено: 26.07.2026.

Этот документ — единственный владелец доменного контракта календаря релизов. Он описывает публичное расписание, личный календарь, редакционные правки, уведомления и синхронизацию с импортом. Общие правила публикации каталога остаются в [`DATA_RELATIONS.md`](DATA_RELATIONS.md), кеша — в [`caching.md`](caching.md), уведомлений — в [`notifications.md`](notifications.md), импорта — в [`importer.md`](importer.md).

## Результат аудита

До задачи 17 в приложении не было отдельной таблицы, маршрута или сервиса календаря. `/discover/upcoming` был лишь запасным режимом рекомендаций. Даты находились в нескольких несводимых источниках:

- `episodes.released_at` предназначен для фактической даты выпуска серии, но в текущей рабочей базе не заполнен;
- `licensed_media.published_at` означает публикацию конкретного варианта видео на портале;
- `seasons.release_status_text` и `seasons.latest_episode_released_at` — необработанные сведения поставщика и не доказывают точную премьеру;
- `created_at`, `updated_at` и `indexed_at` — технические даты и не являются датами релиза;
- отдельные ожидаемые даты озвучки и субтитров, календарные подписки и история исправлений отсутствовали.

Поэтому существующие поля не переназначены и не перезаписаны. Новый домен добавлен рядом с ними, а автоматическая синхронизация создаёт событие только из подтверждаемого факта: `Episode::released_at`, реальной публикации `LicensedMedia` либо полностью нормализованного Seasonvar-наблюдения с гражданской датой, номером и существующей серией. Неопределённый текст поставщика не преобразуется в фиктивное первое января.

## Каноническая модель

### Таблицы и identity

Migration `2026_07_17_170000_create_release_calendar_domain.php` добавляет четыре обратимые таблицы, а отдельная additive migration приватных календарных feed'ов — пятую:

| Таблица | Назначение |
| --- | --- |
| `release_schedule_entries` | Канонические публичные и служебные события расписания. |
| `release_schedule_corrections` | Последовательная история содержательных изменений даты, точности и статуса. |
| `release_calendar_subscriptions` | Одна пользовательская подписка на один тайтл с отдельными категориями событий. |
| `release_calendar_notification_preferences` | Общие предпочтения категорий уведомлений пользователя. |
| `release_calendar_feeds` | Независимые приватные iCalendar-подписки пользователя с типизированной областью, отзываемым token hash и зашифрованным секретом. |

Каждое событие имеет случайный `public_id` UUID и уникальный серверный `logical_key`. Identity не зависит от названия, locale, даты, slug, номера группы календаря или отображаемой студии. `ReleaseScheduleIdentity` строит type-specific key из стабильной цели: премьера сериала — тайтл, сезона — сезон, серии/special — серия, публикация портала — серия, перевод/субтитры — серия плюс допустимые язык/студия, quality upgrade — конкретное media. Ручной редактор, observers и merge используют один builder; клиент не может передать key самостоятельно. Nullable внешние ключи на тайтл, сезон, серию и медиа позволяют сохранить историю при удалении цели. При слиянии `ReleaseCalendarTargetMergeService` пересчитывает key под canonical target, переносит подписки с точным сохранением/объединением категорий и сохраняет конфликтующее событие как отменённую скрытую историю вместо удаления.

### Типы событий

Поддерживаются стабильные коды:

- `serial_premiere`;
- `season_premiere`;
- `episode_release`;
- `translation_release`;
- `subtitle_release`;
- `portal_publication`;
- `quality_upgrade`;
- `special_release`.

Переведённые подписи не сохраняются в базе. Тип определяет видимую подпись, допустимую цель, правила уведомлений и публичное представление.

### Источник и ручная блокировка

Стабильные источники: `editorial`, `official`, `trusted_provider`, `provider`, `importer`, `inferred`, `user_report`, `portal`. Исполняемый порядок при автоматической синхронизации: `editorial` → `portal` → `official` → `trusted_provider` → `provider` → `importer` → `user_report` → `inferred` → `unknown`; observer не понижает более сильный источник. Дополнительно `is_locked=true` полностью защищает редакторскую запись. Для явной передачи значения автоматике сотрудник снимает lock и выбирает подходящий источник. Provider reference ограничен по длине и показывается только сотруднику; произвольные адреса не загружаются и не публикуются.

### Точность даты

`ReleaseDateValue` поддерживает:

- `exact_datetime` — UTC-момент плюс исходный IANA timezone;
- `exact_date` — гражданская дата без выдуманного времени;
- `month` — месяц и год;
- `quarter` — квартал и год;
- `year` — только год;
- `date_range` — ограниченный диапазон гражданских дат;
- `unknown` — дата не объявлена.

Частичные даты хранятся в отдельных `release_year`, `release_month`, `release_quarter`, `date_value` и `date_end`. Они не превращаются в `YYYY-01-01 00:00:00`. `ReleaseDatePresenter` форматирует точность на русском или английском, а точный момент конвертирует из UTC в IANA timezone пользователя с правилами перехода на летнее время. Гражданская exact date не сдвигается на соседний день из-за timezone.

### Статусы и переходы

Стабильные статусы: `scheduled`, `estimated`, `confirmed`, `released`, `delayed`, `postponed`, `cancelled`, `awaiting_translation`, `awaiting_subtitles`, `awaiting_portal_publication`, `unknown`. Enum `ReleaseScheduleStatus` владеет разрешёнными переходами; произвольный статус или обход через Livewire отклоняется. `is_estimated` не может сочетаться с `confirmed`/`released`, а статус `estimated` требует этого флага.

Просроченное подтверждённое событие без фактической публикации представляется как задержанное и не получает отрицательный countdown. Отмена останавливает countdown и дальнейшие release-уведомления. Повторный save одинакового payload идемпотентен. Содержательное изменение повышает `revision`, пишет correction и создаёт новое уведомительное событие только по правилам категории.

## Различие дат

- Оригинальный релиз — `episode_release`, `season_premiere` или `serial_premiere`; он не обещает наличие видео на портале.
- Публикация портала — отдельный `portal_publication`, создаваемый только для реально опубликованного episode-bound `LicensedMedia`.
- Озвучка/voice-over — отдельный `translation_release` с языком и, если известно, студией.
- Субтитры — отдельный `subtitle_release`; язык интерфейса не подставляется как язык субтитров.
- Импорт и техническое обновление модели не считаются релизом.

Ожидаемая дата перевода может быть заведена редактором как `estimated`, но приложение не вычисляет прогноз из оригинальной даты и не выдаёт оценку за подтверждение. Истёкшая оценка становится состоянием ожидания без повторяющихся ложных уведомлений.

## Запросы и видимость

`ReleaseCalendarQuery` остаётся канонической page query boundary. Она применяет существующие publication/availability scopes тайтла, сезона и серии, eager loading, bounded окна, allowlisted filters/sorts и детерминированный `id` tie-break. `ReleaseCalendarFeedQuery` является отдельной bounded projection boundary только для iCalendar и переиспользует те же `ReleaseScheduleVisibility` и personal-eligibility constraints; он не создаёт второй источник расписания. Скрытые, неопубликованные, удалённые или недоступные записи не попадают в public/personal/feed output. Текущая модель портала не имеет самостоятельных таблиц premium entitlement и region grant; календарь честно переиспользует каноническую audience/availability boundary и автоматически наследует будущую проверку из неё, не симулируя отсутствующие лицензии.

Поддерживаются тип, статус и стабильный ID тайтла как фильтры, а также хронологическая сортировка в обоих направлениях и сортировка по названию. Произвольные SQL columns, ranges и timezone не принимаются. Окна ограничены `release-calendar.maximum_window_days`; date range выбирается по пересечению с окном, а не только по первому дню.

Пустое состояние сортировки разрешается относительно route view: канонический
`/calendar` (`recent`) по умолчанию использует `latest`, поэтому сначала
показывает наиболее новые фактические даты; upcoming/day/week/month/personal
по умолчанию сохраняют `earliest`. Явные `?sort=earliest|latest|title`
продолжают иметь приоритет. Значение, совпадающее с route default, не
загрязняет canonical URL, а изменение select проходит через один Livewire
action и сбрасывает только calendar paginator. Хронологическое направление
применяется в `ReleaseCalendarQuery` до `paginate()`, после чего Blade только
группирует уже упорядоченную page collection с сохранением её порядка.

## Публичные маршруты

Все HTML-страницы — full-page Livewire:

- `/calendar` — каноническая стартовая страница недавних фактических релизов;
- `/calendar/upcoming` — отдельная страница ближайших подтверждённых событий;
- `/calendar/day/{YYYY-MM-DD}` — день;
- `/calendar/week/{YYYY-Www}` — ISO-неделя;
- `/calendar/month/{YYYY-MM}` — месяц;
- `/calendar/recent` — постоянное совместимое перенаправление на `/calendar`;
- `/calendar/mine` — закрытый личный календарь;
- `/calendar/feed/{private-token}.ics` — stateless read-only iCalendar feed по случайному приватному token;
- `/{locale}/calendar...` — те же публичные интерфейсы RU/EN;
- `/admin/calendar` — редакционная панель.

Legacy `/schedule` и `/release-calendar` перенаправляются на canonical `/calendar`. `/discover/upcoming` сохранён как отдельная discovery-страница и не объявлен календарём. `ReleaseCalendarPeriod` проверяет календарную дату, ISO week/year boundary и месяц; локализованная строка не используется как route identity.

## День, неделя, месяц и agenda

День и неделя используют точные временные границы в timezone пользователя. Неделя начинается с настроенного дня, но ISO route identity остаётся однозначной. Month view читает один ограниченный набор агрегатов, показывает доступную таблицу на широком экране и agenda на телефоне; полные графы серий для каждого дня не загружаются. Upcoming группирует today/tomorrow, конкретные локальные даты и unknown, а recent отделяет фактическую публикацию от оригинального выхода. Unknown и partial dates остаются в agenda, но не получают ложную ячейку month grid.

Состояние view, period, type, status, sort и title синхронизируется с безопасной частью URL. Locale меняет подписи, но не identity события, media language или пользовательский timezone.

## Личный календарь и подписки

Личный календарь требует текущего пользователя, возвращает `private, no-store`/`noindex` и не использует общий кеш. Eligibility включает явную calendar subscription и существующие релевантные состояния библиотеки; `not_interested` и `blacklisted` исключаются. История одного открытия карточки не включает уведомления автоматически.

Подписка одна на пару `(user_id, catalog_title_id)` и содержит независимые флаги премьеры сериала, сезона, серии, перевода, субтитров и публикации портала. `SetReleaseCalendarSubscription` авторизует владельца, под блокировкой транзакции идемпотентно создаёт либо удаляет unique-строку, применяет ограничитель частоты и персональную инвалидацию. Bookmark, прогресс и подписка остаются независимыми.

## Приватные iCalendar-подписки

Владелец управляет независимыми feed'ами на `/calendar/mine`. Один feed имеет собственный `public_id`, scope и secret token, поэтому его можно регенерировать или удалить без отзыва остальных подписок. Поддерживаются стабильные scopes:

- `all` — весь личный календарь по тем же subscription/library eligibility и negative exclusions;
- `collection` — события тайтлов одной собственной активной подборки;
- `new_episodes` — только `episode_release` личного календаря;
- `season_premieres` — только `season_premiere` личного календаря;
- `title` — все доступные события одного тайтла;
- `translation` — конкретный `translation_release` по обязательному названию варианта перевода и необязательному языку; можно дополнительно ограничить одним тайтлом;
- `subtitles` — `subtitle_release` по обязательному коду языка; можно дополнительно ограничить одним тайтлом.

Scope, target и track-поля валидируются совместно server-side. Невозможные сочетания запрещены, пустые строки нормализуются, target повторно разрешается через owner/visibility boundary. Коллекционный feed можно создать только для собственной неудалённой подборки. Title и optional track target должны быть доступны пользователю на момент создания и повторно проходят canonical visibility при каждом чтении.

Token генерируется CSPRNG, имеет не менее 256 бит энтропии и никогда не хранится как единственное открытое значение. `token_hash` SHA-256 обслуживает unique indexed lookup, а зашифрованная Laravel cast-копия нужна только для повторного показа URL владельцу. Оба поля скрыты от сериализации и account export. Регенерация под row lock атомарно заменяет hash и encrypted secret, повышает version и немедленно превращает старый URL в `404`; удаление feed'а окончательно отзывает URL. В интерфейсе и логах token не маскируется как идентификатор пользователя, не записывается в browser storage и не отправляется сторонним analytics.

Feed route не требует session cookie и является capability URL: знание полного token даёт read-only доступ ровно к одному feed. Invalid, revoked, deleted-target и blocked-account состояния fail closed одинаковым `404`. Ответ всегда имеет `text/calendar; charset=utf-8`, inline filename, `private, no-store`, `noindex,nofollow,noarchive`, `nosniff` и `no-referrer`; общий page/document cache и sitemap исключены. Named limiter использует только hashes token/IP, не сохраняет secret в cache key и возвращает приватный `429`.

iCalendar renderer выдаёт RFC 5545 `VCALENDAR`/`VEVENT`, CRLF и UTF-8 line folding, escaped text, stable UID из schedule UUID, revision `SEQUENCE`, безопасный canonical title URL и корректные UTC/exact-date/date-range boundaries. Partial month/quarter/year и unknown events пропускаются, потому что форматирование их первым днём периода было бы ложной точностью. Окно прошлого/будущего и максимум событий hard-bounded config; query выбирает только нужные колонки, eager-loads только title и не выполняет запросы из Blade.

Apple Calendar получает прямую `webcal://` ссылку. Официальный Google Calendar flow для стороннего ICS использует «Добавить календарь → По URL» и не предоставляет документированный стабильный one-click prefill: кнопка Google копирует HTTPS feed URL и открывает страницу добавления по URL с понятной инструкцией. Кнопка копирования использует Clipboard API с локальным fallback, не сохраняет token в `localStorage`/`sessionStorage`; regeneration и deletion имеют явное подтверждение и loading/disabled state.

## Уведомления

`ReleaseCalendarNotificationService` использует существующий Laravel database channel и предпочтения из настроек аккаунта. Категории: announcement, date change, serial premiere, season premiere, episode release, translation, subtitles, portal publication, delay и cancellation. Получатель должен одновременно иметь разрешённую категорию в подписке и общих настройках.

UUID уведомления детерминирован по получателю, событию, revision, delivery kind и стабильному `entry_type`, поэтому повтор observer/import не создаёт дубликат. Delivery kinds: announcement, date change, released, postponed и cancelled; содержательная категория премьеры/сезона/серии/перевода/субтитров/портальной публикации остаётся типом события и выбирает отдельный preference flag. Payload содержит только public UUID, type/status/kind/revision и безопасные старую/новую даты; provider reference, URL медиа, private note, email, точный progress и список получателей отсутствуют. Inbox заново разрешает видимость цели. Изменение времени меньше настроенного порога не создаёт date-change spam.

Точный background reminder за 24 часа или час не заявлен: новая обязательная очередь или cron не добавлены. Страница показывает актуальное состояние при обычном чтении, а уведомления создаются после нормальной mutation/import boundary. Это честное graceful degradation при отсутствии отдельного надёжного планировщика напоминаний.

## Countdown

Серверный presenter отдаёт только доверенный абсолютный ISO timestamp и готовую доступную сводку. Vite-модуль `resources/js/release-calendar.js` обновляет подходящую единицу не чаще раза в минуту, останавливается на нуле, не опрашивает сервер и удаляет timers при Livewire navigation. В Blade нет вычисления даты и inline JavaScript. Reduced motion не нарушает чтение; screen reader получает стабильную текстовую сводку.

## Импорт и исправления

`EpisodeReleaseScheduleObserver` синхронизирует только непустой `episodes.released_at`. `LicensedMediaReleaseScheduleObserver` синхронизирует только реальную publication episode-bound media и разделяет portal, translation и subtitle events. Оба observer работают after commit, проверяют schema для rolling deploy, сохраняют stable key, не трогают locked override и запускают точечную инвалидацию/уведомление.

Не выполняется прямой backfill неоднозначного `release_status_text`, технических timestamps или произвольных raw provider strings. Явно разобранная строка Seasonvar с валидной гражданской датой, точным номером уже названной серии и существующей canonical `Episode` считается отдельным provider observation: при указанной студии создаётся `translation_release`, при явном указании субтитров — `subtitle_release`, а без варианта перевода — provider `episode_release`. Такое наблюдение использует `exact_date`, источник `provider` и stable episode/type/translation identity; оно не заполняет `Episode::released_at`, не объявляется оригинальной премьерой при наличии перевода и никогда не вычисляет дату следующей серии.

Синхронизация выполняется внутри существующего `seasonvar:import` после сохранения текущего сезона и серий. Повтор одинаковой строки является no-op, изменение даты того же события повышает revision и пишет correction, следующий номер серии создаёт новое событие, а manual lock или более сильный источник сохраняются. Исторические события остаются facts; исчезновение строки из текущей страницы не удаляет их автоматически. Cache invalidation и допустимое уведомление запускаются after commit, причём backfill старше настроенного recent window не создаёт запоздалое уведомление. В full/global queued title-group и synchronous sitemap apply hidden Laravel `Context` coalesces только record-level public cache version bumps до обязательной terminal catalog invalidation. Entry/correction и notification logic продолжают выполняться; targeted URL, visitor refresh и admin/editor changes находятся вне scope и инвалидируют calendar/home/sitemap немедленно. Scope восстанавливается после exception и не хранит deferred data, новый key или второй pipeline. Inferred recurrence не реализована: портал не создаёт будущие эпизоды по недельному шаблону и не рассылает их как подтверждённые.

Production backfill 20.07.2026 прошёл через единственную публичную команду: отдельный targeted refresh обновил контрольный тайтл вне хвоста XML, а queued run `#954` выбрал ровно последние 1000 distinct serial URL и штатно расширился sibling seasons до `1592/1592`. Итог: `completed`, page failures `0`, active/problem groups `0/0`, live claims `0`. На «Вестис» и «Интервью с вампиром» создано по одной logical translation row для точной серии `RuDub`; portal media повысила их до `exact_datetime`, не создав дубль, а `episodes.released_at` остался пустым. Отфильтрованный публичный `/calendar` показывает дату, сезон, серию и перевод обоих событий.

Correction хранит предыдущие и новые точный момент, гражданскую дату/границы диапазона, год, месяц, квартал, IANA timezone, точность, статус, источник, actor, публичную причину и отдельную private note. Только администратор видит private note. Ретрай с одинаковым состоянием не создаёт новую correction.

## Администрирование

`/admin/calendar` защищён `auth`, `auth.session`, `account.private` и gate `manage-release-calendar`. Сотрудник ищет каноническую цель, выбирает тип, точность, IANA timezone, источник и статус, может заблокировать ручное значение, управлять public visibility/notification eligibility и просматривать историю. Target ancestry повторно проверяется на сервере. Создание, правка, postponement/cancellation и publication используют один `ReleaseScheduleService`; destructive GET и прямой mass assignment отсутствуют.

Bulk editing намеренно не добавлен: пользовательская задача не требует административной массовой mutation, а безопасной bulk UX в продукте нет. Приватный iCalendar export реализован отдельной read-only capability boundary и не расширяет административные права.

## Кеш и производительность

`CacheDomain::ReleaseCalendar` хранит версии только публичных scalar/response данных. Public page profile допускает allowlisted `type`, `status`, `sort`, `title` и page; locale и canonical public timezone входят в boundary. Публичные index, upcoming, day, week и month route names для обычных и локализованных адресов входят в общий кеш только через явный route-safety allowlist; personal/admin routes в нём отсутствуют. Произвольные timezone пользователя не создают неограниченный shared key: точное grouping выполняется request-side, а личная страница bypass-ит общий кеш.

Mutation повышает release-calendar, home, sitemap и affected title generations после commit; store-wide flush и wildcard scan отсутствуют. Исключение — только record-level изменения внутри full/global Seasonvar apply: authoritative rows сохраняются, а public version bump выполняет один terminal `CatalogCacheInvalidator::catalogChanged()` после завершения run. Targeted/visitor/admin mutations сохраняют immediate semantics. Личный state, subscription, preference, entitlement, feed secret/scope и notification read state не входят в global value. iCalendar response не кэшируется приложением, reverse proxy или browser; новый cache domain и invalidation fan-out не создаются.

Основные индексы соответствуют запросам диапазона/статуса/типа/цели, correction timeline, subscription owner и notification preference lookup. Logical key и user/title subscription защищены unique constraints. Feed migration добавляет только индексы, соответствующие token lookup, owner listing и точным public time/date feed windows; duplicate indexes не создаются. Page query использует eager loading, ограниченное окно и paginator; month summary читает только bounded scalar projection и группирует её в пользовательском timezone. Feed query имеет hard limit и deterministic date/ID order. Полный каталог или все будущие годы в PHP не сравниваются.

## SEO

Непустые канонические `/calendar` и `/calendar/upcoming` без личного состояния, произвольных фильтров и пагинации могут быть `index,follow` и попасть в существующий sitemap. Пустые upcoming/recent, daily, weekly, monthly, filtered и personal views используют self canonical с `noindex`; personal page также `noarchive` и не имеет `hreflang`. Прежний `/calendar/recent` перенаправляется на `/calendar` и не создаёт вторую canonical/cache identity. RU/EN alternates публикуются только для реальных публичных страниц.

Structured data — ограниченный public `ItemList`; estimated/unknown date не представляется подтверждённым `Event`, private state и source URL отсутствуют. Calendar sitemap URL добавляется только при schema-ready и наличии публично видимого события. Второй sitemap generator не создан.

## Security, privacy и отказоустойчивость

- Все mutation проходят gate/action/service, CSRF Livewire и rate limiter; user/target/status/source/timezone с клиента не считаются доверенными.
- UI выводит пользовательские и импортированные строки через escaped Blade; raw provider URL, media source, credentials и private correction note отсутствуют в public DTO.
- Schema guards позволяют развернуть код до migration без fatal error: пользователь получает локализованное unavailable состояние.
- Cache/notification failures не откатывают уже подтверждённую доменную транзакцию; ошибки report-ятся без provider payload.
- Account export содержит собственные subscription codes/preferences, но не других подписчиков или внутренние correction notes. Удаление пользователя каскадно удаляет его подписки и preferences, не удаляя общественно значимое расписание.
- Account export содержит scope/targets/locale/version собственных feed'ов, но никогда не содержит `token_hash`, encrypted secret или готовый private URL. Удаление пользователя каскадно отзывает все его feed'ы. Soft-deleted target делает feed недоступным, force delete каскадно удаляет его; title merge переносит feed target на canonical тайтл.
- Calendar correctness не зависит от новой queue: текущий статус и delayed presentation вычисляются из сохранённого состояния и текущего времени.

## Развёртывание и rollback

Обе migrations расширяющие, обратимые и SQLite-compatible. Перед production migration нужна обычная резервная копия; старые поля и маршруты не меняются. Feed migration создаёт только `release_calendar_feeds` и два feed-window индекса, не backfill-ит пользователей и не создаёт secrets на GET. Rolling deploy защищён отдельным `feedsReady()` schema guard: старый calendar продолжает работать, а management/feed route fail closed до DDL. Rollback удаляет feed table/indexes и тем самым отзывает новые URL, не затрагивая расписание, личные title subscriptions или public routes. Исторические записи не заполняются из неоднозначных данных автоматически; редактор может добавить проверенные события после развёртывания.

## Ручная проверка

- Проверить `/calendar`, `/calendar/upcoming`, day/week/month, redirect с `/calendar/recent`, RU/EN и legacy redirects с валидными и невалидными period.
- Проверить exact datetime на границе дня в двух IANA timezone, DST, exact date без сдвига, month/year/range/unknown.
- Проверить отсутствие hidden/deleted/unpublished целей, дублей от media/translation и отрицательного countdown.
- Проверить narrow phone agenda, desktop month grid, zoom, keyboard focus, loading/empty/error live regions и cleanup countdown после Livewire navigation.
- Проверить личный calendar/noindex/no-store, подписку/отписку, независимые категории и suppression blacklist/not-interested.
- Проверить все семь feed scopes, translation/subtitle + optional title combination, empty/invalid combinations, owner collection/title authorization, exact/date/range ICS, partial-date omission и hard event/window limits.
- Проверить stateless invalid/old/deleted/blocked token `404`, regeneration/delete, hash/encrypted storage, account export/deletion, title merge и no-store/noindex/nosniff/no-referrer headers.
- Проверить Google copy + add-by-URL flow, Apple `webcal://`, direct copy fallback, desktop/mobile layout, keyboard/focus, loading/error/status и отсутствие token в browser storage/logs.
- Проверить admin gate, invalid target ancestry, locked override, correction history, postpone/cancel/release и private note.
- Проверить repeated observer/import, deterministic notification UUID, preference suppression и отсутствие source URL/private text.
- Проверить public cache locale/time boundary, targeted version bump, sitemap eligibility, canonical/`hreflang` и public-only ItemList.
- Выполнить additive migration на чистой временной SQLite, `Pint`, route/view/config cache, frontend build и browser smoke-check; production данные не очищать.

## Известные ограничения

- Рабочие данные пока не содержат подтверждённых `episodes.released_at`; календарь не выдумывает историю и заполнится редактором или будущими проверенными импортными датами.
- Автоматический прогноз перевода, recurrence generation, гарантированный pre-release scheduler и bulk editor отсутствуют.
- Отдельные premium/region entitlement tables и provider market timezone отсутствуют; применяется существующая publication/audience/availability boundary.
- Доступные интерфейсные locale — RU и EN; пользовательский текст и provider labels автоматически не переводятся.

## Интеграция личной библиотеки Task 09

`CatalogPersonalUpdateQuery` не создаёт второй release feed: он применяет существующий `ReleaseScheduleVisibility` к опубликованным/released событиям и сравнивает их с owner/title acknowledgment. New episode/season/translation/subtitle/quality badges используют stable event type, а technical `updated_at`, duplicate source и hidden/unpublished/inaccessible event не считаются личным обновлением.

Первый baseline берётся из semantic bookmark/status/progress activity; acknowledge monotonic и не меняет status или progress. Historical `completed` сохраняется после нового сезона, а новый event остаётся отдельным indicator. `not_interested`/`blacklisted`, notification preferences, Premium/region visibility и existing calendar subscriptions продолжают применяться каноническими boundaries; UI-бейдж не отправляет обязательное уведомление и не требует нового scheduler.
