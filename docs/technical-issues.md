# Технические обращения и поддержка

Обновлено: 17.07.2026.

Этот документ — единственный владелец контракта Task 20. Он описывает ошибки уже существующего контента и функций портала. Заявки на отсутствующий или новый контент принадлежат Task 19 и `content_requests`; жалобы на пользователей, комментарии, отзывы и иной UGC принадлежат moderation/report-доменам. Юридические обращения не преобразуются в технические тикеты.

## Результат аудита до внедрения

До Task 20 в web/API/localized routes, миграциях, моделях, Livewire, уведомлениях и storage не существовало technical/support/playback ticket aggregate, legacy ticket number, message/history/assignment/attachment/follower/confirmation/merge таблиц или публичного known-issues каталога. Generic reports были только moderation-жалобами, а Task 19 уже владел публичными запросами на контент. Поэтому внедрён первый и единственный private technical-issue aggregate; данные Task 19 и moderation не переносились и не менялись.

Аудит также подтвердил:

- каталог использует канонические `CatalogTitle`, `Season`, `Episode`, `LicensedMedia` и `Translation`; отдельные модели audio/subtitle tracks отсутствуют;
- source health уже принадлежит `LicensedMedia` и его manager/service boundary;
- private upload disk и database notifications уже доступны;
- интерфейс поддерживает `ru` и `en`;
- release-calendar и канонический Premium entitlement/billing ledger существуют, но платёжный provider, invoices/refund request UI, rights-holder и territory-license workflow не настроены;
- sitemap формируется явным allowlist и private account/admin routes в него не входят.

## Границы, identity и видимость

Внутренняя identity — неизменяемый bigint `technical_issues.id`. Для URL используется случайный UUID `public_id`, для общения — случайный `ISS-` плюс 20 шестнадцатеричных символов. Номер не содержит user ID, не последовательный, не является секретом и никогда не заменяет policy check.

Все тикеты private. Их видят requester, авторизованный confirmer/follower в пределах подготовленного представления, и сотрудники с gate `manage-technical-issues`. Requester не видит identities других участников, staff assignment, private notes, raw diagnostic columns или чужие attachments/messages. Support DTO может включать только релевантный публичный account context и sanitized diagnostics.

Canonical routes:

- `issues.create`, `issues.mine`, `issues.show`, `issues.attachments.show`;
- localized `localized.issues.create`, `localized.issues.mine`, `localized.issues.show`;
- staff-only `admin.issues`.

Create требует authenticated account; подтверждение email не требуется, иначе пользователь не смог бы сообщить о неисправной доставке verification/reset notification. Anonymous legacy reporting не существовало, поэтому tracking token/email workflow не добавлялся. Ручные confirm/follow чужого incident требуют verified account; exact-duplicate intake проходит отдельный create limiter, атомарно записывает occurrence и присоединяет отправителя к canonical incident, чтобы приватный redirect оставался доступен. Создание, ownership и private visibility всегда привязаны к server-authenticated user. Все страницы используют private/no-store/noindex middleware и private SEO metadata. Attachment route дополнительно выдаёт `nosniff`, restrictive CSP и `X-Robots-Tag`. Тикеты, inbox, admin и attachments отсутствуют в sitemap, structured data и social metadata.

## Типы и цели

`TechnicalIssueType` хранит 45 стабильных кодов:

- playback: `video_unavailable`, `video_loading_failure`, `playback_stops`, `excessive_buffering`, `wrong_video`, `quality_unavailable`, `quality_label_mismatch`, `fullscreen_problem`, `autoplay_problem`, `player_controls_problem`;
- episodes/content: `wrong_episode`, `wrong_season`, `duplicate_episode`, `missing_episode`, `incorrect_episode_order`, `incorrect_episode_number`, `metadata_error`, `image_problem`;
- audio/subtitles: `audio_missing`, `audio_language_mismatch`, `audio_sync`, `translation_studio_mismatch`, `subtitles_missing`, `subtitle_language_mismatch`, `subtitle_sync`, `subtitle_text_error`;
- progress/player environment: `progress_not_saved`, `progress_incorrect`, `continue_watching_problem`, `browser_compatibility`, `mobile_device_problem`, `page_rendering_problem`, `livewire_interaction_problem`;
- portal features: `broken_internal_link`, `broken_external_reference`, `search_problem`, `filter_problem`, `notification_problem`, `calendar_problem`, `account_problem`, `regional_access_problem`, `premium_access_problem`, `accessibility_problem`, `performance_problem`, `other_technical_issue`.

