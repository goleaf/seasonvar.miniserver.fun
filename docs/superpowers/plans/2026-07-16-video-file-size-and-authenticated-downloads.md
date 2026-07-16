# Размеры видеофайлов и авторизованные скачивания — план реализации

Дата: 2026-07-16  
Ветка: только существующая `main`  
Статус: выполняется

## Цель и границы

Реализовать единый production-контур, который безопасно определяет точный размер прямого внешнего видео без полной загрузки, сохраняет байты и диагностику в `licensed_media`, показывает размер в импорте и player UI, а зарегистрированному пользователю отдаёт разрешённый прямой файл потоково с безопасным именем и single-range resume. Автоматическое скачивание и постоянное хранение видео импортёром остаётся запрещённым. HLS-манифесты не считаются полным видеофайлом, не объединяются и не предлагаются как обычная загрузка.

Прямое требование задачи запрещает создавать и запускать тесты. Поэтому проверка выполняется только статическими и безопасными нетестовыми командами, перечисленными ниже.

## Обязательный аудит текущего состояния

- [x] Выполнен `git status --short --branch`; рабочая ветка — ровно `main`.
- [x] Зафиксировано наличие посторонних пользовательских изменений; они не относятся к этой функции, не удаляются и не должны попасть в feature-коммит.
- [x] Полностью прочитаны `AGENTS.md`, `README.md`, `CHANGELOG.md`, карта владельцев `docs/README.md` и тематические документы architecture/importer/performance/frontend/UI/data/development/API/deployment.
- [x] Прочитаны проектные навыки импортёра, Laravel и UI/Tailwind; Laravel 13 API сверены с документацией Laravel Boost.
- [x] Прочитаны обязательные command/pipeline/importer/media/model/query/resolver/Livewire/ViewModel/Blade/route/config файлы.
- [x] Прочитаны все миграции, создающие или изменяющие `licensed_media`, `seasonvar_import_runs`, `seasonvar_import_events` и `users`.
- [x] Проверены auth, email verification, policies/gates, scoped binding, throttling, URL/DNS validation, media health, external HTTP, translations, cache invalidation и admin import UI.
- [x] Выполнен repository-wide поиск `playback_url`, `source_url`, `source_media_key`, `licensed_media`, import events, stream/Range/download/auth/rate-limit терминов.

## Обнаруженная архитектура

### Фактический data flow медиа

```text
Seasonvar HTML / внешний playlist
    -> SeasonvarCatalogParser / ExternalPlaylistImporter::parse
    -> нормализованный media item
    -> SeasonvarPreparedMediaResolver (playlist + availability metadata)
    -> SeasonvarCatalogImporter::applyPreparedPage
    -> catalog transaction: title / season / episode
    -> после commit: SeasonvarCatalogImporter::syncParsedMedia
    -> LicensedMedia + MediaSourceHealthManager
    -> CatalogTitlePlaybackQuery (available relations + eager-loaded media)
    -> CatalogPlaybackSourceResolver (entitlement + trusted source)
    -> CatalogTitlePlayer
    -> CatalogShowViewModel
    -> resources/views/livewire/catalog-title-player.blade.php
```

