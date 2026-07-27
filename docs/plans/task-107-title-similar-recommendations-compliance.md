# Task 107 — compliance matrix

Обновлено: 27.07.2026.

| Требование | Статус | Evidence |
|---|---|---|
| Canonical requirements прочитаны до edits | `completed` | `AGENTS.md`, `docs/requirements/index.md` и применимые owners |
| Verified stack и existing architecture | `completed` | PHP 8.5.8, Laravel 13.22, Livewire 4.3.3, SQLite, Tailwind 4.3.2 |
| Exclusive lease и exact manifest | `completed` | Начальный `task-107-title-similar-recommendations`; delivery передан живому owner `task-108-complete-seasonvar-importer` без второго checkout |
| 6 рекомендаций + следующие 6 | `completed` | Builder ограничивает выдачу 12 элементами и готовит `6 + 6`; native `<details>` раскрывает уже загруженную вторую шестёрку без запроса |
| Compact card и до трёх truthful reasons | `completed` | Query-free component выводит до трёх уникальных bounded-причин и возвращённую screen-reader group semantics |
| Двухстрочный bounded excerpt | `completed` | Server-side plain text ограничен 180 Unicode-символами и `line-clamp-2` |
| Compact «Не похоже» feedback | `completed` | Allowlisted `compact` variant отправляет `not_similar`; full discovery feedback сохранён |
| Authorization/security/admin-correction compatibility | `completed` | Existing Livewire actions, enum validation, guest/auth boundaries и admin-only correction controls не менялись |
| Query/performance improvement | `completed` | Title builder больше не выполняет subject-option query для каждой compact recommendation; related query tests зелёные |
| Mobile/accessibility/browser | `completed_with_external_regression` | Новые 6+6, three-reason, 44px, focus, feedback и overflow assertions прошли на desktop/mobile/tablet; общий guard видит прежний PWA poster `404` |
| Migrations/indexes/dependencies | `not_applicable` | Новый storage/query infrastructure не требуется |
| Documentation/README/CHANGELOG | `completed` | Canonical UI owner, visitor history, technical journal и [evidence](archive/2026-07-27-title-similar-recommendations-compact-evidence.md) обновлены |
| Commit/push `main` | `unresolved` | Ожидает final exact shared-index review и обычный push |

## Cross-feature impact

| Domain | Status | Evidence |
|---|---|---|
| Routes/API/SEO | `already_compliant` | Public title/discovery routes, API envelopes, binding и canonical contracts не менялись |
| Authentication/privacy | `already_compliant` | Guest не получает write controls; authenticated feedback остаётся server-authorized |
| Translations | `completed` | RU/EN compact labels добавлены с parity |
| Caching/search/ranking | `already_compliant` | Ranking order, recommendation DTOs, cache keys и search contracts сохранены |
| Mobile/accessibility | `completed` | Native reveal, visible focus, 44px actions, screen-reader reason group и no horizontal overflow |
| Imports/player/premium | `not_applicable` | Эти boundaries не изменены |
