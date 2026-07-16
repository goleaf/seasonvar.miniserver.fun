# Мультиязычная главная страница — архитектурный аудит и дизайн

Дата: 16.07.2026. Область: Task 01, главная страница и её общая layout-оболочка. Рабочая ветка: существующая `main` по прямому разрешению пользователя.

## Цель

Встроить всю главную страницу в уже работающую локализацию Laravel без второго translation layer, нового пакета или дублирования тайтлов по языкам. Интерфейс должен одинаково работать на всех поддерживаемых locale, а locale должен сохраняться между обычными и Livewire-запросами. Переводимый presentation text, SEO, даты, числа, plural forms и accessibility labels отделяются от стабильных доменных кодов и внешнего контента.

## Обязательный аудит существующей архитектуры

1. **Поддерживаемые locale:** `ru` и `en`. Текущий общий allowlist задан в `config/catalog-collections.php` (`supported_locales`) и согласован с настройками аккаунта, коллекций, тегов и заявок.
2. **Locale приложения по умолчанию:** `ru` через `config/app.php` / `APP_LOCALE`; default настройки аккаунта также `ru`.
3. **Fallback locale:** `ru` через `config/app.php` / `APP_FALLBACK_LOCALE`; DB-переводы коллекций и тегов используют согласованный content fallback `ru`.
4. **Выбор active locale:** middleware `ApplyAccountPreferences` учитывает разрешённый route locale, locale из session для локализованных/Livewire-запросов, сохранённую настройку пользователя и default. Текущий guest/session приоритет на обычных URL неполон и будет безопасно выровнен.
5. **Locale из URL:** да, но только на локализованных alias-маршрутах аккаунта, discovery, коллекций, заявок и комментариев; у главной страницы alias пока отсутствует.
6. **Locale в session:** ключ `interface_locale_route`; его записывает `SetInterfaceLocale`, а `ApplyAccountPreferences` восстанавливает, в том числе на Livewire update.
7. **Locale в cookies:** отдельной locale-cookie нет. Session может использовать обычную Laravel session cookie как транспорт идентификатора сессии.
8. **Locale в профиле:** `user_account_settings.locale`, доступ через `AccountSettingsService` и relation модели `User`.
9. **Browser headers:** web не выбирает locale по `Accept-Language`; JSON API использует `SetApiLocale` и `Request::getPreferredLanguage()` с allowlist.
10. **Domain/subdomain:** locale не зависит от домена или subdomain; locale-domain routing отсутствует.
11. **Route prefixes:** используются частичные `/{locale}` alias-маршруты; глобального обязательного prefix для всего каталога нет.
12. **Translated routes:** имеются отдельные `localized.*` route names, но path segments не переводятся.
13. **Laravel PHP language files:** используются `lang/ru/*.php` и `lang/en/*.php` с namespaced semantic keys.
14. **Laravel JSON language files:** отсутствуют.
15. **Database-driven translations:** используются для редакционных коллекций и глобальных тегов.
16. **Model translation packages:** не установлены и не используются.
17. **JSON translation columns:** переводы не хранятся в JSON columns; существующие JSON-поля относятся к другим доменам.
18. **Separate translation tables:** `catalog_collection_translations` и `tag_translations` с уникальностью parent + locale.
19. **Separate language databases:** отсутствуют; используется одна SQLite database, тестовая среда также SQLite-compatible.
20. **Translation content API:** внешнего API машинного/контентного перевода нет.
21. **Metadata per language:** интерфейсные/SEO-ключи локализованы в PHP; коллекции и теги имеют DB name/description/SEO translations. Core `CatalogTitle` отдельной translation relation не имеет.
22. **Translated slugs:** отсутствуют; collection/tag translations не содержат slug, используется стабильный canonical slug и slug histories.
23. **Localized canonical URLs:** discovery, collections и requests умеют locale-aware canonical; главная страница пока нет.
24. **Localized sitemap:** discovery и редакционные коллекции учитывают locale; главная пока представлена только `/`.
25. **`hreflang`:** layout поддерживает `seo.alternates`; discovery/collections/requests их задают, главная пока не задаёт полноценный ru/en набор.
26. **Admin translation management:** администраторы редактируют переводы тегов и коллекций. У core title translation UI нет, потому что нет соответствующей schema.
27. **Missing translation detection:** автоматического runtime detector/editorial alert нет; работает Laravel fallback.
28. **Translation key validation:** универсальной parity utility нет. Для изменения выполняется статическая рекурсивная сверка ключей, placeholders, PHP syntax и отсутствие hardcoded presentation strings.
29. **Translation caching:** отдельного compiled translation cache нет; применяются штатный request/runtime loader Laravel и обычные config/route/view caches.
30. **Language switcher:** существует в виде page-specific locale links и select в account settings; общего header switcher на главной нет.
31. **Livewire locale persistence:** web middleware восстанавливает session locale на Livewire update; collection/comment components дополнительно применяют locked locale state.
32. **Locale middleware before Livewire:** `ApplyAccountPreferences` добавлен в `web` middleware group; Livewire update проходит через эту группу.
33. **Validation locale:** Laravel validation использует active locale; project requests/components используют переведённые project messages.
34. **Date localization:** `AccountDateTimeFormatter` использует `IntlDateFormatter` и timezone пользователя. На главной остаются ручные `d.m.Y`, их нужно заменить.
35. **Number localization:** штатный `Illuminate\Support\Number` доступен, но homepage stat сейчас использует `number_format`, а некоторые counters выводятся raw.
36. **Pluralization:** используется Laravel `trans_choice`; русские каталоги содержат формы `one/few/many`, английские — `one/other`.
37. **Text direction:** текущие `ru` и `en` — LTR, RTL locale и отдельной RTL strategy нет. Реализация не заявляет несуществующую RTL-поддержку и избегает новых жёстких left/right assumptions.
38. **DB content fallback:** collections: active translation → configured default translation → base fields; tags: active → app fallback → canonical name. Titles используют сохранённые `title`, `original_title`, aliases и provider content без автоматического перевода.
39. **Key naming:** один PHP-файл на домен, вложенные semantic keys, dot notation и named placeholders. Для Task 01 подходит отдельный `home.php`, общая оболочка расширяет `catalog.php`.
40. **Markdown documentation:** выбор locale/Livewire описан частично в `architecture.md` и `frontend.md`; DB translations — в `DATA_RELATIONS.md`/`administration.md`; cache — в `caching.md`; SEO — в `architecture.md` и discovery specs; workflow — в `development.md`. Единого владельца локализации в карте пока нет.