`TechnicalIssueTypeRegistry` — единственный источник eligible targets, required actual/steps/timestamp/language fields, diagnostic relevance, conservative default severity, support team, requester-private behavior и разрешённых resolution types. UI показывает только поля выбранного типа, а server повторно валидирует весь input. Переведённые labels никогда не сохраняются.

Allowlisted target codes: `title`, `season`, `episode`, `media`, `translation`, `page`, `account`, `notification`, `calendar`, `search`, `general`. В БД используются явные nullable FKs вместо произвольного morph class. Resolver проверяет published/access-visible title и цепочки ownership season → title, episode → season, media → episode/title, translation → title. Нельзя подставить source/track ID из другого episode. Page-rendering, Livewire, browser/mobile, accessibility, performance и broken-link типы разрешают server-resolved page/general/account/notification/calendar/search context, а также title/season/episode/media context, чтобы реальная страница портала не теряла canonical feature/content identity. Account и notification targets автоматически сохраняют requester-private duplicate scope.

Поскольку самостоятельных audio/subtitle track models нет, такие обращения используют канонический episode/media target и server-validated stable language code; storage path или имя subtitle-файла не принимается. Regional/premium/account типы описывают симптом, но canonical entitlement/access state всегда читается сервером через существующие boundaries; формы не принимают premium/region truth, password, MFA, card data, payment или recovery credentials. Calendar target разрешается через существующую calendar entity, когда она есть в контексте.

Metadata error создаёт evidence для editor и никогда непосредственно не изменяет каталог. Если обращение является запросом на новый контент, staff выбирает безопасный reroute code `content_request`; технические diagnostics/attachments автоматически в Task 19 не копируются.

`missing_episode` в Task 20 означает дефект уже известной каталогу серии: она пропала из списка или нарушена её существующая привязка к сезону. Серия, которой ещё нет в каталоге и которую пользователь просит добавить, всегда относится к публичному Task 19 content-request workflow. Для технического дефекта разрешён только канонический существующий title/season target; отсутствующая серия не представляется выдуманным ID.

## Форма, entry points и player context

Один `TechnicalIssueFormPage` и один `CreateTechnicalIssue` обслуживают ссылки из player/title/season/episode, personal library/history, account settings, notification inbox, navigation/footer и page/search context. Title footer использует уже bound canonical `CatalogTitle`, player — выбранные season/episode/media, а library/history context открывает только подходящие progress/continue-watching и page types. Отсутствующая продуктовая поверхность не получает фальшивую кнопку.

`TechnicalIssueContext` создаёт encrypted expiring envelope на 120 минут. Он может содержать только IDs title/season/episode/media/translation, allowlisted feature и route, sanitized relative path, selected quality/audio/subtitle codes, player component и приблизительную позицию. Create action заново разрешает target и relationship. Protected source URL, playback grant, provider credential, signed URL, cookie, session/CSRF/auth/reset/verification/OAuth token, DRM detail и storage path в envelope и Livewire state отсутствуют.

Позиция хранится отдельно в целых неотрицательных секундах, ограничена 24 часами и clamp-ится до известной duration. Она не изменяет progress/history. Summary — Unicode plain text 4–240 символов; expected/actual — до 4000, steps — до 6000. Простые video-unavailable/loading типы не требуют лишнего текста; сложные UI проблемы требуют actual и reproduction steps. HTML не исполняется, URLs не linkify-ятся автоматически, line breaks сохраняются.

Form draft хранится только в authenticated session и не включает temporary binaries или diagnostics. Перед session persistence prose проходит canonical sanitizer/redaction и сразу возвращается в public Livewire state без обнаруженного secret span; language/quality values сохраняются только после code allowlist. Submission UUID преобразуется в user-scoped hash и обеспечивает idempotent retry/double-click handling. Livewire properties с context/locale/submission identity locked; models, request objects, Eloquent graphs, diagnostic payloads и attachment bytes не сериализуются.

## Диагностика и redaction

