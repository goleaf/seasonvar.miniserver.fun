# Каноническая архитектура video playback

Проверено: 19.07.2026. Этот документ — владелец фактического playback-контракта Task 07. Живой чек-лист реализации и отката находится в `docs/plans/laravel-video-portal-modernization.md`. Портал воспроизводит только разрешённые проекту источники и не реализует обход DRM, подписи, оплаты, региона или ограничений поставщика.

## Итог аудита

В проекте остаётся один playback-контур:

`title page → CatalogTitlePlayer → CatalogTitlePlaybackQuery → CatalogPlaybackSourceResolver/CatalogEntitlementService → short-lived signed same-origin grant → allowlisted provider redirect или storage response → native video/Plyr/HLS.js → CatalogUserStateService`.

- Единственная публичная поверхность плеера встроена в full-page Livewire-карточку `/titles/{catalogTitle:slug}`. Отдельной episode/player/modal/fullscreen-страницы, локализованного маршрута тайтла или legacy player route нет.
- `GET /playback/{licensedMedia}` (`playback.source`) — signed delivery boundary, а не HTML-страница. Мобильный API выдаёт сессию через `POST /api/v1/titles/{titleSlug}/playback-sessions`, signed source через `GET /api/v1/playback/{licensedMedia}` и принимает progress отдельно.
- Единственная библиотечная связка — native `<video>`, Plyr `3.8` и HLS.js light `1.6`. Video.js, Shaka, dash.js, MPEG-DASH и второй initializer отсутствуют.
- Аудированный постоянно обновляемый SQLite-каталог содержит более 875 тысяч `LicensedMedia`; фактические источники snapshot — только MP4. Код допускает MP4/M4V/WebM/MOV и HLS `m3u8`; реальная adaptive quality заявляется только для настоящего HLS manifest.
- Логическая серия определяется стабильным `Episode.id`, а не URL, качеством, озвучкой или `LicensedMedia.id`. Замена источника не меняет identity progress/history.
- Новая migration не потребовалась: текущая схема уже хранит source identity, publication, health, format, quality, variant, progress concurrency и account settings. Неподтверждённые audio/subtitle/region/age данные не выдумываются.

## Маршруты и SEO

| Surface | Route | Назначение | Индексация |
| --- | --- | --- | --- |
| Карточка и встроенный плеер | `titles.show` | Канонический HTML, episode/media query state | Clean title canonical; query state не создаёт canonical duplicate |
| Web source grant | `playback.source` | Signed, viewer-bound повторная авторизация и redirect/storage response | `X-Robots-Tag: noindex, nofollow`, `private, no-store` |
| Authenticated direct download | `titles.media.download` | Отдельная policy boundary, direct-file streaming и single Range | Не является playback page |
| Mobile session | `api.v1.titles.playback-sessions.store` | Авторизованный playback context без raw provider URL | JSON, throttled |
| Mobile source grant | `api.v1.playback.source` | Signed grant с opaque server grant | JSON/media boundary, не sitemap |
| Mobile progress | `api.v1.titles.episodes.progress.update` | Authenticated stable episode progress | Private write |

Task 07 удаляет временный signed playback URL из `VideoObject.contentUrl` и page video metadata. Structured data сохраняет только публичные title/series/season/episode facts; private source identifiers и grants не попадают в canonical, Open Graph, JSON-LD или sitemap. В существующую streamed sitemap входят только канонически видимые title pages; delivery/query URLs исключены.

## Канонический playback context

`CatalogTitlePlayer` готовит только данные текущего запроса:

- stable title/season/episode/media IDs;
- локализованные title/season/episode labels;
- текущую season lane и server-resolved previous/next playable episode;
- разрешённые варианты только выбранной серии, сгруппированные по stable variant, quality и format;
- один выбранный short-lived source grant и MIME/format;
- trusted progress-session token только для verified authenticated viewer;
- saved position, account playback preferences и минимальный RU/EN player dictionary;
- safe issue/help links и public Media Session metadata.

Полные Eloquent graphs не сериализуются в JavaScript. Livewire public properties содержат только validated IDs/codes, query selection, locked failure IDs и render generation; raw `playback_url`, `path`, credentials, administrative metadata и private provider responses туда не входят. HTML содержит только один реально выбранный same-origin signed grant, необходимый `<video>`; альтернативные upstream URLs не выдаются.

## Episode identity, order и navigation

`CatalogTitlePlaybackQuery` — единственный владелец playability и порядка. Он проверяет принадлежность episode → season → title и применяет publication/audience/window/soft-delete/source-health filters. Порядок не использует database ID как номер серии:

1. season `kind`;
2. season `sort_order`;
3. nullable season `number`;
4. season ID только как deterministic tie-break;
5. episode `kind`;
6. episode `sort_order`;
7. episode `number`;
8. episode ID только как deterministic tie-break.

Regular и special lanes не смешиваются. Previous/next на странице и Media Session используют один `CatalogEpisodeNavigation`; переход через границу сезона выполняется тем же ordered query. Hidden, unpublished, soft-deleted, expired, audience-inaccessible и не имеющие playable source серии не входят в lane. Client не вычисляет next по ID и не опрашивает сервер каждую секунду.

HTML season list теперь загружает только episode metadata и `available_media_count`. Полные source summaries загружаются одной выборкой лишь для выбранной серии. API сохраняет прежний совместимый вариант `episodesForSeason(..., withMedia: true)`; mobile navigation использует лёгкий режим без source graph.

## Authorized source resolution

`CatalogPlaybackSourceResolver` принимает только server-loaded title/user/episode, opaque requested media ID и validated preferences. Он:

1. применяет `CatalogEntitlementService` к title, season, episode и media;
2. повторно проверяет hierarchy и publication/audience/availability window;
3. исключает `unavailable`, non-playable health и failed source IDs текущей bounded recovery session;
4. принимает только configured formats и `https` hosts из `PLAYBACK_ALLOWED_HOSTS` с public-DNS guard;
5. для storage допускает только configured disks и относительный путь без NUL, absolute path или `..`;
6. ранжирует explicit variant/audio/quality/format preference, provider priority, health, quality и deterministic ID tie-break;
7. возвращает только safe DTO с opaque media ID и signed same-origin URL.

Client-provided source ID никогда не превращается в URL напрямую: Livewire/API заново находят доступную строку и проверяют episode ownership. Fallback исключает уже отказавшие IDs, ограничен 100 ID и 12 server actions/minute. Authorization refresh ограничен 6 actions/minute. Retry/fallback не меняют premium, region, publication или audience decision.

Текущая catalog schema реально поддерживает `public|authenticated` audience и окна публикации. Отдельных source-level Premium, region-country или user-age полей сейчас нет; Premium Task 22 прямо не обещает особые источники/качество. Поэтому UI не показывает fake paywall/region/age controls, а enum denial states остаются локализованными fail-closed ответами для будущего честного entitlement adapter. Ни Premium status, ни client country, ни заявленный возраст не дают обход текущей проверки.

## Delivery, signed URL, Range, CORS и CSP

- Signed grant живёт `30..600` секунд (`PLAYBACK_SIGNED_URL_TTL_SECONDS`, default 300), содержит media ID и viewer binding и повторно проверяется в момент обращения.
- External source после проверки возвращается как `302` на allowlisted HTTPS provider. Laravel не становится generic proxy, не принимает arbitrary URL и не читает video body.
- Local/S3 delivery использует storage response. Большой файл не собирается в PHP string; web server/storage adapter сохраняет streaming/range semantics.
- Отдельный authenticated download responder поддерживает только один validated byte range, bounded 64 KiB buffer и не сохраняет копию. HLS segments не объединяются в файл.
- Responses получают `private, no-store`, `no-referrer`, `nosniff` и `noindex` headers. Signed URLs не кешируются в shared application cache.
- Provider/CDN обязан отдавать корректные MIME, Range и CORS для manifest, segments и subtitle tracks. Credentials mode не включается без отдельного provider contract.
- Report-only CSP теперь ограничивает `media-src`/`connect-src` `'self'`, `blob:` и configured `11cdn.org` origins вместо общего `https:`. Дополнительный разрешённый provider сначала добавляется в URL allowlist и отдельные CSP env lists; `unsafe-inline` и wildcard `*` не требуются.

## Formats, quality и source fallback

- Progressive MP4/M4V/WebM/MOV: каждая строка `LicensedMedia` — отдельный server-authorized вариант. Смена ссылки сохраняет position через bounded `sessionStorage` handoff, pause/play state восстанавливается браузером из канонического resume, а volume/mute/speed остаются в player preferences.
- HLS: native HLS используется там, где browser умеет его сам; иначе один HLS.js instance обслуживает manifest и segments. Automatic ABR остаётся native/HLS.js. Manual HLS level menu не заявляется, пока importer не хранит правдивые manifest levels.
- Quality selector показывает только реально доступные progressive rows выбранного variant. Stable technical values не переводятся; `auto` остаётся account preference и означает server/browser safe selection, а не выдуманный adaptive stream.
- Default priority: explicit episode URL → account preferred variant/quality → previous selected profile carried into next episode → portal resolver rank. Отсутствующий вариант временно пропускается и не стирает preference.
- HLS network retry — один; HLS media recovery — одна; progressive network retry — один. Затем server-side fallback выбирает другой разрешённый source того же episode, предпочтительно с тем же profile. Failed IDs не повторяются; бесконечного loop нет.
- Expired same-origin authorization обновляется только server action: access и source revalidate, shell получает новый signed grant, position переносится без client URL/token refresh. После bounded refresh ошибка переходит в обычный fallback/error state.