- [x] Synchronous и queued импорт используют один preparer/apply path; queued finalizer применяет durable prepared payload.
- [x] Каталожная multi-table транзакция заканчивается до `syncParsedMedia`, поэтому bounded HTTP size inspection можно выполнить после media save без открытой DB-транзакции.
- [x] Новый/изменённый Seasonvar media определяется по `catalog_title_id + source_media_key`, затем fallback по `catalog_title_id + playback_url`.
- [x] `source_media_key` включает title/release/source/URL/quality/format; сезоны и серии остаются внутри одного `CatalogTitle`.
- [x] Основные create/update пути: `SeasonvarCatalogImporter`, `ExternalPlaylistImporter`; URL также может измениться через `CatalogAdministrationService` и merge в `SeasonvarTitleMerger`.
- [x] Effective media location — `playback_url`, иначе `path`; `source_url` описывает страницу/playlist-источник, а не клиентский параметр загрузки.
- [x] Availability хранится отдельно (`check_status`, `health_status`, retry schedule); неудача размера не должна менять playable health.
- [x] `PlaybackSourceUrlGuard` и `VerifiedExternalUrlData` обеспечивают host allowlist, public DNS и cURL pinning; redirects сейчас запрещены, а значит непроверенный redirect никогда не принимается.
- [x] `ExternalPlaylistImporter` уже проверяет только HTTP(S), стандартные порты, отсутствие credentials и public IP; реализация размера должна вынести общую проверку, а не ослабить download guard.
- [x] `CatalogEntitlementService` и существующие availability scopes являются источником истины title/season/episode/media публикации, audience, window и soft-delete.
- [x] Player получает только краткоживущий внутренний signed playback URL; raw upstream URL отсутствует в Blade и public API.
- [x] Title/player cache инвалидируется через `CatalogCacheInvalidator::importedTitleChanged`, без full cache flush.
- [x] Поддерживаемые player formats: `m3u8`, `mp4`, `m4v`, `webm`, `mov`; importer дополнительно распознаёт `mkv`, `avi`, `m3u`. Download direct allowlist будет явным и отдельным от player capability.
- [x] Публичные локали: `ru` и `en`; UI-текст находится в `lang/{locale}/catalog.php`.

## Целевая архитектура

```text
import/update LicensedMedia
    -> обнаружить изменение playback_url/path
    -> сбросить только file-size metadata в pending
    -> сохранить обычные media/health данные
    -> InspectLicensedMediaFileSize (общая action)
       -> freshness/format/security/limit decision
       -> ExternalMediaFileSizeInspector
          -> validated HEAD + Accept-Encoding: identity
          -> при необходимости streamed GET Range: bytes=0-0
          -> немедленно закрыть body
       -> сохранить exact bytes/status/source/http/error/checked_at
       -> structured progress event + atomic run counters
       -> targeted title cache invalidation при изменении metadata
```

```text
GET authenticated download route
    -> auth/auth.session/account.private + named request limiter
    -> scoped title/media binding + explicit relationship check
    -> LicensedMediaPolicy::download
    -> LicensedMediaDownloadEligibility (entitlement + direct format + health)
    -> trusted source from LicensedMedia only
    -> PlaybackSourceUrlGuard validation + pinned connection
    -> safe SingleByteRange validation
    -> upstream streamed request without redirects
    -> filtered attachment headers + bounded StreamedResponse loop
    -> close upstream in finally, persist no video bytes
```

## Database changes

- [x] Создать additive reversible migration для `licensed_media`.
- [x] Добавить nullable `unsignedBigInteger file_size_bytes`; `null` = unknown, явный `0` = доверенный zero-byte resource.
- [x] Добавить nullable `timestamp file_size_checked_at`.
- [x] Добавить nullable enum-compatible indexed `string file_size_check_status`: `pending`, `known`, `unknown`, `unsupported`, `failed`.
- [x] Добавить nullable bounded `string file_size_source`.
- [x] Добавить nullable `unsignedSmallInteger file_size_http_status`.
- [x] Добавить nullable bounded `string file_size_check_error` только для sanitized diagnostics.
- [x] Добавить реальный due-query индекс `(file_size_check_status, file_size_checked_at, id)`, не индексировать byte count.
- [x] Создать additive reversible migration persistent counters в `seasonvar_import_runs`: checked/known/unknown/unsupported/failed/known_bytes.
- [x] Обновить fillable, casts, PHPDoc и deterministic helpers `LicensedMedia` без I/O/accessor queries.
- [x] Обновить fillable/casts `SeasonvarImportRun` и atomic whitelist `SeasonvarImportRunRecorder`.

## Typed result и форматирование

- [x] Добавить enum статуса проверки размера.
- [x] Добавить immutable `ExternalMediaFileSizeResult` с status/bytes/source/httpStatus/checkedAt/errorCategory/safeErrorMessage/contentType/acceptRanges/resolvedUrl.
- [x] Добавить named constructors `known`, `unknown`, `unsupported`, `failed`; запретить отрицательные bytes и zero-as-unknown.
- [x] Не сериализовать и не логировать полный `resolvedUrl`.
- [x] Добавить один `HumanFileSizeFormatter` с binary IEC math (1024), UI labels B/KB/MB/GB/TB и максимум двумя полезными знаками.
- [x] Использовать тот же formatter в console, ViewModel и admin; exact bytes остаются отдельным значением.

