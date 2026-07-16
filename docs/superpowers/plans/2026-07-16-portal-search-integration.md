# Task 02: единая portal-search интеграция

Дата: 16.07.2026
Статус: завершено и закоммичено в `main`; ожидает синхронизации с remote

Этот план расширяет, а не заменяет [`catalog-search.md`](../../catalog-search.md), broad search design и завершённый header-autocomplete план. Он является Task 02 audit/acceptance checklist и не создаёт параллельный архитектурный контракт.

## Аудит до изменений

| Область | Подтверждено |
| --- | --- |
| Routes | `GET /search` (`search.index`), Livewire `/titles` (`titles.index`), `GET /api/v1/search/suggestions`, actor/tag directory routes и канонические `/titles/{type}/{taxonomy}`. Search state — GET query, URL shareable; `CatalogSeries` синхронизирует filters/sort/page с history. |
| Rendering | Header — progressive Blade/Vite combobox с server GET fallback; общая страница — thin controller + Blade; полный каталог — Livewire 4 `CatalogSeries`; JSON — Form Request + API Resource. Volt отсутствует. |
| Suggestions | Два independent API scope, 160 ms debounce, `AbortController`, per-scope sequence guard, max 5 title/max 12 portal items, bounded in-tab cache и server tiered cache. |
| Data | `catalog_titles.title`, `original_title`, `catalog_title_aliases`, normalized search document и FTS5. Actors — stable name/slug only. Tags — stable base row plus translation/alias tables. Seasons/episodes участвуют только в grouped public availability counts, не в text matching. |
| Locales | `ru,en`; default/fallback `ru`. Locale priority: valid route value, account preference, guest session, configured default. API uses supported `Accept-Language`. Domain/subdomain locale и localized catalog/search slug routes отсутствуют. |
| Visibility | `CatalogEntitlementService`/`CatalogTitleQuery` enforce publication, audience, availability and soft deletion. Public collection/request/profile/tag scopes enforce their own visibility. Region/premium/age title fields and personal blacklist filtering are not present in the current public search domain. |
| Search engine | SQLite FTS5, document version 3, one document per title, importer/admin/merge synchronization and safe legacy fallback. No Scout/Meilisearch/Typesense/Elasticsearch/OpenSearch/Algolia/TNTSearch. |
| DB/performance | SQLite locally/production-style and in-memory tests. One title identity per FTS document; candidate IDs prevent translation duplicates. Current DB: ~33k titles, ~14k aliases, ~111k actors, 571 tags. Existing actor/tag/pivot/alias/FTS indexes are used; no new schema required. |
| Caching | `SearchSuggestions` tiered Redis/Memcached domain; key dimensions format, locale, public audience, scope and SHA-256 normalized query. Suggestions do not contain user state. Title/tag catalog invalidation exists; collection/request/profile invalidation gap discovered. |
| Rate limits/security | Named `api-search-suggestions` limiter: 120/minute per authenticated identity or privacy-safe network fingerprint. Bound parameters, parser-generated FTS expression and allowlisted scopes/sorts/filters. Wildcards are escaped in the bound legacy partial lookup. Header title scope permits a one-character exact-title lookup; general portal search requires 2–80 normalized characters. |
| SEO | `/search` and text/filter combinations are `noindex,follow`; canonical strips query. `WebSite.SearchAction` targets `/search?q=…`. Stable actor/tag pages remain indexable according to existing directory/tag presenters and sitemaps; no fake localized alternate URLs. |
| Accessibility/UI | Header combobox has label, listbox semantics, keyboard arrows/Home/End/Enter/Escape, outside click, touch targets, loading/minimum/empty/error states and bounded viewport panel. Existing title card is reused. |
| Privacy/analytics | Search history, popular-query analytics and raw-query logging are absent. Task 02 will not add retention-sensitive persistence. |

## Риски и совместимость

- Не менять route names, `/titles` query contract, FTS schema/version, importer command, title/tag/actor identity или API envelope.
- Сохранить `/search` как default-locale URL и добавить только совместимый `/{locale}/search` для уже поддерживаемых route locales; actor/tag URL strategy не менять.
- Не путать interface locale с original title, audio, subtitle, studio или translation type.
- Не превращать portal autocomplete в полнотекстовый поиск по biography/description/user content.
- Не кэшировать authenticated watch/bookmark overlays вместе с public suggestion payload.
- Не добавлять миграцию: существующие indexes покрывают выбранный bounded lookup; rollback остаётся code-only.
- Из-за общего dirty workspace менять и коммитить только Task 02 paths; чужие staged/unstaged изменения сохранять.

## Файлы в scope

- Search query/presentation: `app/Services/Catalog/Search/*`, search Form Requests/Resource/controller.
- Cache invalidators public collections/requests/profiles.
- `resources/views/search/index.blade.php`, header search component/module and existing catalog translations.
- `routes/web.php` только если фактический integration требует совместимой route wiring.
- Владельцы документации: `docs/catalog-search.md`, existing broad spec, this execution plan, `docs/caching.md`, `docs/frontend.md`, `docs/views.md`, `docs/security.md`, `README.md`, `CHANGELOG.md`.