Минимальный target/feature/route context сохраняется независимо от optional browser diagnostics. Неотмеченный checkbox согласия не собирает client diagnostics. После согласия Vite-модуль передаёт только allowlisted browser family/major, OS family, device category, viewport, timezone и online state. В приложении пока нет canonical client-generated public error code, поэтому форма не принимает произвольную строку под видом такого кода; nullable server field зарезервирован только для будущего trusted context.

Не собираются raw user-agent, IP, headers, cookies, session ID, bearer/CSRF/reset/verification/OAuth/provider tokens, local/session storage, arbitrary form data/history, clipboard, password/MFA/payment data, precise location, private export/reset URLs, protected media/source URL или exception/SQL/stack/storage path. Нет client fingerprint и автоматического speed test. Raw UA/IP остаются только в уже существующих auth/security boundaries и не копируются в тикет.

`TechnicalIssueTextSanitizer` удаляет control/bidi abuse и консервативно redacts похожие на credentials/tokens/cookies, credential/private-network/signed/protected-media links, email/phone spans до duplicate identity и persistence. Redaction audit хранит field, reason и before/after hashes, но не удалённый secret. Staff может повторно redact ticket field или message; requester payload сразу использует redacted value. Автоматическое обнаружение не объявляется идеальным.

Одна запись occurrence на user/ticket агрегирует независимых затронутых пользователей без повторного счёта. Optional diagnostic dimensions могут показать staff browser/device distribution; participant identities не отображаются. Terminal diagnostics очищаются bounded-командой после retention window, а сам факт occurrence остаётся.

## Вложения

Разрешены максимум три optional screenshots PNG/JPEG/WebP, до существующего `uploads.max_image_kilobytes`, 6000 px по стороне и 24 млн пикселей. Проверяются upload error, исходное расширение, размер, decoded content/MIME и dimensions. GD декодирует и заново кодирует raster с гарантированным освобождением image/output buffer, тем самым удаляя original filename/metadata и блокируя SVG/HTML/script/archive/polyglot executable payload. Случайное имя сохраняется на existing private disk вне public executable tree. В БД хранится стабильное `screenshot-N.ext`, а requester/support presenter и account export создают локализованное имя только при выводе; translated attachment labels не становятся identity data.

Malware scanner не имитируется: в проекте его нет. Защита основана на узком raster allowlist, content decode/re-encode, private storage и never-execute serving. URL inspection/fetch отсутствует, поэтому attachment/diagnostic input не создаёт SSRF.

Download всегда связывает attachment с ticket на сервере и авторизует `viewAttachment`. Requester видит только собственные evidence и requester-visible support attachment; merge не открывает чужие файлы. В БД хранится нейтральное generated display name, а локализованная подпись снимка формируется presenter/export по active locale; нет predictable storage URL, public cache или sitemap entry. Livewire temporary upload limiter — 30/min, а глобальная temporary-upload boundary до записи временного файла применяет тот же 2 MiB и JPEG/PNG/WebP allowlist, что и доменный attachment service. Встроенный Livewire cleanup удаляет abandoned temporary uploads. После закрытия/withdrawal screenshots могут быть удалены bounded retention command только после 365 дней; failed file deletion не удаляет DB reference.

## Создание, duplicate detection и anti-abuse

Create action выполняет policy/account/type/target/rule/text/diagnostic/attachment/idempotency/rate checks, duplicate search и transactional создание `submitted` ticket, conservative severity, `normal` priority, history, requester follow, occurrence, diagnostics/attachments и after-commit notification. Клиент не выбирает status, severity, priority, assignee, support team или resolution.

Лимиты на одного пользователя: creation 6/hour, update 12/min, engagement 20/min, messages 8/min, reopen 3/day; upload route 30/min. Keys не содержат sensitive input и намеренно общие для action/user, поэтому смена wording/type/ticket не обходит окно. Bulk staff operation ограничена 10 tickets и повторно авторизует каждый.

Exact identity `v2` использует type, target chain, source/translation, route/feature, 30-second timestamp bucket для временных проблем, language/quality/client dimensions где они релевантны, safe error code и requester ID для account/notification/premium/regional/progress/continue-watching private context. Любые заполненные summary/expected/actual/steps после той же sanitization получают bounded normalized evidence fingerprint. Поэтому два разных текстовых симптома на одной странице или серии не становятся exact только из-за общего target, а пустой простой outage всё ещё может честно объединяться. Active exact identity имеет unique constraint. Candidate search сначала сужается indexed type/episode-or-season-or-title/open state и максимум до 12 строк; тот же episode с неизвестным/другим source становится probable, другой episode того же title — related, а весь ticket table в PHP не сравнивается.