## Translation, audio и subtitles

Нужно различать interface locale, episode metadata language, source variant/studio, translation type, audio language и subtitle language. Task 07 не переводит внутренние identifiers и не принимает локализованную подпись как selection value.

Текущая source model достоверно хранит `variant_key`, `variant_type`, `variant_name`, `translation_name`, `quality`, `format` и boolean `has_subtitles`. Это позволяет:

- выбирать стабильный source variant/studio;
- отличать provider values `voiceover|original|subtitles|trailer` там, где importer их подтвердил;
- переносить preferred variant в следующую серию и безопасно fallback-ить;
- показывать original/subtitle source variant без выдачи фиктивной отдельной audio track.

Отдельной таблицы audio tracks, нормализованных audio-language codes или subtitle tracks/bodies в проекте нет. `has_subtitles` не является URL дорожки. Поэтому пустые Audio/Subtitles menus не отображаются и fake controls не создаются. Если `<track kind="subtitles|captions">` появится из будущего канонического subtitle service, Plyr применит native selection, RU/EN labels и non-fatal load error; raw SRT/ASS/SSA/HTML сейчас не вставляется и не исполняется. Отдельная поддержка WebVTT/SRT conversion/ASS renderer потребует additive domain, importer/admin ownership и отдельной проверки.

## Progress, resume, restart и completion

### Authenticated

- Одна unique строка `episode_view_progress` на `(user_id, episode_id)`; source replacement не меняет identity.
- Browser держит время локально. Heartbeat — 30 секунд и только при изменении минимум на 10 секунд; дополнительные flush происходят на play/pause, stable seek, visibility hidden, episode/source navigation, page lifecycle, ended и destroy.
- Каждый write содержит encrypted playback-session context и increasing event sequence. Server revalidates user/title/episode/media и известную duration, использует transaction + `lockForUpdate` и отклоняет старую session/sequence.
- Явный restart — отдельный rate-limited action: position/progress/completion сбрасываются в locked row и session sequence обновляется. Поэтому намеренный restart не конфликтует с правилом stale-write protection.
- Duration ограничена configured maximum; position clamp-ится и не доверяет browser duration, если у media есть server duration.

### Anonymous

- Гость хранит только episode ID, position, duration, completion hint и timestamp в `seasonvar.playback-progress.v1` local storage; единый Vite module ограничивает store 50 строками и 30 днями.
- Retention — 30 дней, максимум 50 episode entries. Нет source URL, grant, token, account/user ID или provider metadata.
- Storage failure не блокирует playback. Server write и cookie на каждый checkpoint не создаются. Автоматического merge в аккаунт текущий продукт не обещает.

### Resume и completion

Resume выполняется после `loadedmetadata`, clamp-ится и не seek-ит в последние 5 секунд. Source/quality handoff живёт в `sessionStorage` не более 5 минут. Canonical completion rule находится в `CatalogPlaybackCompletionRule`: `95%`, не более `15` секунд до конца или trusted `ended`. Completion сохраняется при source replacement, обновляет progress/sync/history/recommendation signals существующими сервисами и не требует отдельного player table.

Текущая продуктовая семантика разрешает completion после validated seek к порогу; Task 07 её не меняет скрытно. Credits metadata отсутствует. Отдельное season/serial progress поле не хранится: агрегаты вычисляются из canonical episode progress, поэтому нет второго состояния для рассинхронизации.

## Preferences и autoplay

`user_account_settings` и versioned device key `seasonvar.account-preferences.v1` остаются единственным preference contract. Legacy `plyr` local-storage читается только как bounded anonymous volume/mute fallback и переносится при следующей canonical device write; собственное storage Plyr выключено, а старый key не удаляется принудительно:

- autoplay;
- remember volume, volume `0..100`, muted;
- allowlisted speed `0.50..2.00`;
- preferred quality и variant;
- subtitles enabled;
- keyboard shortcuts enabled;
- reduced motion из account presentation settings.

