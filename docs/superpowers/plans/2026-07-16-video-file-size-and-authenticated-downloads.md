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

- [ ] Создать additive reversible migration для `licensed_media`.
- [ ] Добавить nullable `unsignedBigInteger file_size_bytes`; `null` = unknown, явный `0` = доверенный zero-byte resource.
- [ ] Добавить nullable `timestamp file_size_checked_at`.
- [ ] Добавить nullable enum-compatible indexed `string file_size_check_status`: `pending`, `known`, `unknown`, `unsupported`, `failed`.
- [ ] Добавить nullable bounded `string file_size_source`.
- [ ] Добавить nullable `unsignedSmallInteger file_size_http_status`.
- [ ] Добавить nullable bounded `string file_size_check_error` только для sanitized diagnostics.
- [ ] Добавить реальный due-query индекс `(file_size_check_status, file_size_checked_at, id)`, не индексировать byte count.
- [ ] Создать additive reversible migration persistent counters в `seasonvar_import_runs`: checked/known/unknown/unsupported/failed/known_bytes.
- [ ] Обновить fillable, casts, PHPDoc и deterministic helpers `LicensedMedia` без I/O/accessor queries.
- [ ] Обновить fillable/casts `SeasonvarImportRun` и atomic whitelist `SeasonvarImportRunRecorder`.

## Typed result и форматирование

- [ ] Добавить enum статуса проверки размера.
- [ ] Добавить immutable `ExternalMediaFileSizeResult` с status/bytes/source/httpStatus/checkedAt/errorCategory/safeErrorMessage/contentType/acceptRanges/resolvedUrl.
- [ ] Добавить named constructors `known`, `unknown`, `unsupported`, `failed`; запретить отрицательные bytes и zero-as-unknown.
- [ ] Не сериализовать и не логировать полный `resolvedUrl`.
- [ ] Добавить один `HumanFileSizeFormatter` с binary IEC math (1024), UI labels B/KB/MB/GB/TB и максимум двумя полезными знаками.
- [ ] Использовать тот же formatter в console, ViewModel и admin; exact bytes остаются отдельным значением.

## HTTP metadata inspection

- [ ] Реализовать `ExternalMediaFileSizeInspector` в media boundary.
- [ ] Использовать общую URL/DNS/port/credentials validation и `VerifiedExternalUrlData` pinning; запретить redirect following.
- [ ] Разрешать только HTTP(S) на допустимых стандартных портах; download сохраняет более строгий playback host allowlist.
- [ ] HEAD: `Accept-Encoding: identity`, bounded timeout/connect timeout/retries, no body buffering.
- [ ] Принимать `Content-Length` только для подходящего 2xx direct-media response, строгого non-negative integer и не-HTML/non-playlist Content-Type.
- [ ] Fallback: streamed GET с `Range: bytes=0-0` и `Accept-Encoding: identity`.
- [ ] Принимать только строгий `206 Content-Range: bytes 0-0/TOTAL`, где TOTAL numeric, в int range и не меньше returned end+1.
- [ ] При ignored Range/200 немедленно закрыть stream, ничего не читать, вернуть unknown.
- [ ] Закрывать upstream response в `finally` на каждом пути.
- [ ] Классифицировать HEAD unsupported, auth/404/429/5xx, malformed headers, timeout/DNS/security errors безопасно и без URL query.

## Media format behavior

- [ ] Создать единый resolver direct/playlist format и trusted extension из stored format -> final URL path -> trusted Content-Type.
- [ ] Direct candidates: фактически поддерживаемые `mp4`, `m4v`, `mov`, `webm`, `mkv`, `avi`.
- [ ] `m3u`, `m3u8` и HLS MIME возвращают `unsupported`; manifest length никогда не сохраняется как video size.
- [ ] Не объединять сегменты, не оценивать по сегменту, не использовать FFmpeg/transcoding.
- [ ] Unknown direct media остаётся download-eligible, если остальные проверки проходят.

## Freshness, skip и cache invalidation

