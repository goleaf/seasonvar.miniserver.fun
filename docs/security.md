# Безопасность

Обновлено: 13.07.2026

## Правила

- `.env`, ключи, токены, cookies, приватные логи и локальные базы не коммитятся; публично отслеживается только `.env.example` без значений секретов.
- OAuth client secrets, Google ADC/service-account JSON, refresh tokens, MCP bearer tokens и приватные пути к credential-файлам не хранятся в репозитории.
- Код приложения читает переменные окружения через `config()`. Прямые `env()` допустимы только в `config/*.php`.
- Публичные страницы каталога остаются read-only и проходят строгую валидацию query-параметров через Form Request-классы.
- Все текущие публичные маршруты с implicit binding `CatalogTitle`, включая карточку `/titles/{slug}`, API show и proxy постера статистики, доступны только при `is_published=true`; неопубликованный тайтл возвращает `404` до controller/responder и не инициирует внешний HTTP-запрос.
- Служебная страница `/stats` доступна гостям как read-only Livewire-сводка, дополнительно ограничена rate limiter `catalog-stats` и не выводит raw source URLs, приватные media URLs, stack traces или внутренние имена маршрутов.
- Livewire-компонент `/stats` получает данные через очищенный snapshot: полный массив статистики не хранится в публичных свойствах компонента и не должен попадать в `wire:snapshot`.
- Livewire-компонент карточки хранит только locked `catalogTitleId` и bounded URL-скаляры. Watchlist/rating/progress actions требуют authenticated user, а `CatalogUserStateService` сам применяет `CatalogTitlePolicy::interact` и повторно разрешает episode/media внутри доступной hierarchy; пользовательские/profile IDs не принимаются аргументами записи. Список просмотра принимает только желаемый boolean, а оценка — только значение из серверного диапазона.
- `throttle:livewire-action` защищает Livewire transport двумя Redis-бюджетами, а `SensitiveActionRateLimiter` дополнительно разделяет бюджеты поиска, playback-session, progress, rating, watchlist, history и import-admin по authenticated user и хэшу ресурса. Source-health ограничивается по хэшу host; исчерпание локального бюджета дает `not_checked`, не считается отказом провайдера и не раскрывает host в cache key.
- Playback source выдается только через подписанный на пять минут `/playback/{licensedMedia}` с viewer binding и rate limit. Resolver получает `CatalogEntitlementDecision` для каждого parent/media, повторно проверяет принадлежность media выбранной серии, известные source failures, формат и storage/host allowlist. Raw provider URL, private path и credentials не сериализуются в Livewire и не принимаются от браузера.
- Livewire update endpoint использует два атомарных Redis budgets: общий actor-level потолок 600 requests/minute предотвращает обход перебором component/action buckets, а allowlisted feature bucket ограничивает каждое известное действие 180 requests/minute; malformed/unknown snapshots сводятся в один bounded bucket до проверки Livewire signature. Доменные sensitive-action limits остаются более строгой второй границей для playback, progress, ratings, watchlist, history и import writes.
- Video sitemap использует только внутренний `video:player_loc` страницы тайтла; постоянный provider URL и storage path не публикуются как `video:content_loc`.
- Внешняя playback-проверка допускает только HTTPS:443 hosts из `PLAYBACK_ALLOWED_HOSTS`, отклоняет credentials, localhost, private/reserved/link-local/metadata IP и любой host с непубличным A/AAAA. Выбранный проверенный адрес закрепляется через cURL resolve на один запрос, поэтому повторное DNS-разрешение HTTP-клиентом не открывает SSRF; redirects отключены.
- Для обычного видео отправляется bounded Range GET, для HLS читается только ограниченный manifest fragment и проверяется `#EXTM3U`. `SEASONVAR_MEDIA_CHECK_MAX_RESPONSE_BYTES`, connect/total timeout и закрытие stream не позволяют скачать полный файл. В события попадают только HTTP status, latency и allowlisted error category; URL, query tokens, authorization headers, response body и exception message не сохраняются.
- Прокси `stats.poster` всегда делает запросы только к HTTPS-URL с валидацией `poster_url`, закрепляет проверенный публичный A/AAAA через cURL resolve, не следует редиректам (`withoutRedirecting()`), отбрасывает слишком большие изображения и проверяет `Content-Type` как `image/*`. Непустой `Content-Length` проверяется до чтения тела, а фактический размер тела проверяется всегда; корректные chunked-ответы без `Content-Length` допускаются.
- `CatalogStatsPosterUrlGuard` используется и при сборке `/stats`, и в proxy responder: неразрешимые hostnames, localhost, `.local`, private и reserved IP не попадают в `poster_src` и не создают браузерные 404-запросы.
- Внешние URL Seasonvar нормализуются и допускаются только для `seasonvar.ru`; внешние playlist URL не могут содержать credentials, указывать на localhost, `.local`, private/reserved A/AAAA или проходить через redirect. Публичный адрес входного playlist закрепляется на запрос.
- Google service-account `token_uri` принимается только в каноническом виде `https://oauth2.googleapis.com/token`, поэтому подписанный JWT assertion нельзя перенаправить на endpoint из подмененного credential JSON.
- Google service-account access token не сохраняется в Redis, Memcached, session или другом cross-request cache; он существует только в памяти текущего API-вызова. Cache layer не хранит passwords, CSRF, raw tokens, credential paths, raw signed media URL или private authorization state.
- Public cache dimensions валидируются, ограничиваются и canonical-hash-ируются; raw search, IP, user ID и token не становятся ключом или metric label. Shared snapshots имеют explicit public audience/locale, а authenticated/non-default facets bypass-ят public tier.
- `/health/ready` не создаёт session, имеет `no-store, private`, отдельный rate limit и возвращает только component status/latency без Redis/Memcached hostnames, DB paths или credentials.
- Внешние MCP и Google Workspace данные считаются недоверенным контентом; write tools и широкие scopes включаются только под конкретную задачу и с user-level authorization.
- Локальные temporary storage URLs отключены по умолчанию через `LOCAL_FILESYSTEM_SERVE=false`; включать их можно только для явной функции загрузки/выдачи файлов с отдельной авторизацией.
- Пользовательские uploads по умолчанию сохраняются на приватный disk `uploads`; нельзя доверять клиентским именам файлов, делать upload-файлы публичными без отдельной авторизации или отдавать private paths наружу.
- Operational logs/notifications не включают targeted importer URL, stack traces, raw HTML source snapshots, private media URLs, query tokens, секреты или credential paths; сохраняются режим, run/source IDs, класс исключения и очищенная категория/ошибка.
- `/admin/catalog` закрыт тем же configured email allowlist, что importer admin, и дополнительно использует policy, hierarchy-scoped lookup, locked IDs/versions и отдельный sensitive-action rate limit. Stored playback URL не рендерится; новый URL проходит общий SSRF/provider allowlist.
- Blade-шаблоны не содержат `@php`/`@endphp`; вывод экранируется через `{{ }}`, кроме JSON-LD с `JSON_HEX_*` флагами.

