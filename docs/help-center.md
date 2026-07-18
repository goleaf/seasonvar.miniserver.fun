# Центр помощи и самообслуживание

Обновлено: 18.07.2026

Этот документ — единственный владелец архитектуры Task 21. Центр помощи содержит повторно используемые статьи и инструкции. Он не заменяет приватные технические обращения Task 20, заявки на материалы Task 19, точечные жалобы модерации, безопасные операции аккаунта или Premium-домен.

## Результат аудита до реализации

До Task 21 в репозитории не было FAQ, базы знаний, CMS, моделей статических страниц, редакционных переводов справки, истории ревизий, отзывов о статьях, сообщений об устаревшей справке, контекстных ссылок или legacy help route. Поэтому миграция не удаляет и не переименовывает прежние данные. Единственными смежными контурами были:

- публичные заявки на отсутствующие материалы в Task 19;
- приватные технические обращения, вложения и диагностика в Task 20;
- жалобы на конкретные профили, комментарии, отзывы и подборки в канонических moderation controls;
- короткие interface-подсказки в `lang/{ru,en}` и Blade, которые остаются interface content;
- общий поиск каталога, versioned cache, sitemap responder, SEO layout и localized route resolver, которые теперь повторно используются.

Проверены маршруты, middleware, модели, миграции, policies/gates, Livewire, Blade, Vite, player/Plyr/HLS, прогресс, профиль, authentication/settings, premium, region, notifications, requests, tickets, moderation, API, локализация, cache, SEO, sitemap и администрация. Конкурирующих FAQ или help URL не найдено; совместимые `/faq`, `/support` и `/help-center` добавлены как одноступенчатые `301` alias, а не как новые источники контента.

## Границы и маршруты

Канонические публичные страницы — полноэкранные Livewire-компоненты:

| Назначение | Основной route | Локализованный route |
| --- | --- | --- |
| Главная справки | `help.index` — `/help` | `localized.help.index` — `/{locale}/help` |
| Поиск | `help.search` — `/help/search` | `localized.help.search` |
| Категория | `help.categories.show` | `localized.help.categories.show` |
| Статья | `help.articles.show` | `localized.help.articles.show` |
| Подсказки | `api.v1.help.suggestions` | локаль передаётся валидированным параметром |
| Sitemap-раздел | `sitemap.help` — `/sitemap-help.xml` | один общий потоковый документ |

`legacy.help.*` и `localized.legacy.help.*` перенаправляют `/faq`, `/support` и `/help-center` на корень справки. История slug статьи и категории выполняет только переход к текущему опубликованному адресу; произвольный destination и redirect loop невозможны. Поиск сохраняет только ограниченные `q`, `category` и `page`, а переключение языка использует стабильную identity статьи/категории и реальную опубликованную translation.

`admin.help` и `admin.help.preview` защищены существующими `auth`, session/private-account middleware и gate `manage-help-center`. Preview всегда `noindex`, не входит в sitemap и shared cache. Мутации feedback/report выполняются Livewire POST lifecycle с CSRF; GET ничего не изменяет.

## Данные и стабильная identity

Миграция `2026_07_18_210000_create_help_center_domain.php` добавляет только новые SQLite-compatible таблицы и необязательный nullable FK `technical_issues.help_article_id`, если Task 20 уже установлен:

- `help_categories`, `help_category_translations`, `help_category_slugs`;
- `help_articles`, `help_article_translations`, `help_article_slugs`, `help_article_aliases`;
- `help_article_relations`, `help_contextual_links`;
- `help_article_revisions`, `help_article_feedback`, `help_article_reports`.

Article/category имеют внутренний PK, неизменяемый публичный UUID и стабильный code. Title, locale, slug, category, ordering, type и publication status не являются identity. На статью приходится не более одной translation каждой locale; локальный slug уникален в пределах locale. История slug хранит прежний адрес и не становится отдельной индексируемой страницей. Аналогичный контракт действует для категории.

`replacement_article_id` задаёт замену архивированной статьи. `MergeHelpArticle` работает транзакционно и идемпотентно: требует опубликованную цель, предотвращает цикл, сохраняет исходную identity и ревизию, переносит отсутствующие translations, совместимые aliases/feedback/reports/relations/context links, переподключает старые redirect chains и архивирует источник. Различные по смыслу статьи автоматически не объединяются.

## Типы, категории, аудитории и feature codes