- [ ] Добавить config `seasonvar.media_file_size`: enabled, timeouts, retries/sleep, known TTL, unknown/failed retry, chunk size, per-cycle limit.
- [ ] Проверять новый media, изменившийся effective URL/path, pending, due unknown/failed и explicit force.
- [ ] Пропускать unchanged recent known, not-due results, unsupported format, blocked URL и unchanged-source fast path.
- [ ] Сбрасывать size metadata при изменении `playback_url` или effective `path` во всех write-paths.
- [ ] Не делать HTTP в model accessor/cast, Livewire render, ViewModel, Blade или route binding.
- [ ] Инвалидировать только affected title detail через существующий invalidator и только при material size metadata change.

## Import integration и progress

- [ ] Добавить reusable `InspectLicensedMediaFileSize`, выполняющий decision/inspection/persist/progress/cache в одном месте.
- [ ] Встроить в `SeasonvarCatalogImporter::syncParsedMedia` после media/health save; failure не увеличивает media import failure и не rollback-ит catalog.
- [ ] Встроить в `ExternalPlaylistImporter` без дублирования HTTP/decision logic.
- [ ] Сбрасывать pending metadata в `CatalogAdministrationService` и `SeasonvarTitleMerger` при URL change; backlog завершит проверку вне unsafe mutation transaction.
- [ ] Добавить events started/known/unknown/unsupported/failed/skipped и backlog started/complete.
- [ ] Progress context: licensed_media_id, title, season/episode, format, human/exact bytes, source, HTTP, status, safe reason; без полного URL.
- [ ] Добавить русские labels console formatter и special formatting `file_size_bytes` через общий formatter.
- [ ] Atomic event-driven run counters одинаковы для sync/queued; terminal event учитывается один раз.
- [ ] Добавить counters и total bytes в final console summary, import-complete context и admin run presenter/view.

## Existing-media backfill

- [ ] Расширить единственную command `seasonvar:import` options `--refresh-media-sizes`, `--force-media-sizes`, `--media-size-limit=`.
- [ ] Валидировать несовместимые inventory/status/queued/forever/URL combinations без второго command.
- [ ] Добавить stable ID `lazyById`/chunk backlog с eager-loaded title/season/episode и config/CLI limit.
- [ ] Нормальный import автоматически проверяет new/changed media; explicit option запускает eligible existing backlog.
- [ ] Force игнорирует freshness, но не security/format/upper limit.
- [ ] Уважать stop signal и сохранять каждый уже завершённый результат.
- [ ] Документировать эксплуатационную стоимость HEAD/Range и conservative defaults.

## Download filename

- [ ] Добавить deterministic `LicensedMediaDownloadFilename`.
- [ ] Использовать canonical display title, безопасную transliteration/`Str::slug`, trusted extension и максимум длины.
- [ ] Основной pattern `{slug}-sezon-{NN}-serija-{NN}.{ext}`.
- [ ] Чистые fallbacks для only-season, only-episode, title-video и `video-{id}`.
- [ ] Удалить separators/control/null/header injection, repeated separators, leading/trailing dots/hyphens и Windows reserved basename.
- [ ] Не доверять remote query/filename; имя стабильно от UI locale.

## Authorization и enumeration protection

- [ ] Добавить `LicensedMediaPolicy::download(User, LicensedMedia)` через existing policy discovery.
- [ ] Требовать authenticated registered user, но не verified email: текущее правило verification относится к mutations/interactions, а task требует обычной регистрации.
- [ ] Повторно проверять title/season/episode/media entitlement, publication/audience/window/deletes, relationship ownership, health, location и direct format.
- [ ] Mismatched title/media возвращает not-found behavior; hidden media ID не раскрывает metadata.
- [ ] Кнопка не является security boundary; endpoint всегда authorizes заново.
- [ ] Клиент не передаёт remote URL, filename extension или upstream credentials.

## Streaming download и Range

