# Валидация запросов

Обновлено: 15.07.2026

## Form Request

- Публичные фильтры каталога проверяет `App\Http\Requests\CatalogTitlesRequest`.
- Страница карточки проверяет `season`, выбранную серию, video ID и media profile через `App\Http\Requests\CatalogShowRequest`: variant обязан быть slug-key, quality/format входят в allowlists `config/playback.php`. Livewire повторяет эти allowlists, нормализует положительные ID и не доверяет URL/browser identifiers.
- Контроллеры принимают типизированные Form Request-классы и не вызывают inline `$request->validate()`; full-page `CatalogSeries` переиспользует тот же `CatalogTitlesRequest::validateResolved()` для URL и Livewire update state.
- Для read-only фильтров каталога синтаксис query-параметров валидируется строго. Пустые значения и дубли удаляются, неподдерживаемые значения отклоняются правилами, а существующие slug резолвятся в уникальные положительные ID с лимитом 20 значений на тип перед построением SQL.
- `publication_type[]` проверяется через `CatalogPublicationType`, `subtitles[]` принимает только `available|missing`, `quality[]` — только явно перечисленные разрешения. Фиксированные группы имеют собственные меньшие лимиты; отсутствующие записи справочников игнорируются без ошибки.
- Невалидный год поиска не редиректит посетителя: он сохраняется как `requestedYear`, помечается `invalidYear` и дает пустую выдачу с понятным сообщением.
- Ошибки формы поиска каталога показываются из Livewire error bag без redirect; GET fallback и остальные обычные формы сохраняют стандартный `old()` внутри `x-form.search-field`.
- Поисковый query-параметр `q` перед валидацией приводится к NFKC, обрезается по краям, а последовательности Unicode-пробелов схлопываются. Непустая нормализованная строка должна содержать от 2 до 80 Unicode-символов (`min:2|max:80`) и никогда не обрезается молча. Нескалярный `q` становится пустым безопасным значением.

## Правила

- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`.
- Поддерживаемые варианты сортировки перечислены в `App\Enums\CatalogSort`; invalid/non-scalar `sort` возвращается к `updated`, отдельный `direction` игнорируется, а raw query-значение никогда не передается в `orderBy()` как имя столбца.
- Livewire 4 нормализует malformed `page` к 1; положительная страница за последней доступной границей восстанавливается к `lastPage()` после count query.
- Slug-значения справочников и контекста карточки проверяет reusable rule `App\Rules\CatalogFilterSlug`.
- Сообщения Form Request для публичных страниц должны быть на русском языке.
- Для `q.min` используется сообщение `Введите не менее 2 символов для поиска.`, для `q.max` — `Поисковый запрос слишком длинный.`.
- Blade-шаблоны только показывают ошибки через стандартные Blade-директивы, если появятся формы; вычисления и нормализация остаются в request/view-model слоях.

## Валидация коллекций

- Livewire create/editor валидируют name 2–160, optional description до 10 000, enum visibility/sort, supported editorial locale и SEO limits; service повторяет normalization/limits через `UserPlainText`. Owner, moderation, feature и system type не принимаются из request.
- API index/show используют отдельные Form Requests: `q` до 100, allowlisted directory sort/per-page, `page` 1–10 000 и item `per_page` 6–48. Resolver ограничивает slug длиной 180 и нормализует case; invalid UUID/slug/item/title маскируются безопасным 404.
- Membership batch принимает не более 100 valid UUID, затем сравнивает их с locked owner-scoped collection set; duplicate UUID схлопывается. Reorder принимает unique positive IDs до 500 и повторно проверяет каждую принадлежность collection; direction только `-1|1`.
- Cover применяет `PrivateImageUploadRules`; report reason/status — enums, details до 2 000 plain-text characters. Все пользовательские errors берутся из parity catalogs `lang/{ru,en}/collections.php`; SQL/class/path/internal IDs не включаются.

## Валидация обсуждений

- `CommentBody` является одной server-side boundary create/reply/edit: NFKC, line ending normalization, strip tags/script/style/control/bidi, trim outer/line whitespace, preservation максимум двойного пустого абзаца. Empty result отклоняется; Unicode/non-Latin scripts сохраняются.
- Limits из `config/comments.php`: body 5 000 characters, 40 lines, 2 URL-like tokens, 5 `@` tokens и 30 одинаковых последовательных characters. `javascript:`, `vbscript:` и executable HTML/JavaScript/SVG/XHTML `data:` MIME отклоняются; обычное слово `data:` допустимо, а links остаются escaped non-clickable plain text. Report/private note имеют отдельный limit 2 000 и тот же plain-text sanitizer.
- `CommentTargetType`, `CommentSort`, `CommentReactionType`, report/status/reason/restriction enums — единственные допустимые internal codes. Target ID положительный и повторно разрешается allowlisted resolver; выбранный reply target обязан быть published/non-deleted на той же цели, а его structural root — published и live либо author-deleted tombstone. UUID submission token, expected edit version и selected comment ID перепроверяются server-side.
- Errors берутся только из exact-parity `lang/{ru,en}/comments.php`, не включают SQL/class/table/internal anti-spam detail. Каждое abuse-prone action проверяет exact target-scope и более мягкий user-global bucket; rate-limit message получает bounded retry seconds от исчерпанного server-owned key. Expired restriction считается inactive синхронно. Client-side `maxlength`/disabled controls — только UX, не security boundary.

## Валидация отзывов

- `ReviewTitle` и `ReviewBody` — единая create/edit boundary. Before normalization they reject arrays/arbitrary non-stringable objects and enforce bounded raw UTF-8 byte ceilings, so hostile markup/whitespace cannot force unbounded sanitizer work or become the literal `Array`. Title required, trimmed Unicode 5–120 characters and non-generic; body required, 100–12 000 characters, maximum 80 lines, 2 URL-like tokens and 30 repeated consecutive characters. NFKC and line-ending normalization preserve non-Latin script/meaningful paragraphs; invalid UTF-8, control/bidi, empty sanitized value and `javascript:|vbscript:|data:` are rejected.
- Optional rating accepts only canonical integer 1–10 and writes the current actor/title state; empty is `null`, never 0. Spoiler is server-cast boolean. Target type is fixed `title`, target/review/version positive IDs are reloaded, UUID submission tokens are validated, and author/status/verified/deletion fields are never accepted from form data.
- Sort/filter codes come only from `ReviewSort`, rating filter 1–10, spoiler/verified tri-state from bounded values, page positive and normalized. Vote, report category/status, moderation reason/status, restriction type/reason and notification type are enums; no raw SQL column or translated label reaches a query.
- Report details pass `UserPlainText` with a 1 000-character bound; private moderator/restriction notes use 2 000. Moderator author search removes control/wildcard input and target accepts a normalized slug or positive ID. Errors use exact-parity `lang/{ru,en}/reviews.php`, never reveal anti-spam threshold, SQL/class/table/private evidence, and include bounded retry/restoration values.
- Client `maxlength`, character counter, disabled/loading and browser draft are UX only. Server action revalidates policy/restriction/block/target/rating/version/idempotency inside the transaction; draft is cleared only after confirmed success.

## Валидация тегов

- `TagNormalizationService` — единая label/alias/provider comparison boundary: NFC display, NFKC comparison when Intl is available, entity decode before tag stripping, NBSP/whitespace squish, control/format removal, dash/separator normalization, optional leading `#`, Unicode case-fold. Display preserves original safe case/script/diacritics; normalized hash используется только для identity/search/duplicates.
- Label length 2–80, required meaningful letter/number, all scripts allowed, pure emoji/markup/control/invisible input rejected. Personal description — escaped plain text максимум 1 000; system/editorial descriptions/SEO additionally bounded in `TagService`. Content/translation locale только `ru|en`; unsupported non-null locale rejected, user content never auto-translated.
- Stable global code matches lowercase language-independent allowlist and existing code immutable. Slug contains only lowercase ASCII letters/digits/hyphens, max 180, checked across current/history/alias slugs; service handles collision without trusting request column. Type/visibility/moderation/source/alias-source/synonym-relation/provider-status values come only from enums.
- Exact duplicate checks use global normalized hash for global tags and `(owner, hash)` for personal tags, include soft-deleted owner rows and approved aliases where relevant. Fuzzy similarity/transliteration/translated equivalence is never automatic merge. Alias cannot equal canonical/conflict with another canonical/alias, self synonym invalid, merge source/target must be distinct eligible exact rows.
- Assignment request requires present array, max configured 50, distinct UUIDs; service additionally checks exact UUID syntax, lowercases canonical form, resolves every row within current owner, reauthorizes title and rejects partial/unauthorized set. Global assignments accept only globally assignable tag/title through policies. Sort/filter/route slug use existing catalog allowlists.
- Errors use exact-parity `lang/{ru,en}/tags.php`, escaped placeholders and safe generic authorization/failure text; no SQL/table/class/internal ID/provider credential/moderation note. Client maxlength/disabled/debounce are UX only; service validation, policies, DB uniqueness/transactions and rate limits remain authoritative.
