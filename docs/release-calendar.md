# Календарь релизов

Обновлено: 18.07.2026.

Этот документ — единственный владелец доменного контракта календаря релизов. Он описывает публичное расписание, личный календарь, редакционные правки, уведомления и синхронизацию с импортом. Общие правила публикации каталога остаются в [`DATA_RELATIONS.md`](DATA_RELATIONS.md), кеша — в [`caching.md`](caching.md), уведомлений — в [`notifications.md`](notifications.md), импорта — в [`importer.md`](importer.md).

## Результат аудита

До задачи 17 в приложении не было отдельной таблицы, маршрута или сервиса календаря. `/discover/upcoming` был лишь запасным режимом рекомендаций. Даты находились в нескольких несводимых источниках:

- `episodes.released_at` предназначен для фактической даты выпуска серии, но в текущей рабочей базе не заполнен;
- `licensed_media.published_at` означает публикацию конкретного варианта видео на портале;
- `seasons.release_status_text` и `seasons.latest_episode_released_at` — необработанные сведения поставщика и не доказывают точную премьеру;
- `created_at`, `updated_at` и `indexed_at` — технические даты и не являются датами релиза;
- отдельные ожидаемые даты озвучки и субтитров, календарные подписки и история исправлений отсутствовали.

Поэтому существующие поля не переназначены и не перезаписаны. Новый домен добавлен рядом с ними, а автоматическая синхронизация создаёт событие только из подтверждаемого факта: `Episode::released_at` либо реальной публикации `LicensedMedia`. Неопределённый текст поставщика не преобразуется в фиктивное первое января.

## Каноническая модель

### Таблицы и identity

Migration `2026_07_17_170000_create_release_calendar_domain.php` добавляет четыре обратимые таблицы:

| Таблица | Назначение |
| --- | --- |
| `release_schedule_entries` | Канонические публичные и служебные события расписания. |
| `release_schedule_corrections` | Последовательная история содержательных изменений даты, точности и статуса. |
| `release_calendar_subscriptions` | Одна пользовательская подписка на один тайтл с отдельными категориями событий. |
| `release_calendar_notification_preferences` | Общие предпочтения категорий уведомлений пользователя. |

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

`ReleaseCalendarQuery` — единственная query boundary. Она применяет существующие publication/availability scopes тайтла, сезона и серии, eager loading, bounded окна, allowlisted filters/sorts и детерминированный `id` tie-break. Скрытые, неопубликованные, удалённые или недоступные записи не попадают в public/personal output. Текущая модель портала не имеет самостоятельных таблиц premium entitlement и region grant; календарь честно переиспользует каноническую audience/availability boundary и автоматически наследует будущую проверку из неё, не симулируя отсутствующие лицензии.

Поддерживаются тип, статус и стабильный ID тайтла как фильтры, а также хронологическая сортировка в обоих направлениях и сортировка по названию. Произвольные SQL columns, ranges и timezone не принимаются. Окна ограничены `release-calendar.maximum_window_days`; date range выбирается по пересечению с окном, а не только по первому дню.

## Публичные маршруты

Все HTML-страницы — full-page Livewire:

- `/calendar` — ближайшие события;
- `/calendar/day/{YYYY-MM-DD}` — день;
- `/calendar/week/{YYYY-Www}` — ISO-неделя;
- `/calendar/month/{YYYY-MM}` — месяц;
- `/calendar/recent` — недавние подтверждённые публикации;
- `/calendar/mine` — закрытый личный календарь;
- `/{locale}/calendar...` — те же публичные интерфейсы RU/EN;
- `/admin/calendar` — редакционная панель.

Legacy `/schedule` и `/release-calendar` перенаправляются на canonical `/calendar`. `/discover/upcoming` сохранён как отдельная discovery-страница и не объявлен календарём. `ReleaseCalendarPeriod` проверяет календарную дату, ISO week/year boundary и месяц; локализованная строка не используется как route identity.

## День, неделя, месяц и agenda

День и неделя используют точные временные границы в timezone пользователя. Неделя начинается с настроенного дня, но ISO route identity остаётся однозначной. Month view читает один ограниченный набор агрегатов, показывает доступную таблицу на широком экране и agenda на телефоне; полные графы серий для каждого дня не загружаются. Upcoming группирует today/tomorrow, конкретные локальные даты и unknown, а recent отделяет фактическую публикацию от оригинального выхода. Unknown и partial dates остаются в agenda, но не получают ложную ячейку month grid.

Состояние view, period, type, status, sort и title синхронизируется с безопасной частью URL. Locale меняет подписи, но не identity события, media language или пользовательский timezone.

## Личный календарь и подписки