Результаты: `exact`, `probable`, `related`, `none`. Exact active duplicate не создаёт второй ticket: non-owner получает unique confirmation, follow и occurrence canonical ticket. Requester повторно получает тот же ticket без self-confirmation. Если exact совпадение обнаружено конкурентно после private screenshot processing, новый raster-файл связывается с canonical ticket только как evidence его uploader; тот же uploader/content hash идемпотентно оставляет одну attachment-row/file. Probable/related только подсказывают и никогда автоматически не merge. Candidate details показываются normal user только если policy уже разрешает canonical issue, чтобы сам поиск не раскрывал чужой private ticket.

Confirm и follow — разные unique idempotent non-GET actions. Requester автоматически follows, но не подтверждает собственный тикет. Identities участников private; counts не являются severity/priority. Unfollow/unconfirm не удаляют ticket. Merge upserts follows/confirmations/occurrences без duplication и удаляет canonical requester self-confirmation.

## Статусы, история и staff workflow

Стабильные статусы: `submitted`, `triage_pending`, `clarification_needed`, `confirmed`, `assigned`, `in_progress`, `waiting_for_external_source`, `waiting_for_requester`, `resolved`, `resolution_verified`, `closed`, `reopened`, `rejected`, `merged`, `withdrawn`. `TechnicalIssueStatus::canTransitionTo()` и `TechnicalIssueWorkflow` владеют transition matrix; UI не является boundary.

Meaningful transition создаёт append-only status history с actor, old/new codes, public reason/message и отдельным staff-only private note. Повтор той же state идемпотентен. Requester timeline не выбирает private note. `TechnicalIssueWorkflow::transition()` server-side отклоняет `assigned`, `resolved`, `resolution_verified`, `reopened`, `merged` и `withdrawn`, если вызов не помечен самим dedicated assignment/resolution/verification/reopen workflow; подмена Livewire `desiredStatus` поэтому не обходит требуемые инварианты. Эти состояния проходят соответственно через assignment, resolution/verification, dedicated reopen, merge и requester-withdraw actions. Reopen всегда требует причины, использует отдельный limiter, увеличивает `reopen_count` и не может быть вызван через generic staff status selector. Terminal state освобождает active duplicate identity; reopen проверяет, что за время resolution не появился новый canonical active incident.

Requester редактирует только summary/expected/actual/steps в submitted/triage/clarification/waiting/reopened, но не target/type. Withdraw разрешён в ранних states. Если есть другие confirmers, ownership анонимизируется и shared incident остаётся triage; иначе status становится withdrawn и notifications/follow прекращаются.

Severity (`low`, `medium`, `high`, `critical`) описывает impact. Priority (`low`, `normal`, `high`, `urgent`) описывает processing order. Users не назначают их; registry ставит conservative default, а staff меняет с history. Confirmation count автоматически не повышает ни одно значение.

Assignment принимает только configured administrator и allowlisted team (`support`, `content`, `video`, `subtitles`, `accounts`, `infrastructure`, `accessibility`), сохраняет assignment history и не раскрывает staff identity requester. Переход triage/confirmed/reopened в `assigned` проходит через ту же central matrix; retry с уже выбранным assignee может завершить разрешённый status transition, не создавая duplicate assignment row. Явное снятие единственного assignee со статуса `assigned` возвращает обращение в `confirmed`, сохраняя team и обе записи history. Terminal ticket нельзя назначить.

Messages имеют UUID, author, sanitized body, idempotent submission key и visibility `requester_visible` или `internal`. Clarification/reply может вернуть waiting ticket в triage/in-progress. Internal note отсутствует в requester query/DTO/Livewire/notification/export и защищён server-side, а не CSS.

Resolution types: `fixed`, `source_replaced`, `fallback_enabled`, `metadata_corrected`, `episode_mapping_corrected`, `subtitle_corrected`, `audio_corrected`, `configuration_corrected`, `user_setting_guidance`, `cannot_reproduce`, `duplicate`, `external_provider_issue`, `unsupported_environment`, `intended_behavior`, `rejected`. Registry ограничивает resolution по типу. Resolution требует public-safe summary и может иметь separate private note.