Стабильные типы: `faq`, `troubleshooting`, `how_to`, `policy_explanation`, `feature_guide`, `account_help`, `player_help`, `premium_help`, `accessibility_help`, `known_limitation`, `support_entry`. Enum определяет label/description, FAQ presentation и schema eligibility, search priority, feedback и допустимость специализированной категории. Переведённые подписи хранятся только в `lang/{ru,en}/help.php`.

Начальный реестр категорий: `getting_started`, `watching_video`, `audio_subtitles`, `account_security`, `profile_settings`, `library_community`, `releases_discovery`, `support_requests`, `devices_accessibility`, `premium_availability`. Category code и UUID стабильны, title/description/slug переводимы. Поддерживается top level и не более одного дочернего уровня; save запрещает цикл, self-parent, третий уровень и перенос родителя с детьми под другой parent. Сортировка детерминирована через `position`, затем PK. Публичные counts считаются grouped eager query только по видимым опубликованным article identities.

Аудитории: `everyone`, `anonymous`, `authenticated`, `premium`, `staff`. Policy повторно проверяет status, `published_at`, текущего пользователя и настоящий `PremiumAccessResolver`; `staff` никогда не доступна обычному посетителю. Только `everyone` может быть indexable. Authenticated/premium content может использовать общий обезличенный отрендеренный текст, но персональные ссылки, feedback state и escalation context строятся на запросе и не попадают в shared cache.

Feature relations используют enum codes `general`, `player`, `audio`, `subtitles`, `quality`, `progress`, `authentication`, `sessions`, `settings`, `privacy`, `library`, `collections`, `community`, `calendar`, `recommendations`, `requests`, `tickets`, `premium`, `region`, `devices`, `accessibility`, `notifications`, `security`. Переведённые названия функций не участвуют в связях.

## Переводы и locale fallback

Короткие controls, aria-label, error/status, category/type/audience/status/escalation labels живут в interface catalogs `lang/ru/help.php` и `lang/en/help.php`. Полные редактируемые articles, summary, Markdown body, slug, SEO, keywords и callout — в `help_article_translations`. Эти архитектуры не смешиваются.

`HelpLocale` принимает только configured `ru` и `en`; fallback — `ru`. Resolver сначала ищет опубликованный active locale, затем опубликованный fallback. Fallback page показывает явное сообщение о другом языке, получает canonical на реальный fallback URL и `noindex`; она не выдаётся за translation и не создаёт пустую страницу. `hreflang` содержит только реально опубликованные translations одной article identity. Изменение interface locale не меняет preferred audio/subtitle language и не переводит пользовательский ticket/request text.

Начальная миграция `2026_07_18_210100_publish_initial_help_center_content.php` публикует проверенный corpus обеих поддерживаемых locale из immutable `database/data/help-center.php`. Это не production seeder и не отдельная runtime CMS. Новые или отредактированные long-form материалы создаются через администрацию.

## Publication, revisions и freshness

Статусы: `draft`, `in_review`, `approved`, `published`, `archived`, `hidden`. Разрешённые переходы определены enum и повторно проверяются action. Публикация требует существующую translation с успешно проверенными внутренними links; edit опубликованного текста/метаданных блокируется, пока материал не выведен из `published`. Это исключает незаметное изменение общедоступной ревизии.

Каждое содержательное сохранение и переход создаёт append-only locale revision с article state, translation state, slug, title, summary, Markdown, SEO/callout fields, editor, change note и timestamp. Неизменённое сохранение новую revision не создаёт. Restore требует gate, создаёт audit snapshot, восстанавливает выбранную revision в draft и снимает публикацию всех translations; редактор обязан снова пройти review/publish. Ревизии и private notes не загружаются публичной страницей, не отдаются API и не индексируются.

`published_at` устанавливается только при публикации. `last_reviewed_at` и `review_due_at` меняет только явное действие «проверено»; редактирование alias/order/SEO не подделывает freshness. По умолчанию review cycle — 180 дней. Новый scheduler не требуется: admin filter показывает просроченные материалы и broken links при обычном посещении. Owner teams `player`, `account_security`, `content_operations`, `premium`, `accessibility`, `support` внутренние; имя сотрудника публично не раскрывается.

Ordering для categories/articles/featured/relations — integer position с детерминированным tie-breaker. Админ-форма позволяет точное значение и работает с клавиатуры; drag-and-drop и непрерывных записей при pointer movement нет.