## HTTP

- Web-ответы добавляют защитные заголовки: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` и `X-Permitted-Cross-Domain-Policies`.
- Строгий CSP пока не включен: страницы карточек используют внешние постеры и внешние media URLs, поэтому CSP нужно проектировать отдельно с учетом этих источников.
- Laravel web middleware сохраняет стандартные encrypted cookies и `PreventRequestForgery` для небезопасных HTTP-методов.

## Проверки

- `SecurityHardeningTest` и существующие importer maintenance tests проверяют security headers, rate limit `/stats`, отключенные storage routes, entitlement/source recheck, playback fallback, blocked private/local/metadata hosts, unsafe redirects, timeout classification, redacted logs, failure thresholds, permanent failures и recovery.
- `PrivateImageUploadRulesTest` и `PrivateUploadStorageTest` проверяют upload-валидацию, private visibility, generated filenames и cleanup через fake storage.
- `RunSeasonvarImportJobTest` и `SeasonvarImportFailedNotificationTest` проверяют dispatch/content operational notification без отправки реальных писем.
- `CatalogPageTest` и `CatalogStatsSnapshotSanitizerTest` проверяют, что stats HTML/Livewire-рендер не раскрывает исходные source/media URL, stack traces и приватный event context.
- `composer audit` и `npm audit --audit-level=high` используются для проверки известных уязвимостей зависимостей.