## HTTP metadata inspection

- [x] Реализовать `ExternalMediaFileSizeInspector` в media boundary.
- [x] Использовать общую URL/DNS/port/credentials validation и `VerifiedExternalUrlData` pinning; запретить redirect following.
- [x] Разрешать только HTTP(S) на допустимых стандартных портах; download сохраняет более строгий playback host allowlist.
- [x] HEAD: `Accept-Encoding: identity`, bounded timeout/connect timeout/retries, no body buffering.
- [x] Принимать `Content-Length` только для подходящего 2xx direct-media response, строгого non-negative integer и не-HTML/non-playlist Content-Type.
- [x] Fallback: streamed GET с `Range: bytes=0-0` и `Accept-Encoding: identity`.
- [x] Принимать только строгий `206 Content-Range: bytes 0-0/TOTAL`, где TOTAL numeric, в int range и не меньше returned end+1.
- [x] При ignored Range/200 немедленно закрыть stream, ничего не читать, вернуть unknown.
- [x] Закрывать upstream response в `finally` на каждом пути.
- [x] Классифицировать HEAD unsupported, auth/404/429/5xx, malformed headers, timeout/DNS/security errors безопасно и без URL query.

## Media format behavior

- [x] Создать единый resolver direct/playlist format и trusted extension из stored format -> final URL path -> trusted Content-Type.
- [x] Direct candidates: фактически поддерживаемые `mp4`, `m4v`, `mov`, `webm`, `mkv`, `avi`.
- [x] `m3u`, `m3u8` и HLS MIME возвращают `unsupported`; manifest length никогда не сохраняется как video size.
- [x] Не объединять сегменты, не оценивать по сегменту, не использовать FFmpeg/transcoding.
- [x] Unknown direct media остаётся download-eligible, если остальные проверки проходят.

## Freshness, skip и cache invalidation

- [x] Добавить config `seasonvar.media_file_size`: enabled, timeouts, retries/sleep, known TTL, unknown/failed retry, chunk size, per-cycle limit.
- [x] Проверять новый media, изменившийся effective URL/path, pending, due unknown/failed и explicit force.
- [x] Пропускать unchanged recent known, not-due results, unsupported format, blocked URL и unchanged-source fast path.
- [x] Сбрасывать size metadata при изменении `playback_url` или effective `path` во всех write-paths.
- [x] Не делать HTTP в model accessor/cast, Livewire render, ViewModel, Blade или route binding.
- [x] Инвалидировать только affected title detail через существующий invalidator и только при material size metadata change.

## Import integration и progress

- [x] Добавить reusable `InspectLicensedMediaFileSize`, выполняющий decision/inspection/persist/progress/cache в одном месте.
- [x] Встроить в `SeasonvarCatalogImporter::syncParsedMedia` после media/health save; failure не увеличивает media import failure и не rollback-ит catalog.
- [x] Встроить в `ExternalPlaylistImporter` без дублирования HTTP/decision logic.
- [x] Сбрасывать pending metadata в `CatalogAdministrationService` и `SeasonvarTitleMerger` при URL change; backlog завершит проверку вне unsafe mutation transaction.
- [x] Добавить events started/known/unknown/unsupported/failed/skipped и backlog started/complete.
- [x] Progress context: licensed_media_id, title, season/episode, format, human/exact bytes, source, HTTP, status, safe reason; без полного URL.
- [x] Добавить русские labels console formatter и special formatting `file_size_bytes` через общий formatter.
- [x] Atomic event-driven run counters одинаковы для sync/queued; terminal event учитывается один раз.
- [x] Добавить counters и total bytes в final console summary, import-complete context и admin run presenter/view.

## Existing-media backfill