## Формат, sanitization, links и media

Единственный renderer принимает ограниченный Markdown. CommonMark работает с `html_input=strip` и `allow_unsafe_links=false`; DOM pass нормализует headings до `h2/h3`, присваивает уникальные anchors, формирует table of contents/FAQ items, удаляет image nodes и повторно проверяет links. Поэтому script, event handler, style, iframe, form, JavaScript/data URL, raw Blade/PHP/template syntax и CSS overlay не могут попасть в public HTML.

Разрешены paragraphs, headings, lists, emphasis, safe links, code-like keyboard labels и доступные tables. Article H1 принадлежит page shell. Явный anchor имеет форму `{#stable-id}`; дубли получают безопасный suffix. Callout type выбирается только из `information`, `note`, `warning`, `privacy`, `security`, `limitation`, `next_step`, а CSS class автор не задаёт.

Внутренние редакционные links используют `help:article-code` или allowlisted `route:route.name`; renderer превращает их в route-helper URL. Link validator при сохранении отмечает неизвестную article identity, запрещённый route, unsafe scheme и malformed target. External link допускает только HTTP(S), без userinfo/control/backslash, с ограничением длины; render добавляет `noopener noreferrer nofollow`. Внешнее crawling на пользовательском request отсутствует.

Проект пока не имеет канонического editorial media uploader. Поэтому images из Markdown удаляются, а screenshots не принимаются и не имитируются. Когда trusted media boundary будет создан отдельно, он обязан валидировать MIME/размер, server filename, alt/caption, responsive dimensions, удаление/optimization/cache, safe demo account и отсутствие email/token/source URL/private frames. До этого изменение UI проверяется владельцем article; старые screenshot невозможны по определению.

FAQ — type той же article identity, не отдельная таблица. Visible server-rendered sections остаются доступны без JavaScript; `<details>/<summary>` сообщает expanded state нативно, сохраняет focus и работает с клавиатурой/touch. FAQ schema строится только из реально видимых `h2` question/answer sections. Long article получает server-generated доступный table of contents; content не зависит от JS.

## Публичные страницы, поиск и related content

Главная показывает prominent search, bounded featured/popular articles, category directory и реальные переходы к Task 19/20. Она не показывает private tickets или fake notices. Category page пагинируется, имеет точный published count, empty/loading/error states и не загружает все articles.

`HelpSearchService` повторно использует `CatalogSearchNormalizer`: Unicode/case/typographic normalization применяется к active/fallback title, alias, keywords, summary и body search text. Минимум — 2 символа, query ограничен 120 символами, candidates — 120, page — 12 результатов. Внешний AI/search provider, журнал запросов и ручной reindex отсутствуют: publication transaction обновляет `search_text` синхронно.

Ranking детерминирован: exact title/alias, title prefix, alias prefix, title, keywords, summary/body, active-locale preference, bounded editorial priority, article-type priority, stale-review penalty, затем normalized title и PK. Lifetime views не собираются и не влияют. Несколько translations одной article identity не создают дубликат.

`GET /api/v1/help/suggestions` всегда ищет только public guest-visible articles и отдаёт максимум 7 prepared resources с `private, no-store`. Vite autocomplete ждёт 250 ms, использует sequence/AbortController против stale response, accessible combobox/listbox, arrows/Enter/Escape, touch и plain text DOM. Без JavaScript обычная search form работает. Search/no-results page `noindex`; пустой результат предлагает категории, Task 20 и Task 19, но не создаёт заявку автоматически.

Related articles сначала используют явные ordered relations, затем дополняются совпадающим stable feature или category. Текущая/скрытая/неопубликованная статья и duplicates исключены; limit — 4. Featured требуют published state и editor authorization. «Популярные» используют helpful feedback последних 90 дней, editorial priority и freshness с bounded aggregate, а не lifetime view count. Article views/search opens/escalation clicks/device fingerprints не записываются.

Contextual links хранят feature/context/article identity и position. `HelpContextualLinkService` возвращает prepared public DTO для player error/control, playback/security/privacy/premium settings, Task 19 form, Task 20 form и Premium page. Контекстная ссылка остаётся короткой и ведёт в статью; существенное объяснение не спрятано в hover tooltip. Player errors отображают только локализованный safe state и ссылку — не exception/provider/source URL/stack/path. Decision trees — конечные редакционные шаги с явным выбором пользователя и direct article/ticket fallback, а не автоматическая диагностика.

