# Требования к системной интеграции

Обновлено: 18.07.2026

Этот документ обязателен для любой задачи, затрагивающей более одного feature domain, shared identity, visibility, access, notifications, audit, cache, search, SEO, account lifecycle, imports или administration. Он дополняет тематические документы-владельцы и не создаёт вторую доменную архитектуру.

## 1. Maintenance of requirements

- До работы читаются все канонические requirements в порядке из [`index.md`](index.md), проверяются ссылки и обновляется current task plan/compliance matrix.
- Новое постоянное правило добавляется в единственный тематический owner; unresolved conflict фиксируется честно и не отмечается completed.
- Перед завершением требования перечитываются, а repository повторно проверяется на legacy/duplicate/stale/dead/unfinished implementation текущей области.

## 2. Cross-feature impact

- Ни одна feature не меняется изолированно, если она влияет на authentication, authorization, translations, caching, search, notifications, SEO, privacy, mobile behavior, administration, audit, imports, premium, region, legal restrictions или public routes.
- Для каждого затронутого domain фиксируется `affected|unaffected|not_applicable|unresolved`, evidence, migration/compatibility strategy и rollback.
- Public route names, localized URLs, stable DB identities, enum/event/notification codes, cache identities и translation keys сохраняются либо получают полный compatibility migration.

## 3. Canonical identities

- Один canonical `User` используется authentication, profiles, progress, library, comments, reviews, collections, requests, tickets, premium, advertiser memberships, legal cases, notifications, administration, audit, export, deletion и merge.
- `CatalogTitle`, `Season` и `Episode` являются canonical content identity. Translated title, slug, provider URL, poster, season label и episode number не заменяют stable relation identity.
- Merge/delete/restore processes обязаны reconciliation всех dependent rows до destructive removal; compatibility adapters сохраняются, пока dependants не проверены.

## 4. Shared decision boundaries

- Visibility, entitlement, region, legal restriction, locale fallback и owner/permission decisions принадлежат каноническим server-side services/policies/scopes.
- Browser, Blade, Livewire public state, cache hit или signed URL не становятся authority.
- Access-context DTO, если используется, содержит только минимальные server-resolved values и не сериализует secrets, raw provider state или полный permissions graph.

## 5. State-changing actions

- Actions повторно authenticate/authorize/validate identity и relationship, идемпотентны при возможном retry и используют short transaction для связанных writes.
- После commit обновляются только affected cache/search/SEO/sitemap/recommendation/calendar/notification/admin projections.
- Optional events/queues не скрывают invariant и не делают correctness зависимой от отсутствующей infrastructure; synchronous fallback допустим.

## 6. Notifications and audit

- Notification categories и audit events используют стабильные application codes; labels переводятся только при presentation.
- Recipient authorization, preferences, locale, deduplication, account merge/deletion и delivery failure обрабатываются общей architecture.
- Audit append-only там, где этого требует domain, не содержит secrets, raw private notes, provider payloads, protected URLs или private documents.

## 7. Privacy and storage

- Public/private/unlisted state разрешается server-side; private DTO/cache/search/sitemap/service-worker payload запрещён.
- Internal notes, tickets, legal documents, advertiser reports, invoices, exports и private attachments используют отдельные authorized storage/download boundaries и не входят в public cache/notification/export без policy.
- Account merge/deletion/export учитывают legal/financial/audit retention и не уничтожают evidence без approved policy.

## 8. Query and cache integration

- Card, badge, dashboard и navigation state загружается grouped/eager/aggregate queries, не one-query-per-item.
- Pagination, date ranges, history windows, duplicate search и Livewire payloads bounded; provider calls не выполняются на ordinary render.
- Cache inventory определяет owner, public/private class, dimensions, TTL, invalidation, fallback, merge/delete behavior и service-worker interaction. Global private cache и broad flush запрещены.

## 9. Multilingual, frontend and accessibility

- Supported locales, key/placeholder/plural parity, fallback, localized routes, Livewire hydration, SEO/`hreflang`, email/notification/admin/a11y labels проверяются после cross-system change.
- User-facing text переводится existing translation architecture; translated label не становится persisted identity.
- Mobile/desktop/zoom/keyboard/screen reader/reduced motion/long labels/loading/empty/error/unauthorized/unsupported states проверяются без dead, fake или hover-only controls.

## 10. Security and production readiness

- Review покрывает IDOR, CSRF, mass assignment, XSS, SSRF, redirects, path traversal, uploads/private files, tokens/sessions, caches/service worker, payments/webhooks, cross-organization data, legal/ticket privacy, exports и least privilege.
- Production impact следует [`production-operations.md`](production-operations.md); dependency/runtime changes дополнительно следуют [`maintenance-and-upgrades.md`](maintenance-and-upgrades.md).
- Не выполненная browser/provider/production/restore verification отмечается честно; installation/build/HTTP 200 не доказывают full functional compatibility.

## 11. Administration

- Administration получает prepared read models из canonical domains и не копирует state transitions, visibility, permissions, pricing, legal, premium или advertiser logic.
- Immutable financial/legal/audit/history state меняется только специализированной reconciliation action; arbitrary SQL/shell/Artisan/env/file access и fake operational data запрещены.

## 12. Completion gate

- Все изменённые и directly related unchanged files инспектируются; routes, middleware, policies, schema, queries, Livewire/Blade/JS, translations, cache, SEO/sitemap, storage, account lifecycle, imports и administration сверяются с matrix.
- Выполняются только разрешённые static/build/browser/manual checks. Automated tests не создаются и не запускаются, если task/permanent rule это запрещает.
- README, тематические owner docs, current plan, compliance matrix и русский `CHANGELOG.md` обновляются только по фактическому результату; commit/push выполняются из existing `main`.