- [x] Расширить единственную command `seasonvar:import` options `--refresh-media-sizes`, `--force-media-sizes`, `--media-size-limit=`.
- [x] Валидировать несовместимые inventory/status/queued/forever/URL combinations без второго command.
- [x] Добавить stable ID `lazyById`/chunk backlog с eager-loaded title/season/episode и config/CLI limit.
- [x] Нормальный import автоматически проверяет new/changed media; explicit option запускает eligible existing backlog.
- [x] Force игнорирует freshness, но не security/format/upper limit.
- [x] Уважать stop signal и сохранять каждый уже завершённый результат.
- [x] Документировать эксплуатационную стоимость HEAD/Range и conservative defaults.

## Download filename

- [x] Добавить deterministic `LicensedMediaDownloadFilename`.
- [x] Использовать canonical display title, безопасную transliteration/`Str::slug`, trusted extension и максимум длины.
- [x] Основной pattern `{slug}-sezon-{NN}-serija-{NN}.{ext}`.
- [x] Чистые fallbacks для only-season, only-episode, title-video и `video-{id}`.
- [x] Удалить separators/control/null/header injection, repeated separators, leading/trailing dots/hyphens и Windows reserved basename.
- [x] Не доверять remote query/filename; имя стабильно от UI locale.

## Authorization и enumeration protection

- [x] Добавить `LicensedMediaPolicy::download(User, LicensedMedia)` через existing policy discovery.
- [x] Требовать authenticated registered user, но не verified email: текущее правило verification относится к mutations/interactions, а task требует обычной регистрации.
- [x] Повторно проверять title/season/episode/media entitlement, publication/audience/window/deletes, relationship ownership, health, location и direct format.
- [x] Mismatched title/media возвращает not-found behavior; hidden media ID не раскрывает metadata.
- [x] Кнопка не является security boundary; endpoint всегда authorizes заново.
- [x] Клиент не передаёт remote URL, filename extension или upstream credentials.

## Streaming download и Range

- [x] Добавить thin invokable `DownloadLicensedMediaController`.
- [x] Добавить scoped named route `titles.media.download` под `auth`, `auth.session`, `account.private` и named limiter.
- [x] Добавить `LicensedMediaDownloadEligibility`, `SingleByteRange` и `StreamLicensedMediaDownload` focused components.
- [x] Upstream source только из authorized model; URL guard/DNS pinning перед соединением; redirects не следовать.
- [x] Потоковый PSR-7 body читать bounded chunks (64 KiB default), не вызывать `body()`, не создавать file/temp/storage copy.
- [x] Останавливать чтение при disconnect, освобождать upstream в `finally`, не писать per-chunk DB/events.
- [x] Валидировать только один range: closed, open-ended и suffix; malformed/multiple/unsatisfiable -> 416, без uncontrolled full fallback.
- [x] Для client Range требовать корректный upstream 206 + strict Content-Range; ignored range закрыть и вернуть 416.
- [x] Вернуть корректные 200/206/416, Content-Length/Content-Range/Accept-Ranges/Content-Type/Content-Disposition.
- [x] Не фабриковать range support, не пересылать Set-Cookie/Server/CORS/прочие upstream headers.
- [x] Attachment header содержит safe ASCII `filename` и RFC 5987 `filename*`; `nosniff`, `private, no-store`, `noindex`.
- [x] Если validated upstream full response size расходится с DB, корректировать metadata отдельно до stream без truncation.
- [x] Закрыть PHP session lock перед длинным stream, если session активна.
- [x] Перевести graceful HTTP errors; public response не содержит exception, URL или stack trace.

## Throttling

- [x] Зарегистрировать named limiter на user ID + IP и secondary user + media ID.
- [x] Лимитировать создание download requests, не chunks и не длительность stream.
- [x] Использовать Laravel shared cache limiter, не process-local counter.
- [x] Добавить config разумных attempts/minute и translated 429 response.

## Livewire/ViewModel/UI