Личный календарь требует текущего пользователя, возвращает `private, no-store`/`noindex` и не использует общий кеш. Eligibility включает явную calendar subscription и существующие релевантные состояния библиотеки; `not_interested` и `blacklisted` исключаются. История одного открытия карточки не включает уведомления автоматически.

Подписка одна на пару `(user_id, catalog_title_id)` и содержит независимые флаги премьеры сериала, сезона, серии, перевода, субтитров и публикации портала. `SetReleaseCalendarSubscription` авторизует владельца, под блокировкой транзакции идемпотентно создаёт либо удаляет unique-строку, применяет ограничитель частоты и персональную инвалидацию. Bookmark, прогресс и подписка остаются независимыми.

## Уведомления

`ReleaseCalendarNotificationService` использует существующий Laravel database channel и предпочтения из настроек аккаунта. Категории: announcement, date change, serial premiere, season premiere, episode release, translation, subtitles, portal publication, delay и cancellation. Получатель должен одновременно иметь разрешённую категорию в подписке и общих настройках.

UUID уведомления детерминирован по получателю, событию, revision, delivery kind и стабильному `entry_type`, поэтому повтор observer/import не создаёт дубликат. Delivery kinds: announcement, date change, released, postponed и cancelled; содержательная категория премьеры/сезона/серии/перевода/субтитров/портальной публикации остаётся типом события и выбирает отдельный preference flag. Payload содержит только public UUID, type/status/kind/revision и безопасные старую/новую даты; provider reference, URL медиа, private note, email, точный progress и список получателей отсутствуют. Inbox заново разрешает видимость цели. Изменение времени меньше настроенного порога не создаёт date-change spam.

Точный background reminder за 24 часа или час не заявлен: новая обязательная очередь или cron не добавлены. Страница показывает актуальное состояние при обычном чтении, а уведомления создаются после нормальной mutation/import boundary. Это честное graceful degradation при отсутствии отдельного надёжного планировщика напоминаний.

## Countdown

Серверный presenter отдаёт только доверенный абсолютный ISO timestamp и готовую доступную сводку. Vite-модуль `resources/js/release-calendar.js` обновляет подходящую единицу не чаще раза в минуту, останавливается на нуле, не опрашивает сервер и удаляет timers при Livewire navigation. В Blade нет вычисления даты и inline JavaScript. Reduced motion не нарушает чтение; screen reader получает стабильную текстовую сводку.

## Импорт и исправления

`EpisodeReleaseScheduleObserver` синхронизирует только непустой `episodes.released_at`. `LicensedMediaReleaseScheduleObserver` синхронизирует только реальную publication episode-bound media и разделяет portal, translation и subtitle events. Оба observer работают after commit, проверяют schema для rolling deploy, сохраняют stable key, не трогают locked override и запускают точечную инвалидацию/уведомление.

Не выполняется автоматический backfill неоднозначного `release_status_text`, `latest_episode_released_at`, технических timestamps или raw provider strings. Для будущего parser mapping нужны явные precision/source/timezone. Inferred recurrence не реализована: портал не создаёт будущие эпизоды по недельному шаблону и не рассылает их как подтверждённые.

Correction хранит предыдущие и новые точный момент, гражданскую дату/границы диапазона, год, месяц, квартал, IANA timezone, точность, статус, источник, actor, публичную причину и отдельную private note. Только администратор видит private note. Ретрай с одинаковым состоянием не создаёт новую correction.

## Администрирование

`/admin/calendar` защищён `auth`, `auth.session`, `account.private` и gate `manage-release-calendar`. Сотрудник ищет каноническую цель, выбирает тип, точность, IANA timezone, источник и статус, может заблокировать ручное значение, управлять public visibility/notification eligibility и просматривать историю. Target ancestry повторно проверяется на сервере. Создание, правка, postponement/cancellation и publication используют один `ReleaseScheduleService`; destructive GET и прямой mass assignment отсутствуют.

Bulk editing и iCalendar export намеренно не добавлены: существующий продукт не имел подтверждённой потребности, feed-token/revocation архитектуры или безопасной административной bulk UX. Это предотвращает мёртвые controls и не создаёт второй calendar feed.

## Кеш и производительность

`CacheDomain::ReleaseCalendar` хранит версии только публичных scalar/response данных. Public page profile допускает allowlisted `type`, `status`, `sort`, `title` и page; locale и canonical public timezone входят в boundary. Произвольные timezone пользователя не создают неограниченный shared key: точное grouping выполняется request-side, а личная страница bypass-ит общий кеш.

Mutation повышает release-calendar, home, sitemap и affected title generations после commit; store-wide flush и wildcard scan отсутствуют. Личный state, subscription, preference, entitlement и notification read state не входят в global value.