- [ ] Добавить thin invokable `DownloadLicensedMediaController`.
- [ ] Добавить scoped named route `titles.media.download` под `auth`, `auth.session`, `account.private` и named limiter.
- [ ] Добавить `LicensedMediaDownloadEligibility`, `SingleByteRange` и `StreamLicensedMediaDownload` focused components.
- [ ] Upstream source только из authorized model; URL guard/DNS pinning перед соединением; redirects не следовать.
- [ ] Потоковый PSR-7 body читать bounded chunks (64 KiB default), не вызывать `body()`, не создавать file/temp/storage copy.
- [ ] Останавливать чтение при disconnect, освобождать upstream в `finally`, не писать per-chunk DB/events.
- [ ] Валидировать только один range: closed, open-ended и suffix; malformed/multiple/unsatisfiable -> 416, без uncontrolled full fallback.
- [ ] Для client Range требовать корректный upstream 206 + strict Content-Range; ignored range закрыть и вернуть 416.
- [ ] Вернуть корректные 200/206/416, Content-Length/Content-Range/Accept-Ranges/Content-Type/Content-Disposition.
- [ ] Не фабриковать range support, не пересылать Set-Cookie/Server/CORS/прочие upstream headers.
- [ ] Attachment header содержит safe ASCII `filename` и RFC 5987 `filename*`; `nosniff`, `private, no-store`, `noindex`.
- [ ] Если validated upstream full response size расходится с DB, корректировать metadata отдельно до stream без truncation.
- [ ] Закрыть PHP session lock перед длинным stream, если session активна.
- [ ] Перевести graceful HTTP errors; public response не содержит exception, URL или stack trace.

## Throttling

- [ ] Зарегистрировать named limiter на user ID + IP и secondary user + media ID.
- [ ] Лимитировать создание download requests, не chunks и не длительность stream.
- [ ] Использовать Laravel shared cache limiter, не process-local counter.
- [ ] Добавить config разумных attempts/minute и translated 429 response.

## Livewire/ViewModel/UI

- [ ] Добавить file-size columns в существующие playback selects/eager loads без N+1.
- [ ] Расширить `CatalogShowViewModel` deterministic fields: label, downloadable, URL, reason, filename/format metadata.
- [ ] Component готовит UI state и named route вне Blade; HTTP inspection при render отсутствует.
- [ ] Показать saved size pill рядом с format/quality; unknown — перевод, не `0 B`.
- [ ] Authenticated direct media: emerald/slate download link с локальным `x-ui.icon`, 44px target, focus ring, mobile wrap, normal navigation.
- [ ] Guest: compact login CTA без bypass token/upstream URL.
- [ ] HLS/unsupported: inactive stream-only explanation, без download link и manifest size.
- [ ] Не добавлять Volt, `@php`, inline PHP/CSS/JS, DB/service calls в Blade или duplicate player.

## Translations

- [ ] Добавить stable keys во все текущие локали: `lang/ru/catalog.php`, `lang/en/catalog.php`.
- [ ] Покрыть download, file size known/unknown, login required, stream-only/unsupported, unavailable/remote failure/rate limit/range.
- [ ] Console остаётся русским согласно project rules.
- [ ] Проверить отсутствие missing-key output и русского текста в English locale.

## API contract

- [ ] Подтвердить, что текущий API публичный/read-oriented и не должен отдавать protected download URL.
- [ ] Не менять существующий API payload без authenticated media-response responsibility.
- [ ] Обновить `docs/api.md`: on-demand download существует только в authenticated web route; raw upstream URL и download URL не раскрываются public API.

## Security controls

- [ ] Block schemes/credentials/private/reserved/link-local/CGNAT/metadata/loopback/Unix and unexpected ports via shared validation.
- [ ] DNS resolve + connection pinning на каждый independent request; no redirects исключает redirect rebinding.
- [ ] Не логировать signed query, cookie, source credentials или raw URL в import event/error/download logs.
- [ ] Проверить Content-Type, format и URL path; query parameter никогда не определяет extension.
- [ ] Authorization scoped to title/media and current user at request time; authorization result не кешируется.
- [ ] Private authenticated download не shared-cacheable.

## Performance controls

- [ ] Bounded requests/retries/timeouts/redirects/backfill count/chunks.
- [ ] Нет all-row load, N+1, remote HTTP внутри DB transaction, full body buffering, cache/session/DB video body.
- [ ] Нет duplicate inspection stage и page-render inspection.
- [ ] Нет per-byte events, per-chunk DB writes или full cache flush.
- [ ] Long stream не держит DB transaction и по возможности session lock.

## Documentation updates