## Рассмотренные подходы

### A. Расширить существующую Laravel-архитектуру — выбран

- Добавить semantic keys в `lang/ru/home.php` и `lang/en/home.php`, а общие labels — в существующий `catalog.php`.
- Добавить `/{locale}` alias для главной, не меняя URL strategy тайтлов.
- Сохранить locale в существующих session/user settings через validated POST switch и использовать существующий middleware на SSR/Livewire.
- Локализовать SEO/canonical/alternates, даты, числа и plural forms штатными сервисами.
- Оставить DB translations только там, где schema уже их поддерживает; core title/provider/audio/studio content не машинно переводить.

Это минимальное расширение текущего контракта и не требует миграций или production dependency.

### B. Оставить только unprefixed `/` и saved user locale — отклонён

Подход не даёт гостям устойчивого language URL, корректных home canonicals/`hreflang` и locale-specific sitemap entries.

### C. Добавить translation package и/или title translation tables — отклонён

Пакет дублирует Laravel/local project behavior, а новые title translations не имеют авторитетного источника и административного workflow. Это изменило бы data model вне Task 01.

## Компоненты решения

### Locale selection и switch

Приоритет web request: валидный route locale → для `livewire.update` валидный session locale → для authenticated request сохранённый locale пользователя → для guest request валидный session locale → configured default. Все значения проходят allowlist `supported_locales`. Switch выполняется CSRF-защищённым POST, пишет session и, для authenticated user, существующую account setting. Redirect допускает только local safe URL и исключает Livewire internals; для главной выбирается named localized route, для unprefixed stable routes сохраняется path.

### Главная и presentation data

`CatalogHomePageBuilder` возвращает стабильные коды/модели и locale-aware view data, а Blade переводит интерфейс. Discovery links используют существующие recommendation enum values. Title identity и provider metadata не зависят от interface locale; DB translations коллекций загружаются только для active/fallback locale текущим query service без duplicate rows.

### Dates, numbers и pluralization

Существующий `AccountDateTimeFormatter` расширяется date-only и date-group formatting. `today`/`yesterday` — semantic keys, остальные даты — `IntlDateFormatter` с active locale и configured/user timezone. Counts форматируются `Number::format(..., locale: App::currentLocale())`, noun forms — `trans_choice` с named placeholders.

### SEO и links

Home SEO берётся из `home.seo.*`. `/` остаётся x-default/default entry, `/{locale}` — resolved locale canonical. Alternates включают только `ru`, `en` и x-default, каждый URL соответствует существующему named route. Sitemap получает оба localized home aliases. Title links продолжают генерироваться `route()` по stable slug: translated slugs не выдумываются.

### Livewire

Homepage sections остаются SSR. Любой Livewire child/update получает locale через глобальный `web` middleware и session, а public mutable locale не вводится. Locale-dependent computed/cache data всегда создаётся после middleware. Existing locked locale components остаются совместимыми.

### Cache

Snapshot/facet/page cache keys уже включают validated locale; это сохраняется. Public homepage HTML дополнительно получает deterministic fingerprint active/fallback PHP catalogs, которые формируют guest home/layout, поэтому source translation edit выбирает новый namespace автоматически. Raw global counts не меняют смысл между языками, а labels форматируются после чтения. Domain-version invalidation сбрасывает все locale variants для content mutations; warmer последовательно прогревает поддерживаемые locale с восстановлением исходного `App` locale. User-specific continue-watching не попадает в public home payload.

### Accessibility и layout

Homepage-visible header, skip link, search, navigation, footer, section labels, empty states, poster/video labels и locale switcher используют translation keys. Locale labels не фиксируются по ширине, active language отмечается текстом и `aria-current`; flags не используются.

## Fallback и границы данных

- Missing interface key: штатный Laravel fallback `ru`; статическая parity-проверка предотвращает новые пропуски.
- Collection/tag content: существующий active → fallback → base contract.
- Title/original/episode/provider content: существующие display accessors и исходные значения; отсутствующее значение не превращается в key/ID/enum, presentation layer использует нейтральный translated placeholder только там, где он обязателен.
- Translation studio/audio/subtitles: brand/provider name остаётся исходным; stable translation type codes переводятся отдельно. Interface locale не переименовывается в audio language.
- User-generated comments/reviews не переводятся автоматически.

## Проверка

По прямому требованию задачи новые automated tests не создаются и test runner не запускается. Выполняются static audits: PHP syntax, рекурсивная parity и placeholders для `ru/en`, route inspection, hardcoded-string scan, `git diff --check`, Pint, Vite production build и managed documentation check. Ограничение и remaining manual browser risk фиксируются явно.