Requester или confirmer может ответить fixed/still broken в resolved state. Confirmer state хранится отдельно; requester fixed переводит в resolution_verified, still broken вызывает rate-limited reopen с причиной. Предыдущая resolution/history сохраняется. Временная auto-verification/closure и fake ETA/SLA/queue position отсутствуют.

Reject требует stable reason и public explanation. Reroute destinations: `content_request`, `moderation_report`, `account_security`, `rights_holder`. Это guidance/linkage, а не молчаливое создание записи в чужом домене. Автоматическое conversion появится только при существующем destination action с совместимой policy; private diagnostics не копируются.

Merge выбирает open canonical ticket только при полном совпадении allowlisted target identity: type, target type, title, season, episode, media, translation, feature и route. Поэтому одинаковый issue type у разных серий, сезонов или источников одного сериала не может быть ошибочно объединён; related/probable candidates требуют раздельной triage. Workflow оставляет duplicate ticket и его original evidence, создаёт unique mapping, переносит engagement/occurrences, ставит `merged`/`duplicate` resolution и отправляет canonical link. Requester видит номер и актуальный public-safe status canonical ticket рядом со своей исходной заявкой. Private requester contexts нельзя merge между разными requester. Legacy/source UUID route остаётся доступен только его участникам; чужие messages/attachments не становятся видимыми.

## Source health, notifications и administration

Один report никогда автоматически не отключает media. Staff-only `TechnicalIssueSourceHealthService` lock-ит canonical `LicensedMedia`, проверяет связь с ticket и применяет explicit `under_review`, `disabled`, `restored`; automatic probes продолжают принадлежать существующему `MediaSourceHealthManager`. Disable разрешён только опубликованному source, а restore — только source, ранее отключённому записанным canonical action, поэтому обращение не публикует чужой draft/unavailable record. Action history сохраняет old/new health без provider URL/credential. Только после реального source change affected title ID передаётся existing catalog invalidator, который обновляет зависимые public catalog generations и TitleDetail без full cache flush; рабочие fallbacks и source record не удаляются.

Database notifications имеют deterministic UUID из recipient/ticket/revision/category/canonical, поэтому retry не создаёт дубликат. Категории: submitted, clarification, support reply, status changed, assigned, resolved, resolution verified, closed, reopened, rejected, merged. Public workflow updates получают только requester/followers/confirmers; private assignment получает исключительно новый assignee и не раскрывает internal routing участникам. Actor не уведомляет себя. Preferences отдельно управляют requester/confirmer/follower/support-reply updates. Payload содержит только UUID/number/type/status/category/revision и private route; body не содержит attachment, diagnostic, source URL, IP/UA, message excerpt, internal note или recipient list.

My Tickets использует requester/waiting/followed/confirmed scopes, allowlisted status/type/search/sort, grouped counts, deterministic pagination 12/page и viewer-specific presenter. Support queue — staff-only, 20/page, status/type/severity/priority/team/target/assignment/source-health filters, bounded search, affected user/attachment/message/confirmation counts и deterministic sort. Стабильные `severity`/`priority` codes остаются domain values, а согласованные server-side числовые `severity_sort_rank`/`priority_sort_rank` обслуживают только индексированный порядок очереди; клиент не читает и не задаёт ранги. Requester counts учитывают только requester-visible messages/attachments; даже существование internal note не попадает в их payload. Lists eager-load only selected relations/counts and never attachment bytes, message bodies или diagnostic payload. Messages paginate 20/page; related candidates ограничены 8.

Bulk priority/assignment требует explicit selection, максимум 10 records, повторную authorization каждого ticket и сообщает partial failure. Resolve/merge/source disable остаются single-ticket actions и не выполняются bulk без проверки.

Private ticket HTML/DTO/diagnostics/attachments/follow state/assignment/notes не кэшируются глобально. Registry translations могут использовать обычный framework translation cache, но current issue state всегда читается с viewer-scoped DB query. Нет второго cache subsystem и global flush; mutations увеличивают version, меняют canonical DB rows и точечно invalidates catalog/player cache только для source action.

