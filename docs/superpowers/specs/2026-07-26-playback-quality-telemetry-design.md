# Диагностика качества просмотра — design

Дата: 26.07.2026.

Статус: approved under the user's explicit autonomous implementation
authorization.

## Цель

Добавить к существующему `CatalogTitlePlayer` UX безопасного fallback,
обезличенную first-party телеметрию качества просмотра и агрегированную
staff-only статистику, не создавая второй проигрыватель, второй source-health
resolver или client-trusted health verdict.

Пользователь должен:

- видеть последовательные сообщения «Источник 1 не ответил» и
  «Переключаю на источник 2»;
- открыть действие «Видео не работает»;
- передать в техническое обращение точный server-resolved title/season/
  episode/media context, variant, quality, translation, browser/OS family,
  HLS capability, stable error category, startup time, buffering count,
  primary/fallback failure state, random request ID и bounded same-origin
  network test;
- видеть до отправки обращения, какие диагностические данные будут
  приложены, и иметь возможность снять согласие.

Support должен видеть за bounded период:

- average startup time;
- aggregate rebuffer ratio;
- terminal playback error rate;
- fallback attempt/success rate;
- error groups по browser family/major, provider code и quality code.

## Проверенная текущая архитектура

Один существующий контур остаётся владельцем playback:

`CatalogTitlePlayer → CatalogTitlePlaybackQuery → CatalogPlaybackSourceResolver
→ signed same-origin source → player.js (native video/Plyr/HLS.js)`.

`player.js` уже:

- обрабатывает native/HLS errors;
- выполняет bounded network/media retry;
- инициирует `catalog-source-fallback`;
- сохраняет progress и transient resume;
- уничтожает listeners/timers/HLS/Plyr на navigation/morph.

`CatalogTitlePlayer::selectFallbackMedia()` уже:

- проверяет title/episode/media hierarchy;
- ограничивает действие `12/min`;
- сохраняет не более 100 failed media IDs;
- выбирает fallback через canonical source resolver;
- не изменяет source health автоматически.

`TechnicalIssueContext`, `TechnicalIssueFormPage`,
`CreateTechnicalIssue`, `TechnicalIssueDiagnostic` и staff queue уже
реализуют encrypted target context, explicit diagnostics consent, private
storage, policy/gate, rate limits и safe presentation.

## Рассмотренные варианты

### 1. Переписать player и source resolver

Отклонено. Existing recovery/source-health boundaries уже корректны, а новый
player создаст duplicate lifecycle, source selection и authorization risk.

### 2. Считать статистику только по тикетам

Отклонено. Без denominator успешных playback sessions нельзя корректно
посчитать playback error rate, rebuffer ratio или fallback success.

### 3. Отправлять third-party analytics

Отклонено. Это добавит dependency/provider/consent/secrets/cross-border
boundary и нарушит текущий запрет внешней telemetry.

### 4. First-party bounded playback session telemetry

Выбрано. Browser отправляет cumulative bounded metrics на same-origin
CSRF-protected endpoint. Server повторно разрешает title/media hierarchy и
сам создаёт provider/quality/variant/translation snapshots. Raw media URL,
IP, raw User-Agent, cookies, session ID, playback grants, user ID и private
history не сохраняются.

## Архитектура решения

### Additive data model

Новая `playback_quality_sessions` хранит одну анонимную playback session:

- random UUID `request_id`;
- nullable canonical FKs title/season/episode/initial media/current media;
- server-derived provider/variant/quality/translation/format snapshots;
- allowlisted browser family/major, OS family и HLS capability;
- cumulative startup/playback/buffering timings и buffering count;
- primary/fallback failure flags, fallback attempt/success и terminal error;
- stable allowlisted error category/stage;
- explicit report-click network-test status/latency;
- server timestamps and bounded retention.

Таблица намеренно не содержит `user_id`, session/cookie/token, IP, raw UA,
source URL, signed URL, storage path или arbitrary error text.

`technical_issue_diagnostics` получает additive nullable playback snapshot
columns. Тикет не зависит от retention telemetry row и сохраняет ровно тот
диагностический snapshot, на который пользователь согласился.

### Capability context

`PlaybackQualityContext` выдаёт encrypted expiring capture token только с
canonical title ID. Token не является playback grant и не раскрывает source.
Каждый POST повторно проверяет current media/season/episode через existing
availability scopes.

Report event получает отдельный encrypted report token, привязанный к
`request_id` и server row. Form/action принимают snapshot только если:

- report token действителен и не истёк;
- telemetry row существует;
- title/season/episode/media совпадают с заново разрешённым issue target;
- diagnostics consent остаётся включённым на submit.

### HTTP boundary

Два thin responder route:

- `POST /playback/quality` — CSRF, named dual rate limiter, Form Request,
  `private, no-store`, JSON `202`;
- `GET /playback/network-test` — fixed same-origin, no input, small
  `204`, no-store/noindex response, separate bounded limiter.