- [x] Добавить file-size columns в существующие playback selects/eager loads без N+1.
- [x] Расширить `CatalogShowViewModel` deterministic fields: label, downloadable, URL, reason, filename/format metadata.
- [x] Component готовит UI state и named route вне Blade; HTTP inspection при render отсутствует.
- [x] Показать saved size pill рядом с format/quality; unknown — перевод, не `0 B`.
- [x] Authenticated direct media: emerald/slate download link с локальным `x-ui.icon`, 44px target, focus ring, mobile wrap, normal navigation.
- [x] Guest: compact login CTA без bypass token/upstream URL.
- [x] HLS/unsupported: inactive stream-only explanation, без download link и manifest size.
- [x] Не добавлять Volt, `@php`, inline PHP/CSS/JS, DB/service calls в Blade или duplicate player.

## Translations

- [x] Добавить stable keys во все текущие локали: `lang/ru/catalog.php`, `lang/en/catalog.php`.
- [x] Покрыть download, file size known/unknown, login required, stream-only/unsupported, unavailable/remote failure/rate limit/range.
- [x] Console остаётся русским согласно project rules.
- [x] Проверить отсутствие missing-key output и русского текста в English locale.

## API contract

- [x] Подтвердить, что текущий API публичный/read-oriented и не должен отдавать protected download URL.
- [x] Не менять существующий API payload без authenticated media-response responsibility.
- [x] Обновить `docs/api.md`: on-demand download существует только в authenticated web route; raw upstream URL и download URL не раскрываются public API.

## Security controls

- [x] Block schemes/credentials/private/reserved/link-local/CGNAT/metadata/loopback/Unix and unexpected ports via shared validation.
- [x] DNS resolve + connection pinning на каждый independent request; no redirects исключает redirect rebinding.
- [x] Не логировать signed query, cookie, source credentials или raw URL в import event/error/download logs.
- [x] Проверить Content-Type, format и URL path; query parameter никогда не определяет extension.
- [x] Authorization scoped to title/media and current user at request time; authorization result не кешируется.
- [x] Private authenticated download не shared-cacheable.

## Performance controls

- [x] Bounded requests/retries/timeouts/redirects/backfill count/chunks.
- [x] Нет all-row load, N+1, remote HTTP внутри DB transaction, full body buffering, cache/session/DB video body.
- [x] Нет duplicate inspection stage и page-render inspection.
- [x] Нет per-byte events, per-chunk DB writes или full cache flush.
- [x] Long stream не держит DB transaction и по возможности session lock.

## Documentation updates

- [x] `README.md`: capability/quick operational note.
- [x] `CHANGELOG.md`: dated 2026-07-16 entry.
- [x] `docs/README.md`: topic map links/ownership only where needed.
- [x] `docs/architecture.md`: import inspection + authenticated streaming boundary.
- [x] `docs/importer.md`: fields, HEAD/Range, events, options, config, HLS failure semantics.
- [x] `docs/performance.md`: bounded metadata/backfill and bandwidth/stream constraints.
- [x] `docs/frontend.md` и `docs/UI_STANDARDS.md`: player size/download/guest/stream-only UI.
- [x] `docs/DATA_RELATIONS.md`: exact columns/status semantics/run counters.
- [x] `docs/deployment.md`: migration/config/cache/worker rollout and bandwidth.
- [x] `docs/environment.md`: production variables, public-DNS requirement and config-cache reload.
- [x] `docs/api.md`: public API non-exposure and web-only authenticated download.
- [x] `docs/security.md`, `docs/authorization.md`, `docs/caching.md`: SSRF, policy, private no-store and invalidation boundary.
- [x] `AGENTS.md`: различить forbidden automatic import storage и allowed authenticated on-demand non-persistent stream.
- [x] `.agents/skills/seasonvar-importer/SKILL.md`: та же обязательная operational distinction.
- [x] Не редактировать generated `project-docs` blocks вручную; при необходимости запустить docs refresh и проверить diff.

## Non-test verification checklist