## Database, lifecycle и совместимость

Три additive reversible migrations создают 13 таблиц, requester-order index и два derived sort rank: aggregate, diagnostics, messages, attachments, status history, assignment history, confirmations, followers, occurrences, merges, redactions, source actions и notification preferences. `2026_07_17_141321_add_sort_ranks_to_technical_issues_table.php` idempotently backfill-ит rank из существующих stable severity/priority codes, не переименовывает и не удаляет записи, и добавляет только два индекса точной deterministic support-сортировки. UUID/number/submission/active identity, one confirmation/follow/occurrence per user/ticket и one merge per duplicate защищены unique constraints. 41 явный index поддерживает requester/support queues, target/source/error narrowing, ordered timelines, assignees и participant lookups; default requester pagination использует `(requester_id, updated_at, id)`, priority/severity queue — соответствующий `(sort_rank, created_at, id)`, а status-filtered requester pagination сохраняет отдельный status-prefixed access path. `TechnicalIssueSchema` требует оба rank column и до migration показывает безопасный unavailable state. SQLite foreign-key/integrity и query plans проверены на disposable database.

`TechnicalIssueSchema` даёт rolling-deploy guard: до миграции ссылки/страницы возвращают безопасный unavailable state, а hooks account/title merge не падают. Миграция не изменяет legacy tables, Task 19, moderation, catalog IDs, source URLs, cache keys или route names. Rollback удаляет только новые таблицы; перед rollback production tickets/attachments должны быть экспортированы, а source-health decision при необходимости вручную отменён через тот же authorized source service.

Account export включает только собственные tickets, requester-visible messages/history, собственные engagement/occurrences и protected attachment manifest. Account deletion удаляет preference/engagement, anonymizes requester/author и отзывает owner attachment access, сохраняя operational/audit evidence. Account merge service moves ownership/messages/attachments/history participants and deduplicates follows/confirmations/occurrences idempotently. Title/season/episode/media merge service re-targets issues и пересчитывает exact identity без потери public UUID/number/history. Все mutating merge scans используют primary-key cursor (`eachById`), поэтому изменение requester/target/user columns и удаление occurrences не пропускает строки после первой тысячи.

Операционные ticket/history сохраняются. Команда `php artisan technical-issues:prune-private-data --limit=200` bounded batches удаляет optional diagnostics terminal tickets после 180 дней, redacts occurrence diagnostics и удаляет screenshots только у closed/withdrawn tickets старше 365 дней. Cron/queue не обязательны и автоматически не добавлены; operator запускает command согласно privacy runbook. Rejected/merged evidence сохраняется для moderation/audit до отдельного утверждённого retention policy.

## Multilingual, accessibility и UI

Все labels, help, validation, loading/empty/error/success/confirmation/ARIA тексты находятся в `lang/ru/issues.php` и `lang/en/issues.php`; key parity обязательна. Internal codes не переводятся в БД. Redaction хранит стабильный `[[technical-issue-redacted]]`, а presenter, edit hydration и account export заменяют его на `issues.redacted` активного locale; русская или английская фраза никогда не становится persisted identity data. User prose сохраняется в исходном языке/скрипте и автоматически не переводится. Locked locale восстанавливается на hydration, localized routes сохраняют locale и фильтры.

Формы имеют visible labels, help/error association, live regions, keyboard-accessible controls, 44 px touch targets, disabled/loading states и `wire:key`. Cards/timeline/filter/dialog layouts используют существующие Tailwind/Blade patterns, wrap long prose/codes, не зависят только от color/hover и поддерживают narrow phone, landscape, tablet, desktop, zoom и reduced motion. CSS/JS business logic в Blade, Volt, `@php` и polling не используются.

## Ошибки и безопасные ограничения

Invalid type/target/source/track/status/severity/priority/assignment/resolution/attachment/merge ID server-side отвергается. Authorization, validation, database, duplicate, rate-limit, upload и unavailable-schema failures получают локализованное безопасное сообщение без SQL, exception/class/table/path/provider details. Mutations выполняются через Livewire POST/CSRF, destructive GET отсутствует. Attachment serving не принимает URL и исключает traversal/SSRF.