Telemetry event payload содержит только cumulative allowlisted scalars.
Unknown fields удаляются `$request->validated()`; arbitrary URL/provider/
quality/translation/client identity не принимаются.

### Client lifecycle

`player.js` расширяет существующий `CatalogPlayerSession`, а не создаёт
второй runtime:

- генерирует random UUID через `crypto.randomUUID()` с safe fallback;
- измеряет monotonic `performance.now()` startup/play/buffer durations;
- считает rebuffer only after playback has started;
- классифицирует native/HLS/offline/stalled/fallback errors в stable codes;
- отправляет cumulative snapshot на first ready, error/fallback и final
  lifecycle with best-effort `keepalive`;
- переносит request ID и source ordinal только через существующий bounded
  transient fallback state;
- начинает новый anonymous request ID при in-place episode transition;
- не retry-ит telemetry и никогда не блокирует playback на telemetry failure.

Existing report link становится button-like anchor с normal `href` fallback.
При явном click:

1. action блокируется от double submit и получает localized loading state;
2. выполняется один timed fixed same-origin network test с timeout;
3. отправляется report snapshot;
4. server возвращает fresh issue URL для фактических current episode/media и
   encrypted report token;
5. browser выполняет обычную navigation;
6. при telemetry failure открывается прежний safe issue URL.

Network test не выполняется фоново и не принимает произвольный URL.

### Fallback UX

При fatal source failure существующий status live region сначала показывает
`sourceFailed` («Источник 1 не ответил»). Existing navigation bridge сразу
объявляет `switchingFallback` («Переключаю на источник 2») перед canonical
Livewire fallback action. Failure сохраняет current safe
`fallbackUnavailable`; success сохраняет `sourceChanged`.

Текст не является health verdict. Один client error не меняет
`LicensedMedia.health_status`.

### Administration

`PlaybackQualityMetricsQuery` принимает только allowlisted `1|7|30` day
window и выполняет:

- один aggregate overview query;
- три bounded grouped error queries с limit 10;
- server-side percentages, без PHP collection полного result set.

`TechnicalIssueAdministrationManager` повторно использует existing
`manage-technical-issues`/`support.tickets` boundary. UI показывает textual
summary cards и semantic tables/lists; chart dependency не добавляется.
Raw rows, request IDs, title/media IDs, user/session identity и source URL в
dashboard не выводятся.

## Security и privacy

- CSRF обязателен; endpoint не является public API contract.
- Capture/report tokens encrypted, expiring и length-bounded.
- Rate-limit dimensions используют token hash и one-way IP hash только в
  ephemeral Laravel limiter; IP не сохраняется в database/log payload.
- Target/media/provider/variant/quality/translation resolve server-side.
- Browser/OS/HLS/error codes — allowlists; timings/counts hard bounded.
- No raw SQL interpolation; aggregates используют Query Builder bindings.
- Report action не доверяет client `terminal_error` как source-health truth.
- Blade output escaped; JS пишет visible text через `textContent`.
- Private admin/ticket responses остаются no-store/noindex.
- Retention command bounded удаляет telemetry sessions старше configured
  window.

## Performance и indexes

Telemetry writes используют unique `request_id` и monotonic cumulative
update; repeated/out-of-order snapshots не уменьшают counters.

Indexes соответствуют фактическим reads:

- `(started_at, id)` — bounded period/retention;
- `(playback_failed, started_at, browser_family, browser_major)` — browser
  error distribution;
- `(playback_failed, started_at, error_provider_code)` — provider errors;
- `(playback_failed, started_at, quality_code)` — quality errors.

Новый cache не добавляется: staff aggregates private/current и date-bounded.
No queue/scheduler dependency. Retention расширяет existing bounded operator
command.

## Rolling deployment и rollback

Порядок:

1. backup assessment;
2. additive migration;
3. application/Vite assets;
4. config/route/view cache rebuild;
5. graceful PHP-FPM/worker reload;
6. focused route/admin/player smoke.

`PlaybackQualitySchema` fail-open для основного playback и fail-closed для
telemetry/admin metrics. Если migration ещё не выполнена, player продолжает
работать, report открывает обычную technical issue form, а dashboard
показывает unavailable state.

Rollback:

- сначала откат application/assets;
- telemetry routes исчезают, playback fallback остаётся прежним;
- additive table/columns можно сохранить для forward recovery;
- `migrate:rollback` допустим только после export assessment diagnostics,
  потому что down удаляет telemetry data;
- cache flush/database restore не нужны для code rollback.

## Compatibility contracts

Не меняются:

- public title/source/download/API route names и response shapes;
- source resolver/entitlement/signed grant;
- progress/history/session/local-storage identity;
- source health manager/admin actions;
- technical issue UUID/number/routes/policies/duplicate workflow;
- public cache keys/TTL/invalidation;
- importer command and data hierarchy;
- Premium/region/legal/access checks.