Основные индексы соответствуют запросам диапазона/статуса/типа/цели, correction timeline, subscription owner и notification preference lookup. Logical key и user/title subscription защищены unique constraints. Query использует eager loading, ограниченное окно и paginator; month summary читает только bounded scalar projection и группирует её в пользовательском timezone. Полный каталог или все будущие годы в PHP не сравниваются.

## SEO

Только непустая основная upcoming-страница без личного состояния и произвольных фильтров может быть `index,follow` и попасть в существующий sitemap. Пустая upcoming, daily, weekly, monthly, recent, filtered и personal views используют self canonical с `noindex`; personal page также `noarchive` и не имеет `hreflang`. RU/EN alternates публикуются только для реальных публичных страниц.

Structured data — ограниченный public `ItemList`; estimated/unknown date не представляется подтверждённым `Event`, private state и source URL отсутствуют. Calendar sitemap URL добавляется только при schema-ready и наличии публично видимого события. Второй sitemap generator не создан.

## Security, privacy и отказоустойчивость

- Все mutation проходят gate/action/service, CSRF Livewire и rate limiter; user/target/status/source/timezone с клиента не считаются доверенными.
- UI выводит пользовательские и импортированные строки через escaped Blade; raw provider URL, media source, credentials и private correction note отсутствуют в public DTO.
- Schema guards позволяют развернуть код до migration без fatal error: пользователь получает локализованное unavailable состояние.
- Cache/notification failures не откатывают уже подтверждённую доменную транзакцию; ошибки report-ятся без provider payload.
- Account export содержит собственные subscription codes/preferences, но не других подписчиков или внутренние correction notes. Удаление пользователя каскадно удаляет его подписки и preferences, не удаляя общественно значимое расписание.
- Calendar correctness не зависит от новой queue: текущий статус и delayed presentation вычисляются из сохранённого состояния и текущего времени.

## Развёртывание и rollback

Migration расширяющая и SQLite-compatible. Перед production migration нужна обычная резервная копия; старые поля и маршруты не меняются. Rollback удаляет только четыре новые таблицы. Код защищён schema guard, поэтому временно работает с unavailable state, но полноценный календарь требует migration. Исторические записи не заполняются из неоднозначных данных автоматически; редактор может добавить проверенные события после развёртывания.

## Ручная проверка

- Проверить `/calendar`, day/week/month/recent, RU/EN и legacy redirects с валидными и невалидными period.
- Проверить exact datetime на границе дня в двух IANA timezone, DST, exact date без сдвига, month/year/range/unknown.
- Проверить отсутствие hidden/deleted/unpublished целей, дублей от media/translation и отрицательного countdown.
- Проверить narrow phone agenda, desktop month grid, zoom, keyboard focus, loading/empty/error live regions и cleanup countdown после Livewire navigation.
- Проверить личный calendar/noindex/no-store, подписку/отписку, независимые категории и suppression blacklist/not-interested.
- Проверить admin gate, invalid target ancestry, locked override, correction history, postpone/cancel/release и private note.
- Проверить repeated observer/import, deterministic notification UUID, preference suppression и отсутствие source URL/private text.
- Проверить public cache locale/time boundary, targeted version bump, sitemap eligibility, canonical/`hreflang` и public-only ItemList.
- Выполнить additive migration на чистой временной SQLite, `Pint`, route/view/config cache, frontend build и browser smoke-check; production данные не очищать.

## Известные ограничения

- Рабочие данные пока не содержат подтверждённых `episodes.released_at`; календарь не выдумывает историю и заполнится редактором или будущими проверенными импортными датами.
- Автоматический прогноз перевода, recurrence generation, гарантированный pre-release scheduler, iCalendar feed и bulk editor отсутствуют.
- Отдельные premium/region entitlement tables и provider market timezone отсутствуют; применяется существующая publication/audience/availability boundary.
- Доступные интерфейсные locale — RU и EN; пользовательский текст и provider labels автоматически не переводятся.

## Интеграция личной библиотеки Task 09

`CatalogPersonalUpdateQuery` не создаёт второй release feed: он применяет существующий `ReleaseScheduleVisibility` к опубликованным/released событиям и сравнивает их с owner/title acknowledgment. New episode/season/translation/subtitle/quality badges используют stable event type, а technical `updated_at`, duplicate source и hidden/unpublished/inaccessible event не считаются личным обновлением.

Первый baseline берётся из semantic bookmark/status/progress activity; acknowledge monotonic и не меняет status или progress. Historical `completed` сохраняется после нового сезона, а новый event остаётся отдельным indicator. `not_interested`/`blacklisted`, notification preferences, Premium/region visibility и existing calendar subscriptions продолжают применяться каноническими boundaries; UI-бейдж не отправляет обязательное уведомление и не требует нового scheduler.