## Проверенный начальный corpus

21 русско-английская статья описывает только реально существующее поведение:

- запуск видео, bounded retry, buffering/quality, audio/translation, subtitles, fullscreen/autoplay и реальные Plyr controls;
- progress heartbeat/resume/history/continue watching и отличие library/collections;
- registration, verification, login, password recovery, social/linked account ограничения, sessions и account security;
- profile/privacy/notification settings, comments/reviews/moderation, calendar и recommendations;
- границу content request versus technical ticket и безопасное содержание ticket;
- настоящий Premium entitlement и regional limitation без выдуманной оплаты/качества/рекламы/VPN;
- current desktop/mobile browsers, phones/tablets, touch/orientation, external display limitations, cookies/storage/refresh;
- keyboard, screen reader, subtitles, zoom, focus и reduced motion guidance;
- только реальные support channels.

Player shortcuts перечислены только если они присутствуют в текущем Plyr bridge: Space/K, arrows, M, F, C, L, 0–9 и Escape в применимом focus/context. Browser autoplay может запретить sound; portal его не обходит. Quality показывает только variants источника. Smart TV app, casting, background/offline playback, PWA install, AI diagnostics, SLA/live chat и неподключённая billing/refund функция не заявляются.

Советы не требуют отключать security постоянно, устанавливать неизвестное ПО, сообщать password/reset/verification/OAuth/MFA/recovery token, payment data или protected media URL и не содержат VPN/region-bypass. Очистка site data оставлена поздним шагом с предупреждением о logout и потере anonymous preferences.

## Escalation и support contacts

Enum escalation допускает только `none`, `technical_ticket`, `content_request`, `moderation_report`, `account_support`, `premium_support`, `rights_holder_contact`, `return_to_feature`. Author не может сохранить PHP class, произвольный внешний action или redirect. Issue/request subtype выбирается из config allowlist и повторно валидируется.

- `technical_ticket` открывает каноническую Task 20 form. Help article UUID помещается только в encrypted expiring `TechnicalIssueContext`; user может проверить данные до отправки. Search query, provider/source URL и private analytics не передаются.
- `content_request` открывает Task 19 form с allowlisted request type; повторный canonical search/duplicate check остаётся владельцем Task 19, ничего не создаётся автоматически.
- `moderation_report` объясняет, что жалоба создаётся только у точного UGC target через его canonical control; generic help не подделывает target.
- `account_support` использует secure auth/account recovery routes; secret/token не попадает в URL.
- `premium_support` создаёт private Task 20 context только для реально показанного entitlement, а не обещает refund/billing workflow.
- `rights_holder_contact` показывается только если реальный canonical channel существует; fake email/office hours/response time нет.

Public article body не хранит current ticket/request/user identity. Support contact list содержит только работающие route helpers. Technical issues, screenshots, private correspondence и resolution по-прежнему принадлежат Task 20; missing title/season/episode/translation/subtitle/quality/metadata — Task 19.

## Feedback, outdated reports и privacy

Usefulness — только `helpful`/`not_helpful`. Для negative response необязателен один stable reason: проблема не решена, неясно, устарело, пропущены шаги, ошибка перевода или другое. HMAC actor key строится из user ID либо случайной session UUID; одна active response на translation/actor обновляется идемпотентно. Public voter list и fake count отсутствуют. Rate limit — 12 попыток/час; failure не мешает чтению.

Report reasons: instructions, screenshot, broken link, unclear, incorrect, translation, removed feature, other. Details очищаются как plain text и ограничены 1000 символами. Dedupe включает translation, actor, reason и version translation: повтор текущей версии не создаёт запись, после реального обновления возможен новый report. Лимит — 5/час. Reporter/actor/private note видны только admin queue; один negative vote ничего автоматически не снимает с публикации.

Article/search views и полные search queries не сохраняются, поэтому retention для help analytics сейчас не требуется. Feedback/report сохраняются как редакционный audit evidence; account export включает только собственные submissions, deletion обнуляет FK reporter/user, сохраняя обезличенный факт. Internal note, aggregate и записи других пользователей не экспортируются. Public HTML/cache не содержит feedback state, actor key, account details, ticket IDs или private analytics.

## Cache, queries и indexes

