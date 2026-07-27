# Task 117 — compliance восстановления редакционных подборок

Дата: 27.07.2026.

| Требование | Статус | Evidence |
| --- | --- | --- |
| Root `AGENTS.md` и requirement index | `completed` | Прочитаны до реализации |
| Collection architecture/public quality | `completed` | Root cause census и новый exact recovery contract в `docs/architecture.md` |
| Human-reviewed classification | `completed` | Проверены source provenance, состав, score/issues и девять stable category slugs |
| Demo/empty/ambiguous exclusion | `completed` | 447 demo, 39 empty и 5 сомнительных source rows исключены |
| Laravel/API/version compatibility | `already_compliant` | Laravel 13.22.0; routes/API/schema не меняются |
| Authorization/security/privacy | `completed` | CLI-only exact manifest, no arbitrary IDs/URLs/names; generic errors и safe aggregates |
| Production operations | `completed` | Verified 31 423 373 312-byte SQLite backup; quick/FK checks; writers/cron pause и точное восстановление |
| Caching/recommendations/SEO | `completed` | Existing public scope, targeted invalidation, API/sitemap contracts и quarantine preservation проверены |
| Tests/static/browser | `completed` | 2 301 tests / 208 462 assertions; build/static checks; production desktop/mobile: 10 cards, 5/31 tree, no overflow |
| PWA/browser infrastructure | `unresolved` | Существующий `/service-worker.js` отвечает `404` и даёт один console error; collection local requests/page errors отсутствуют |
| README/CHANGELOG/docs | `completed` | Canonical docs, visitor history, changelog, maintenance и archive evidence обновлены |
| Commit/push в `main` | `unresolved` | Implementation commit `b2e97d80`; push остановлен отсутствующим HTTPS credential helper |