- [x] Не создавать, не менять и не запускать tests/PHPUnit/Pest/browser tests.
- [x] `./vendor/bin/pint --dirty --format agent`.
- [x] `npm run build`.
- [x] `git diff --check`.
- [x] `php artisan route:list --path=download` и проверка auth/throttle/scoped route.
- [x] `php artisan migrate:status`.
- [x] Безопасный migration `--pretend`/schema SQL inspection без data mutation.
- [x] `php -l` для каждого изменённого PHP-файла.
- [x] Поиски `@php`, `env(` вне config, `dd`, `dump`, `ray`, `TODO`, `FIXME`, `console.log`, remote `body()`, `file_get_contents`, `Storage::put` video, exposed URL.
- [x] Проверить все locale keys, отсутствие hardcoded public UI text, inline CSS и Volt.
- [x] Проверить отсутствие binary/video/temp artifacts и secrets.
- [x] Полностью просмотреть `git diff --stat` и `git diff`; task paths отделены от unrelated working-tree changes.
- [ ] Подтвердить `main`, выполнить task-only commit и `git push origin main` без PR.

## Итоговый список task-файлов

Ниже перечислены только файлы этой функции; посторонние изменения параллельных задач в общем рабочем дереве в feature-индекс не включаются.

- [x] Schema/model: `database/migrations/2026_07_16_190000_add_file_size_metadata_to_licensed_media.php`, `database/migrations/2026_07_16_190100_add_media_file_size_counters_to_seasonvar_import_runs.php`, `app/Models/LicensedMedia.php`, `app/Models/SeasonvarImportRun.php`, `app/Enums/MediaFileSizeCheckStatus.php`.
- [x] Typed data/formatting: `app/DTOs/ExternalMediaFileSizeResultData.php`, `app/DTOs/LicensedMediaDownloadData.php`, `app/DTOs/SingleByteRangeData.php`, `app/Support/HumanFileSizeFormatter.php`.
- [x] Size inspection/import: `app/Actions/Media/InspectLicensedMediaFileSize.php`, `app/Services/Media/ExternalMediaFileSizeInspector.php`, `app/Services/Media/ExternalMediaFileType.php`, `app/Services/Media/ExternalMediaUrlGuard.php`, `app/Services/Media/ExternalPlaylistImporter.php`, `app/Services/Crawler/PoliteHttpClient.php`.
- [x] Seasonvar pipeline/progress: `app/Console/Commands/ImportSeasonvar.php`, `app/Console/Commands/Concerns/OutputsSeasonvarProgress.php`, `app/Services/Seasonvar/SeasonvarCatalogImporter.php`, `app/Services/Seasonvar/SeasonvarImportPipeline.php`, `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`, `app/Services/Seasonvar/SeasonvarImportAdminService.php`, `app/Services/Seasonvar/SeasonvarTitleMerger.php`, `app/Services/Catalog/CatalogAdministrationService.php`.
- [x] Download boundary: `app/Http/Controllers/DownloadLicensedMediaController.php`, `app/Policies/LicensedMediaPolicy.php`, `app/Services/Media/LicensedMediaDownloadEligibility.php`, `app/Services/Media/LicensedMediaDownloadFilename.php`, `app/Services/Media/PlaybackSourceUrlGuard.php`, `app/Services/Media/SingleByteRange.php`, `app/Services/Media/StreamLicensedMediaDownload.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`.
- [x] Player/admin presentation: `app/Services/Catalog/CatalogTitlePlaybackQuery.php`, `app/Livewire/CatalogTitlePlayer.php`, `app/View/ViewModels/CatalogShowViewModel.php`, `resources/views/livewire/catalog-title-player.blade.php`, `resources/views/livewire/seasonvar-import-manager.blade.php`, `lang/ru/catalog.php`, `lang/en/catalog.php`.
- [x] Configuration: `config/seasonvar.php`, `config/playback.php`, `.env.example`.
- [x] Agent/project docs: `AGENTS.md`, `.agents/skills/seasonvar-importer/SKILL.md`, `README.md`, `CHANGELOG.md`, `docs/README.md`, `docs/architecture.md`, `docs/importer.md`, `docs/performance.md`, `docs/frontend.md`, `docs/UI_STANDARDS.md`, `docs/DATA_RELATIONS.md`, `docs/deployment.md`, `docs/environment.md`, `docs/api.md`, `docs/authorization.md`, `docs/caching.md`, `docs/security.md` и этот plan-файл.