Используется существующий `TieredCache` и `CacheDomain::HelpCenter`, а не второй cache. Guest categories, featured/popular, contextual snapshots и sanitized article content имеют dimensions `locale`, fallback/route locale, audience, article UUID, content/translation/presentation version. Search queries не кэшируются, чтобы исключить unbounded cardinality и private query persistence. Authenticated lists строятся request-time; preview/draft/staff/report/feedback/current-user state всегда обходят shared cache.

После commit `HelpCacheInvalidator` повышает версии HelpCenter и SearchSuggestions, для article — scoped UUID version, а при public mutation — Sitemap. Это покрывает body/title/slug/status/category/order/relations/alias/escalation/context/SEO/media policy/replacement; feedback меняет popular aggregate без sitemap. Global application flush не используется, cache failure логируется и не блокирует чтение.

Public query layer eager-loads только active+fallback translations/category, uses grouped counts, bounded relations/candidates/pagination и deterministic ordering. Нет query из Blade, category query на каждую card, translation query на article, related query на card или feedback count на card. Индексы миграции привязаны к public status/audience/date, category order, feature/featured/review, locale/slug/publication, alias lookup, relation/context order, feedback aggregates, reports queue и slug history. Уникальности вводятся на пустой новый домен, поэтому legacy reconciliation не требуется. SQL остаётся совместимым с SQLite и не использует database-specific FTS.

`HelpCenterSchema` даёт rolling deployment fail-closed: до миграции публичная оболочка показывает безопасное unavailable/empty state без database exception, admin/API не раскрывают partial schema. Новых queues, cron, scheduler, Supervisor или внешней платформы нет.

## SEO, sitemap и canonical policy

Indexable: help home, непустая real-locale public category и `everyone` published/indexable article с реальной translation. `HelpSeoPresenter` готовит localized title/description, canonical, real alternates, breadcrumb JSON-LD и видимый FAQPage либо Article schema.

`noindex`: search/query pages, locale fallback duplicate, empty category, authenticated/premium/staff article, draft/review/approved/hidden/archived, preview/revision/action/admin, feedback/report и personalized escalation state. Tracking/search query не входит в canonical. Open Graph включается только для допустимого public page.

`CatalogSitemapResponder::help()` потоково включает home, eligible categories и каждую eligible published translation. Draft/internal/fallback duplicate/search/preview/revision/feedback/report/legacy slug/redirect/Livewire endpoint исключены. Главный sitemap index ссылается на `/sitemap-help.xml`; cache invalidation переиспользует существующий sitemap domain. Archived URL перенаправляется только на опубликованную replacement; без неё используется safe not-found/archived policy.

## Администрирование, security и governance

Gate `manage-help-center` и `HelpArticlePolicy` проверяются внутри каждого action, а не только скрывают кнопки. Server валидирует article/category ID, locale, code/slug, enum type/status/audience/owner/feature/escalation/feedback/report, issue/request type, order, parent, relation/replacement identity и revision ownership. Actions используют validated allowlists, guarded models и transactions; mass assignment из request отсутствует.

Admin умеет создавать article/category, редактировать translations, aliases/SEO/callouts, explicit related/contextual links/escalations/order/featured, preview, draft/review/approve/publish/unpublish/archive, mark reviewed, restore revision, merge/replace и разбирать feedback/outdated queue. Broken-link filter использует сохранённый link status. Unsaved locale/navigation guard предотвращает тихую потерю текста. Списки articles/feedback/reports пагинированы; revision timeline ограничен 20 последними строками.

Preview повторно использует sanitizer, требует авторизацию и показывает явный режим preview. Arbitrary HTML/iframe/media upload нет. Category delete, destructive hard delete, public revision URL и shareable preview token не реализованы. Это сохраняет audit/legacy identity и исключает опасную операцию без отдельного reviewed action.

Article owner вручную проверяет инструкцию после изменения связанной функции, просроченного review, repeated negative feedback или report. Screenshot/media review станет обязательным только после появления trusted media boundary. Broken external link не проверяется с public request; редактор проверяет его вручную. Changelog записывает изменения продукта, help article объясняет текущее поведение, known limitation описывает существующее ограничение — типы не подменяют друг друга.

## Accessibility, responsive и frontend lifecycle