Authenticated changes проходят existing `AccountSettingsService`, CSRF/Livewire session и bounded player action; anonymous changes остаются local. Slider/rate changes debounce-ятся, fingerprint-deduplicate-ятся и не создают write на каждый movement. При выключенном remember-volume server/device volume/mute не обновляются, speed сохраняется независимо.

После `ended` autoplay использует только server-resolved next playable link. Client запускает configurable countdown `3..30` секунд (default 8), показывает название следующей серии, Play now и Cancel, объявляет состояние screen reader и не poll-ит сервер. Escape/play/restart/navigation/destroy отменяют единственный timer. Preference `off` предотвращает переход. Последняя серия показывает final state без loop и без unrelated recommendation autoplay. Browser policy всё равно может запретить начальный autoplay со звуком.

## Player JavaScript и Livewire lifecycle

`resources/js/player.js` владеет video/Plyr/HLS lifecycle, source load/retry/fallback, buffering, progress, preferences, countdown, keyboard, fullscreen/PiP capability и Media Session. `player-navigation.js` — узкий Livewire bridge meaningful server actions. Blade содержит только prepared data/translated labels; inline CSS и application JavaScript отсутствуют.

- initialization reserved/ready markers гарантируют один session на video;
- `AbortController` снимает DOM/window/document listeners;
- HLS instance, heartbeat, retry, buffering, preference, notice и countdown timers уничтожаются;
- Livewire morph/navigation сначала flush-ит meaningful progress, затем pause/destroy старый player;
- detached audio не продолжает играть;
- `timeupdate` не вызывает Livewire;
- storage payloads считаются untrusted и проходят type/range/retention validation;
- user-facing errors получают только translation dictionary и `textContent`; `innerHTML`, raw keys, provider URL и console logging не используются.

## Controls, responsive behavior и accessibility

Plyr остаётся владельцем play/pause, seek, time, mute/volume, speed, captions, PiP и fullscreen. Portal controls добавляют previous/next, autoplay, restart, source groups, report/help и keyboard-help dialog.

- Space/K, arrows, M, F и C следуют scoped Plyr keyboard behavior; `Shift+N`, `Shift+P`, `P`, `?` и Escape обслуживают portal actions.
- Shortcuts работают только при focus внутри player/tools, не срабатывают в input/textarea/select/contenteditable и не являются единственным способом действия.
- Native fullscreen/PiP feature-detect-ятся; unsupported control library скрывает. Fake CSS fullscreen и fake PiP не создаются.
- Player использует `aspect-ratio: 16/9`, safe-area insets, 44 px coarse-pointer controls, bounded mobile menu, readable captions и landscape fullscreen geometry.
- iOS получает `playsinline`, native HLS fallback и browser-governed fullscreen/PiP/autoplay/background behavior. Android/Chromium использует feature detection, HLS.js/MSE только при поддержке и не получает device-specific hacks.
- Focus visible глобально, dialog возвращает focus, status/countdown/error regions локализованы. Время не объявляется каждую секунду.
- `prefers-reduced-motion` и account reduced-motion отключают необязательные transitions/animations, не скрывая loading state.
- `navigator.connection.saveData` отключает autoplay, снижает HLS buffers и меняет preload на `none`; network API не используется для fingerprinting.

## Loading, errors, reports и privacy

Player различает preparing/loading/ready/playing/paused/seeking/buffering/stalled/retrying/expired/ended/fatal. Краткое buffering не становится fatal: stalled recovery показывается через 15 секунд. Subtitle failure не останавливает video. Offline, fallback unavailable и authorization failure имеют отдельные локализованные состояния.

Существующий Task 20 report flow принимает stable title/season/episode и opaque source ID, category/reason/quality и bounded browser diagnostics. URL источника, grant, credentials, cookies, tokens, raw provider response и stack trace в issue context не входят. Duplicate detection и rate limits принадлежат canonical technical-issue service; player не создаёт второй тикет-контур.

Новая playback analytics не добавлена. История/progress остаются private; source URL не логируется. Existing meaningful progress/recommendation signals используются по прежнему назначению, без события на каждую секунду и без fingerprinting.

## Cache, database, import и administration