- [ ] `README.md`: capability/quick operational note.
- [ ] `CHANGELOG.md`: dated 2026-07-16 entry.
- [ ] `docs/README.md`: topic map links/ownership only where needed.
- [ ] `docs/architecture.md`: import inspection + authenticated streaming boundary.
- [ ] `docs/importer.md`: fields, HEAD/Range, events, options, config, HLS failure semantics.
- [ ] `docs/performance.md`: bounded metadata/backfill and bandwidth/stream constraints.
- [ ] `docs/frontend.md` и `docs/UI_STANDARDS.md`: player size/download/guest/stream-only UI.
- [ ] `docs/DATA_RELATIONS.md`: exact columns/status semantics/run counters.
- [ ] `docs/deployment.md`: migration/config/cache/worker rollout and bandwidth.
- [ ] `docs/api.md`: public API non-exposure and web-only authenticated download.
- [ ] `docs/security.md`, `docs/authorization.md`, `docs/caching.md`: SSRF, policy, private no-store and invalidation boundary.
- [ ] `AGENTS.md`: различить forbidden automatic import storage и allowed authenticated on-demand non-persistent stream.
- [ ] `.agents/skills/seasonvar-importer/SKILL.md`: та же обязательная operational distinction.
- [ ] Не редактировать generated `project-docs` blocks вручную; при необходимости запустить docs refresh и проверить diff.

## Non-test verification checklist

- [ ] Не создавать, не менять и не запускать tests/PHPUnit/Pest/browser tests.
- [ ] `./vendor/bin/pint --dirty --format agent`.
- [ ] `npm run build`.
- [ ] `git diff --check`.
- [ ] `php artisan route:list --path=download` и проверка auth/throttle/scoped route.
- [ ] `php artisan migrate:status`.
- [ ] Безопасный migration `--pretend`/schema SQL inspection без data mutation.
- [ ] `php -l` для каждого изменённого PHP-файла.
- [ ] Поиски `@php`, `env(` вне config, `dd`, `dump`, `ray`, `TODO`, `FIXME`, `console.log`, remote `body()`, `file_get_contents`, `Storage::put` video, exposed URL.
- [ ] Проверить все locale keys, отсутствие hardcoded public UI text, inline CSS и Volt.
- [ ] Проверить отсутствие binary/video/temp artifacts и secrets.
- [ ] Полностью просмотреть `git diff --stat` и `git diff`; task paths отделены от unrelated working-tree changes.
- [ ] Подтвердить `main`, выполнить task-only commit и `git push origin main` без PR.

## Планируемый итоговый список изменённых файлов

Список обновляется по факту реализации; каждый фактически изменённый task-файл будет отмечен `[x]`, неиспользованные кандидаты удаляются из списка.

- [ ] Новые migration-файлы для `licensed_media` и `seasonvar_import_runs`.
- [ ] Новые enum/DTO/value/action/service/controller/policy классы media download/size boundary.
- [ ] `app/Models/LicensedMedia.php`, `app/Models/SeasonvarImportRun.php`.
- [ ] `app/Services/Seasonvar/SeasonvarCatalogImporter.php`, `SeasonvarImportPipeline.php`, `SeasonvarImportRunRecorder.php`, `SeasonvarTitleMerger.php`.
- [ ] `app/Services/Media/ExternalPlaylistImporter.php` и общий безопасный HTTP boundary.
- [ ] `app/Services/Catalog/CatalogAdministrationService.php`, `CatalogTitlePlaybackQuery.php`.
- [ ] `app/Console/Commands/ImportSeasonvar.php`, `Concerns/OutputsSeasonvarProgress.php`.
- [ ] `app/Services/Seasonvar/SeasonvarImportAdminService.php`, admin import Blade.
- [ ] `app/Livewire/CatalogTitlePlayer.php`, `app/View/ViewModels/CatalogShowViewModel.php`, player Blade.
- [ ] `app/Providers/AppServiceProvider.php`, `routes/web.php`, `config/seasonvar.php`, `config/playback.php`, `.env.example`.
- [ ] `lang/ru/catalog.php`, `lang/en/catalog.php`.
- [ ] Точечные README/CHANGELOG/docs/AGENTS/skill документы из раздела документации.
- [ ] Этот план с окончательно отмеченными пунктами и реальным changed-files перечнем.