Create boundary повторно строит DTO только из полей, разрешённых rule выбранного типа. Timestamp, audio, subtitle и quality от устаревшей или подделанной формы очищаются, когда тип их не поддерживает; browser/OS/device/viewport/timezone/network поля очищаются без diagnostics consent. Stable type/target/route codes остаются независимыми от переведённых labels.

Осознанные ограничения текущего продукта:

- нет anonymous intake, public known-issues page, automatic elapsed-time closure или ETA;
- нет first-class audio/subtitle track entities, поэтому используется episode/media плюс language code; для выбранного media сервер дополнительно связывает существующий canonical `Translation` по точному каталожному имени, когда такая связь уже существует, но не выдумывает studio ID при её отсутствии;
- нет external malware scanner: действует raster re-encode/private storage boundary;
- canonical calendar и Premium entitlement records не копируются в ticket; provider billing, territory/licensing/rightsholder records отсутствуют, поэтому ticket фиксирует симптом, но не выдумывает состояние или bypass guidance;
- нет безопасного универсального automatic conversion между доменами; reroute сохраняет историю и даёт guidance без копирования private context;
- retention command не требует нового scheduler и должен запускаться оператором.

## Проверка и acceptance

Проверки Task 20 выполнены без добавления или запуска automated tests, согласно ограничению задачи:

- полный Markdown/route/schema/model/policy/Livewire/Blade/JS/cache/SEO/import/source audit;
- Pint и PHP syntax inspection;
- fresh migrations, rollback/re-apply, 13-table/41-index inventory, SQLite `foreign_key_check` и `integrity_check` на disposable database;
- query plans для exact identity, requester pagination, indexed priority/severity support ordering и occurrence distribution;
- manual transactional smoke: два users → один exact canonical ticket, unique confirmation, два follows/occurrences, полный submitted → triage → confirmed → assigned → in progress → resolved → requester verified → closed lifecycle и deterministic notifications;
- manual attachment smoke: valid PNG был decoded/re-encoded на isolated private disk с generated path без original filename и удалён canonical service; SVG/script body, замаскированный как PNG, отклонён safe `invalid_attachment` до storage;
- route middleware inspection для canonical/localized/admin/attachment paths;
- exact parity 429/429 translation leaves и placeholder inspection;
- source scan на raw secrets/UA/IP/headers/cookies/storage/URLs, Blade queries, `@php`, inline CSS/business JS, debug/TODO;
- Vite production build; managed Chromium requester/list/detail/create и staff queue smoke в RU/EN на desktop/mobile подтвердил policy denial, noindex canonical metadata, отсутствие horizontal overflow и console errors на разрешённых страницах.

Перед production rollout выполнить обе additive migrations, обновить application code одновременно, rebuild config/route/view caches штатным deployment workflow и проверить private routes под requester/staff accounts. Не запускать destructive migration commands и не очищать весь application cache.

## Интеграция с центром помощи Task 21

Help article остаётся public/editorial aggregate и не хранит diagnostics, screenshots, correspondence или resolution. При переходе в Task 20 `HelpEscalationService` передаёт только stable article UUID внутри существующего encrypted expiring context; `TechnicalIssueTargetResolver` повторно проверяет опубликованную identity и сохраняет nullable `technical_issues.help_article_id`. Search query, provider/source URL, feedback actor и private article note не передаются.

Ticket form показывает contextual troubleshooting link, но решение о типе/target, duplicate detection, attachment/diagnostic preview, privacy и submit остаётся только у Task 20. Broken published media — ticket; отсутствующий title/season/episode/translation/subtitle/quality/metadata — Task 19. Полный help contract: [`help-center.md`](help-center.md).

## Player failure context Task 07

Player report link передаёт только stable title/season/episode IDs, opaque media ID, selected quality/audio/subtitle codes и allowlisted capability summary, уже подготовленные `TechnicalIssueContext`. Signed/provider URL, storage path, credentials, token, cookie, raw HLS error и private user history не передаются. Categories охватывают start/buffering/wrong episode/source/audio/subtitles/translation/quality/order/fullscreen/mobile/other через существующий тип обращения, duplicate detection и rate limits.

Client fallback не записывает health verdict сам и не отключает source. Staff source action повторно авторизуется и использует существующий health/admin/import boundary. Полный playback recovery/report privacy contract: [`audits/video-playback-report.md`](audits/video-playback-report.md).