## Файлы вне scope

- FTS migrations/tables/triggers and importer public command.
- Player, watch progress, bookmarks, authentication, admin, API response fields unrelated to suggestions.
- Existing actor/tag identities and canonical public URLs.
- Test infrastructure: Task 02 запрещает создавать или запускать automated tests.

## Phased checklist

- [x] Прочитать все Markdown и подтвердить владельцев документации.
- [x] Проверить branch/runtime/routes/packages/locales/schema/data volumes/search index.
- [x] Проследить title, actor, tag, visibility, cache, rate limit, SEO и Livewire boundaries.
- [x] Зафиксировать dependency/performance/migration/rollback решения до кода.
- [x] Локализовать Form Request messages и server-prepared suggestion metadata/counts.
- [x] Добавить locale-aware tag translation/alias matching без duplicate base rows.
- [x] Усилить `/search`: accurate counts, ordered translated groups, safe error/empty/minimum states, no `@php`.
- [x] Закрыть collection/request/profile `SearchSuggestions` invalidation gaps.
- [x] Проверить отсутствие hardcoded UI text, raw keys, wildcard/raw SQL interpolation и N+1.
- [x] Обновить canonical docs, README visitor history и English CHANGELOG.
- [x] Выполнить Pint для PHP, route/view/config/translation/static diagnostics, Vite build и browser smoke; automated tests не запускать.
- [x] Повторно пройти 66 acceptance criteria и проверить changed/directly-related files.
- [x] Убедиться в `main`, создать scoped commit и подготовить push configured remote.

## Реализованная архитектура

- `CatalogTitleQuery::matchingTitles()` — единый visibility-aware источник кандидатов для `/titles`, global search и title autocomplete; exact/localized-or-original/alias, prefix/word/partial и FTS relevance остаются детерминированными, один base title возвращается один раз.
- `CatalogTitleSuggestionQuery` не дублирует matching SQL, ограничивает hydration и загружает aliases/season/episode aggregates пакетно.
- `PortalSearchSuggestionQuery` объединяет ограниченные публичные person/tag/directory/collection/request/profile/section группы, сохраняет стабильные type identifiers и формирует прямые канонические URL.
- `TagQuery` сопоставляет base name, все поддерживаемые переводы и approved aliases, а display выбирает active/fallback locale без размножения base tag.
- Header combobox использует 160 ms debounce, AbortController и sequence guard; cache разделён по locale/scope/query и не содержит user overlays.
- `/search` и `/{locale}/search` используют GET state, thin controller и server-rendered fallback; полный paging/filter/sort контракт остаётся у Livewire `/titles`.
- Search pages остаются `noindex,follow`; actor/tag identity, canonical pages and sitemap policy не меняются.
- Новая схема, внешняя search service, очередь, search history и raw-query analytics не добавлялись.

## Свежие доказательства проверки

- PHP style/syntax: targeted Pint завершён успешно; `php -l` прошёл для всех изменённых Task 02 PHP и language files.
- Static localization audit: `ru`/`en` имеют одинаковые 729 flattened keys и совпадающие placeholders; OpenAPI JSON валиден; изменённые search Blade не содержат `@php`, inline style или прямых model/DB calls.
- Runtime diagnostics: uncached route table содержит `/search`, `/{locale}/search`, suggestion API и прежние actor/tag routes; view cache compiles; Vite production build succeeds.
- Database smoke: localized/original/exact-short/alias/transliteration/wildcard/HTML/person/tag queries проверены через реальные query services; counts стабильны и base identities не дублируются.
- Browser smoke: Chromium desktop RU (1440), mobile EN (390) и tablet actor autocomplete; combobox arrows/active option/Escape/clear/focus, canonical actor URL, responsive overflow and console errors проверены. Снимки находятся в `output/playwright/task02-search/` и не входят в commit.
- Automated tests не создавались и не запускались в соответствии с прямым запретом Task 02.

## Final manual verification

- RU/EN: title/original/alias/transliteration, actor and localized/fallback/alias tag queries.
- Exact/prefix/word/partial ranking, typo suggestion boundary, no duplicate title/actor/tag identity.
- Query values `%`, `_`, quotes, HTML, emoji, mixed scripts, blank/one-character/81-character inputs.
- Header debounce/stale-response/clear/Escape/keyboard/touch/outside-click/loading/empty/error behavior.
- `/search?q=…`, `/titles?q=…`, actor/tag redirects, browser back/forward, catalog pagination/filter/sort URLs.
- Public/unpublished/deleted/audience/availability scopes and public collection/request/profile/tag scopes.
- Locale-aware cache key and after-commit invalidation; no user ID or personalized state in public cache.
- Search noindex/canonical/SearchAction and current actor/tag canonical/sitemap behavior.
- 320/390/768/1440 widths, zoom/long labels/no poster/long names and console/network errors.
- No migration, new dependency, Volt, Blade `@php`, inline CSS, direct model query in Blade, dead control or placeholder.
