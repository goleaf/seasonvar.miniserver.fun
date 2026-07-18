# Валидация запросов

Обновлено: 16.07.2026

## Form Request

- Публичные фильтры каталога проверяет `App\Http\Requests\CatalogTitlesRequest`.
- Страница карточки проверяет `season`, выбранную серию, video ID и media profile через `App\Http\Requests\CatalogShowRequest`: variant обязан быть slug-key, quality/format входят в allowlists `config/playback.php`. Livewire повторяет эти allowlists, нормализует положительные ID и не доверяет URL/browser identifiers.
- Контроллеры принимают типизированные Form Request-классы и не вызывают inline `$request->validate()`; full-page `CatalogSeries` переиспользует тот же `CatalogTitlesRequest::validateResolved()` для URL и Livewire update state.
- Для read-only фильтров каталога синтаксис query-параметров валидируется строго. Пустые значения и дубли удаляются, неподдерживаемые значения отклоняются правилами, а существующие slug резолвятся в уникальные положительные ID с лимитом 20 значений на тип перед построением SQL.
- `publication_type[]` проверяется через `CatalogPublicationType`, `subtitles[]` принимает только `available|missing`, `quality[]` — только явно перечисленные разрешения. Фиксированные группы имеют собственные меньшие лимиты; отсутствующие записи справочников игнорируются без ошибки.
- Невалидный год поиска не редиректит посетителя: он сохраняется как `requestedYear`, помечается `invalidYear` и дает пустую выдачу с понятным сообщением.
- Ошибки формы поиска каталога показываются из Livewire error bag без redirect; GET fallback и остальные обычные формы сохраняют стандартный `old()` внутри `x-form.search-field`.
- Поисковый query-параметр `q` перед валидацией приводится к NFKC, обрезается по краям, а последовательности Unicode-пробелов схлопываются. Непустая нормализованная строка должна содержать от 1 до 80 Unicode-символов (`min:1|max:80`) и никогда не обрезается молча. Односимвольный запрос обслуживает точный короткий поиск, а нескалярный `q` становится пустым безопасным значением.

## Правила

- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`.
- Поддерживаемые варианты сортировки перечислены в `App\Enums\CatalogSort`; invalid/non-scalar `sort` возвращается к `updated`, отдельный `direction` игнорируется, а raw query-значение никогда не передается в `orderBy()` как имя столбца.
- Livewire 4 нормализует malformed `page` к 1; положительная страница за последней доступной границей восстанавливается к `lastPage()` после count query.
- Slug-значения справочников и контекста карточки проверяет reusable rule `App\Rules\CatalogFilterSlug`.
- Сообщения Form Request для публичных страниц должны быть на русском языке.
- Для `q.min` используется сообщение `Введите хотя бы один символ названия.`, для `q.max` — `Поисковый запрос не должен быть длиннее 80 символов.`.
- Blade-шаблоны только показывают ошибки через стандартные Blade-директивы, если появятся формы; вычисления и нормализация остаются в request/view-model слоях.

## Валидация коллекций

- Livewire create/editor валидируют name 2–160, optional description до 10 000, enum visibility/sort, supported editorial locale и SEO limits; service повторяет normalization/limits через `UserPlainText`. Owner, moderation, feature и system type не принимаются из request.
- API index/show используют отдельные Form Requests: `q` до 100, allowlisted directory sort/per-page, `page` 1–10 000 и item `per_page` 6–48. Resolver ограничивает slug длиной 180 и нормализует case; invalid UUID/slug/item/title маскируются безопасным 404.
- Membership batch принимает не более 100 valid UUID, затем сравнивает их с locked owner-scoped collection set; duplicate UUID схлопывается. Reorder принимает unique positive IDs до 500 и повторно проверяет каждую принадлежность collection; direction только `-1|1`.
- Cover применяет `PrivateImageUploadRules`; report reason/status — enums, details до 2 000 plain-text characters. Все пользовательские errors берутся из parity catalogs `lang/{ru,en}/collections.php`; SQL/class/path/internal IDs не включаются.

## Валидация обсуждений

- `CommentBody` является одной server-side boundary create/reply/edit: NFKC, line ending normalization, strip tags/script/style/control/bidi, trim outer/line whitespace, preservation максимум двойного пустого абзаца. Empty result отклоняется; Unicode/non-Latin scripts сохраняются. Edit повторно выполняет duplicate/link anti-spam checks и не сохраняет `published`, если bounded new-account-plus-link signal требует review.
- Limits из `config/comments.php`: body 5 000 characters, 40 lines, 2 URL-like tokens, 5 `@` tokens и 30 одинаковых последовательных characters. `javascript:`, `vbscript:` и executable HTML/JavaScript/SVG/XHTML `data:` MIME отклоняются; обычное слово `data:` допустимо, а links остаются escaped non-clickable plain text. Report/private note имеют отдельный limit 2 000 и тот же plain-text sanitizer.
- `CommentTargetType`, `CommentSort`, `CommentReactionType`, report/status/reason/restriction enums — единственные допустимые internal codes. Target ID положительный и повторно разрешается allowlisted resolver; выбранный reply target обязан быть published/non-deleted на той же цели, а его structural root — published и live либо author-deleted tombstone. UUID submission token, expected edit version и selected comment ID перепроверяются server-side.
- Errors берутся только из exact-parity `lang/{ru,en}/comments.php`, не включают SQL/class/table/internal anti-spam detail. Каждое abuse-prone action проверяет exact target-scope и более мягкий user-global bucket; rate-limit message получает bounded retry seconds от исчерпанного server-owned key. Expired restriction считается inactive синхронно. Client-side `maxlength`/disabled controls — только UX, не security boundary.

## Валидация отзывов

- `ReviewTitle` и `ReviewBody` — единая create/edit boundary. Before normalization they reject arrays/arbitrary non-stringable objects and enforce bounded raw UTF-8 byte ceilings, so hostile markup/whitespace cannot force unbounded sanitizer work or become the literal `Array`. Title required, trimmed Unicode 5–120 characters and non-generic; body required, 100–12 000 characters, maximum 80 lines, 2 URL-like tokens and 30 repeated consecutive characters. NFKC and line-ending normalization preserve non-Latin script/meaningful paragraphs; invalid UTF-8, control/bidi, empty sanitized value and `javascript:|vbscript:|data:` are rejected.
- Optional rating accepts only canonical integer 1–10 and writes the current actor/title state; empty is `null`, never 0. Spoiler is server-cast boolean. Target type is fixed `title`, target/review/version positive IDs are reloaded, UUID submission tokens are validated, and author/status/verified/deletion fields are never accepted from form data.
- Sort/filter codes come only from `ReviewSort`, rating filter 1–10, spoiler/verified tri-state from bounded values, page positive and normalized. Vote, report category/status, moderation reason/status, restriction type/reason and notification type are enums; no raw SQL column or translated label reaches a query.
- Report details pass `UserPlainText` with a 1 000-character bound; private moderator/restriction notes use 2 000. Moderator author search removes control/wildcard input and target accepts a normalized slug or positive ID. Errors use exact-parity `lang/{ru,en}/reviews.php`, never reveal anti-spam threshold, SQL/class/table/private evidence, and include bounded retry/restoration values. Legacy read-only API pagination keeps the existing integer/min/max rules and resolves their messages from the same locale catalog instead of hard-coded Russian text.
- Client `maxlength`, character counter, disabled/loading and browser draft are UX only. Server action revalidates policy/restriction/block/target/rating/version/idempotency inside the transaction; draft is cleared only after confirmed success.

## Валидация тегов

- `TagNormalizationService` — единая label/alias/provider comparison boundary: NFC display, NFKC comparison when Intl is available, entity decode before tag stripping, NBSP/whitespace squish, control/format removal, dash/separator normalization, optional leading `#`, Unicode case-fold. Display preserves original safe case/script/diacritics; normalized hash используется только для identity/search/duplicates.
- Label length 2–80, required meaningful letter/number, all scripts allowed, pure emoji/markup/control/invisible input rejected. Personal description — escaped plain text максимум 1 000; system/editorial descriptions/SEO additionally bounded in `TagService`. Translation/alias locale только configured `ru|en`; unsupported non-null locale rejected. Personal `content_locale` optional: web create сохраняет `null`, API меняет его только при явно присутствующем nullable allowlisted field, user content never auto-translated.
- Stable global code matches lowercase language-independent allowlist and existing code immutable. Slug contains only lowercase ASCII letters/digits/hyphens, max 180, checked across current/history/alias slugs; service handles collision without trusting request column. Type/visibility/moderation/source/alias-source/synonym-relation/provider-status values come only from enums.
- Exact duplicate checks use global normalized hash for global tags and `(owner, hash)` for personal tags, include soft-deleted owner rows and approved aliases where relevant. Fuzzy similarity/transliteration/translated equivalence is never automatic merge. Alias cannot equal canonical/conflict with another canonical/alias across any locale or create an ambiguous target; resolver also fail-closed handles incompatible legacy ambiguity. Self synonym invalid, merge source/target must be distinct eligible exact rows.
- Assignment request requires present array, max configured 50, distinct UUIDs; service additionally checks exact UUID syntax, lowercases canonical form, resolves every row within current owner, reauthorizes title and rejects partial/unauthorized set. Global assignments accept only globally assignable tag/title through policies. Sort/filter/route slug use existing catalog allowlists.
- Errors use exact-parity `lang/{ru,en}/tags.php`, escaped placeholders and safe generic authorization/failure text; no SQL/table/class/internal ID/provider credential/moderation note. Client maxlength/disabled/debounce are UX only; service validation, policies, DB uniqueness/transactions and rate limits remain authoritative.