Каждая page имеет один H1, доступные breadcrumbs, visible focus, 44px touch targets, server-rendered body, понятные links, localized aria/status/error/loading labels и `aria-live` для результатов/actions. Search autocomplete следует combobox/listbox semantics; FAQ — native details; TOC, forms, feedback/report/escalation, pagination и admin controls доступны с клавиатуры. Essential content не находится только в tooltip/color/hover. Reduced-motion, browser zoom и long translated labels поддерживаются общими UI standards.

Карточки и article column используют mobile-first grid, readable line length, `min-w-0`, `break-words`, responsive table/article styles и масштабирование без page overflow. Narrow phone, landscape, tablet, laptop, large screen и zoom сохраняют search, screenshot-free body, callout, table, FAQ, TOC и escalation controls. Tables имеют доступный horizontal container там, где нельзя безопасно свернуть структуру.

`resources/js/help-center.js` отвечает только за autocomplete interaction и admin unsaved guard; ranking/publication/visibility/fallback/escalation не вычисляются в browser. Module подключается Vite, имеет debounce, abort/sequence cleanup и повторную инициализацию после Livewire navigation. Article/FAQ остаётся полезной без JS. Loading disables только target action; empty, unavailable translation/article, no related/popular/search result, validation/rate failure и cache/query failure получают локализованное безопасное восстановление без SQL/class/path/provider details.

## Развёртывание, откат и ручная проверка

Rollout: backup, обычный `php artisan migrate`, Vite build, route/config/view cache по стандартному процессу, затем browser smoke. Начальная публикация транзакционна и не обращается к сети. Не запускаются `migrate:fresh`, `db:wipe`, external link crawl, reindex queue или destructive cache flush.

Откат до новых пользовательских/редакторских записей может удалить только Task 21 tables и nullable Task 20 relation. После feedback/reports/revisions сначала нужен export/backup; предпочтителен roll-forward. Откат не меняет requests, tickets, UGC/moderation, profiles, progress, premium, catalog/media, existing cache keys или locale files вне добавленных help keys.

Минимальный ручной checklist перед выпуском:

1. Проверить `main`, migration up/down на disposable SQLite, constraints/indexes и schema guard до/после migration.
2. Проверить route list, default/localized/legacy URL, slug history/replacement, back/forward и locale switching без loop.
3. Проверить home/category/article/search/API, active/fallback locale, no duplicate translations, deterministic pagination/ranking/related/popular/counts.
4. Проверить draft/internal/premium/auth audiences как guest/user/staff; убедиться в отсутствии private content в public search/cache/API/sitemap.
5. Вставить malicious Markdown/link и подтвердить strip script/style/iframe/form/event/JavaScript/data/template/image; проверить headings/TOC/FAQ/table/callout/external rel.
6. Пройти draft → review → approve → publish → archive, preview, unchanged save, restore, merge, slug/category change, broken link и targeted cache invalidation.
7. Проверить helpful/not helpful update, reason, report dedupe/rate limit, private admin queue, export и anonymization contract.
8. Проверить контекст player/settings/request/ticket/Premium, encrypted Task 20 article context, Task 19 route и отсутствие автоматического создания escalation.
9. Проверить canonical/robots/OG/breadcrumb/FAQ schema/real `hreflang`, streamed help sitemap и исключения search/preview/action/internal/archive/legacy.
10. Проверить keyboard/touch/focus/announcements/no-JS FAQ, 320/390 px, landscape/tablet/desktop/zoom, long labels, tables и отсутствие horizontal page overflow/console errors.
11. Выполнить PHP syntax, Pint, route/static diagnostics, translation-key parity, Vite build и Playwright smoke. Автоматизированные тесты для Task 21 по прямому требованию не создаются и не запускаются.

## Подтверждённые ограничения

- Supported interface/editorial locale сейчас только `ru` и `en`; новый язык требует interface-key parity и editorial review, а не копирования fallback как перевода.
- Нет trusted article media uploader, поэтому screenshots/images intentionally removed.
- Нет external link crawler, search-query/view analytics, automatic review reminder, AI search/chatbot или background search index.
- Smart TV native app, casting, offline playback/PWA, guaranteed browser versions, billing provider, response SLA/live chat не заявляются.
- Public article editor использует безопасное Markdown textarea, не rich WYSIWYG; это сознательная граница до появления trusted editor package.
- Help не выполняет private diagnostics и не раскрывает provider/source details; такие данные принадлежат Task 20 и проверяются до отправки.