- Public title metadata пользуется существующими versioned cache domains. HTML cache transformer заменяет expiring guest grant; authenticated/private page не shared-cache-ится.
- Shared cache не содержит signed URL, user progress, preferences, entitlement, audience decision или failed-source session. Новый playback cache/store/key не создан.
- Source/publication/health/metadata mutation сохраняет существующую targeted title/catalog/API/sitemap invalidation. User progress invalidates только owner sync и нужные recommendation signals, не global cache.
- Точные запросы поддерживаются существующими unique/index paths для episode season/order, licensed media title/season/episode/availability/health и progress user/episode/last watched. Task 07 не добавляет speculative или duplicate indexes.
- `seasonvar:import` остаётся единственной public import command. `source_media_key`/provider identity, normalized URL fallback, episode relation, health, priority/status/quality/format/variant и editorial ownership сохраняют idempotency; source replacement не переносит progress, потому что progress уже привязан к episode.
- Admin использует существующий catalog manager для publication, source status, health, format, quality, translation/variant, subtitle flag, audience и availability windows. Provider credentials не выводятся. Separate audio/subtitle/region/age fields не добавлены, потому что importer и schema не могут их достоверно заполнить.
- Source health принимает bounded observations и не отключает источник после одного client error. Player fallback не записывает необоснованный global health verdict.

## Known limitations

1. В текущих данных нет независимых audio tracks/language codes и subtitle track URLs/bodies; выбор работает на уровне реально импортированных source variants.
2. Нет реального HLS в текущем SQLite snapshot; HLS.js/native code сохраняется для разрешённых manifest sources, manual ABR level UI не заявляется.
3. Premium существует как отдельный account/billing domain, но ни один playback feature/source не привязан к нему. Region-country и user-age playback schema также отсутствуют.
4. External provider отвечает за codec, CORS, Range, manifest и segment availability. Portal может безопасно retry/fallback, но не обходит provider controls.
5. Fullscreen/PiP/background/orientation отличаются между browser/OS и проверяются feature detection, а не обещанием идентичности.
6. После verified login anonymous progress переносится best-effort через существующий settings migration endpoint: сервер повторно разрешает playable episode, сохраняет только position provenance `anonymous`, не доверяет completion hint и никогда не заменяет уже существующую account row.
7. DRM, MPEG-DASH, transcoding, offline video, generic proxy, HLS merge и protected-stream scraping не поддерживаются.

## Manual acceptance и rollback

Перед публикацией обязательно проверить:

- routes/bindings/middleware и отсутствие delivery routes в sitemap/SEO;
- hierarchy/entitlement/viewer signature/URL-DNS/path guards и no-store headers;
- один player instance, cleanup при Livewire morph/navigation/back-forward;
- regular/special/cross-season previous/next и отсутствие final loop;
- autoplay on/off/countdown/cancel/play-now и progress flush;
- variant/quality/format selection, position handoff, one-retry/fallback/refresh bounds;
- authenticated and anonymous progress cadence, stale sequence, restart, resume и completion thresholds;
- volume/mute/remember-volume/speed validation and persistence;
- RU/EN dictionary parity, отсутствие raw keys/hardcoded labels;
- keyboard/editable exclusion/focus/dialog/live regions/reduced motion;
- phone/tablet/desktop overflow, coarse touch, captions, iOS/Android feature boundaries;
- error/help/report context без URLs/secrets;
- selected-episode-only source query, existing indexes/query plans and no N+1;
- importer duplicate/health/editorial preservation and admin management;
- CSP provider origins and required upstream CORS/Range/MIME.

Rollback — revert Task 07 code/assets/translations/docs. Database rollback и data repair не нужны: migration отсутствует, IDs/rows/routes/preferences/cache keys/API fields не переименованы, а выданные grants истекут максимум через configured TTL.

Автоматизированные tests для Task 07 не создаются и не запускаются по прямому требованию. Разрешённые evidence gates: PHP/JS syntax, Pint, Blade compilation, route/schema/query/index inspection, translation parity, Vite production build, static secret/URL/DOM-sink scans и browser smoke с console/network/viewport inspection.

Фактическая приёмка 18.07.2026 прошла все эти gates. PHP syntax, Pint, focused Larastan, JS syntax, Blade compilation, Vite build, docs-refresh и whitespace checks успешны; SQLite `quick_check(1)` вернул `ok`, а query-plan inspection использовал существующие индексы порядка эпизодов, доступности источников и уникального progress. Рекурсивный RU/EN key/placeholder contract совпадает. Статический HTML содержит только короткоживущий same-origin `/playback/{id}` grant и не содержит CDN URL или `VideoObject.contentUrl`. Ручной Chromium smoke на desktop и `390×844` подтвердил один Plyr/video instance, отсутствие overflow и console errors, локализованный keyboard dialog с возвратом focus, переход Livewire с 1-й на 2-ю серию, сохранение канонической device preference и реальную выдачу CDN с `206 Partial Content`. Automated test suite не запускался.