## Валидация профилей пользователей

- `ProfileUsername` and the owner Livewire rules enforce lowercase route-safe ASCII, length, separator and reserved-code rules; service repeats ownership/current-password/rate/current+history uniqueness under transaction.
- Display name retains existing account validation. Biography is optional bounded Unicode plain text; server normalizes newlines and removes HTML, controls and bidi overrides. Visibility and moderation/report values use explicit enums/allowlists rather than client keys.
- Avatar/cover require image + exact JPEG/PNG/WebP MIME/extensions, separate size/dimension limits and private storage. SVG, arbitrary disk/path/kind/version and executable masquerades are rejected before persistence.
- Report category is an enum and detail is optional bounded plain text. Normal users cannot submit reporter/target/status/moderator/private note fields.

## Валидация настроек аккаунта

- Locale принимается только из существующего supported registry; timezone — только `DateTimeZone::listIdentifiers()` плюс `UTC`. Interface locale не преобразуется в audio/subtitle language и не принимает translated labels.
- Playback booleans server-cast, volume — integer `0..100`, speed — exact config allowlist, quality/variant — только stable codes из bounded реально eligible media options. URL/source/HTML/CSS class, translated studio label и произвольный nested preference key не принимаются.
- Collection default — `CatalogCollectionVisibility` enum; изменение влияет только на будущие create. Notification form имеет фиксированные booleans actual comment/review categories и вызывает canonical actions, поэтому client не может добавить category/channel.
- Anonymous migration Form Request запрещает unknown nested arrays, revalidates все поля и заполняет только nullable explicit choices. Service независимо повторяет allowlists/ranges/regex, Gate и transaction lock. Invalid legacy/device values fallback-ятся без role/premium/verification/profile mutation.
- Profile display name продолжает `UserPlainText` normalization и запрещает control/bidi; generic profile fill allowlisted только `name|email`. Session action token должен быть 64-char HMAC и повторно разрешается внутри own session set; password/export/delete rules остаются в существующих security boundaries.

- Technical issue input сначала преобразуется в typed DTO, затем registry проверяет eligible target и type-specific actual/steps/timestamp/language fields. Context resolver повторно проверяет catalog/media ownership chain и allowlists route/feature/path/codes. Text limits/control/bidi/secret redaction применяются до persistence; optional diagnostics принимают только browser/OS/device enums и bounded numeric values. Screenshots проходят upload/MIME/decoded raster/bytes/pixels/dimensions/re-encode checks. Полный matrix: [`technical-issues.md`](technical-issues.md).

## Валидация Premium

- Public checkout принимает только stable plan code и opaque request UUID; server query заново проверяет active/public/nonlegacy plan, provider capability, region, positive integer minor amount, allowlisted ISO currency и registry-backed entitlements. User/provider/customer/amount/currency/status/expiry не являются form fields.
- Provider adapter проверяет signature на raw body и возвращает typed normalized event. Reconciler повторно проверяет provider/environment/event/object identity, checkout ownership, exact amount/currency, period chronology, stable status mapping и event timestamp; raw exception наружу не выходит.
- Admin grant использует enum feature/source/reason, bounded duration/date/private note и current authorized actor; coupon/campaign codes, windows и limits нормализуются server-side. User coupon form принимает только bounded code и не задаёт duration/discount.
- Payment, refund, dispute и entitlement models имеют explicit `$fillable`; private account UI работает через owner query/action, а audit context допускает только bounded scalar values. Полный matrix — [`premium.md`](premium.md).

## Валидация центра помощи

- Locale, article type/status/audience/owner/feature/escalation, feedback/report reason и issue/request subtype принимаются только из enum/config allowlist; article/category/revision/relation/replacement ID разрешаются заново.
- Code/slug имеют bounded Unicode/ASCII patterns и uniqueness с учётом current/history slug. Category parent ограничен двумя уровнями, replacement/related не допускают self/cycle.
- Markdown максимум 60 000 символов проходит canonical renderer и link validator; summary/SEO/callout/alias/details/private note имеют отдельные bounds. Raw HTML/template/script/style/form/iframe/image и unsafe scheme не сохраняются в public rendering.
- Feedback/report используют server actor key, rate limit, exact published translation ownership, idempotent unique/dedupe и sanitized optional reason/details. Полный matrix: [`help-center.md`](help-center.md).
