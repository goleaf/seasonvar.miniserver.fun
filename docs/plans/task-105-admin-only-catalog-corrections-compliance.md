# Task 105 compliance — admin-only catalog corrections

| Требование | Статус | Evidence |
|---|---|---|
| Root/requirements/docs прочитаны до изменений | `completed` | Read-only discovery; owners перечислены в implementation plan. |
| Версии и фактический stack проверены | `completed` | PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, SQLite, Tailwind 4.3.2, Vite 8. |
| Работа только в существующей `main` | `completed` | `git status --short --branch`, baseline HEAD `6d7d30ed`. |
| Exclusive lease и declared paths | `completed` | `task-105-admin-only-catalog-corrections`, NUL manifest после handoff Task 102. |
| Канонический owner изменён до реализации | `completed` | `docs/catalog-quality.md`, раздел «Полевые предложения исправлений». |
| Public control полностью удалён | `completed` | Builder/player URL preparation и Blade/component удалены; HTTP + Playwright 3/3 не находят text/attributes. |
| Admin-only authorization не доверяет frontend | `completed` | Enum/policy/form/action reauthorization; direct URL, forged state/action, revoked-permission idempotency и отсутствующий `correction_target_key` покрыты тестами. |
| Historical rows fail-closed | `completed` | Type-aware policy, route binding, `publiclyVisible`, My Requests, SEO/presenter/notifications; legacy `is_public = 1` regression test. |
| Admin moderation остаётся работоспособной | `completed` | Admin create/detail/queue, approved/rejected transitions и target/reason identity проходят feature tests. |
| Help-center не ведёт в закрытый workflow | `completed` | Config allowlist, fail-closed service и guarded reversible migration с up/down/forward evidence. |
| Cache/deploy/rollback/data safety оценены | `completed` | Title/request page contracts, search format `3`, API/document `ETag` contract `2`, guarded migration и rollback; pre-change payload/validators покрыты тестами. |
| Новая schema/index/dependency | `not_applicable` | Колонка/package не нужны; индекс только по `EXPLAIN` evidence. |
| Security/privacy review | `completed` | Server Gate сохраняется на всех entry points; XSS/IDOR/revoked-access/private notification tests зелёные; CSRF остаётся Livewire POST contract. |
| Performance/query review | `completed` | Public link-building удалён; SQLite использует existing public/status index, bounded 20-row page не обосновывает новый write-cost index. |
| README/CHANGELOG/тематические docs | `completed` | README visitor contract/history, русский CHANGELOG и 13 owner/operations documents обновлены. |
| Focused/broad/full/browser/build/migration verification | `completed` | [Archive evidence](archive/2026-07-26-admin-only-catalog-corrections-evidence.md); task suites/build/browser/migration зелёные, foreign full baseline честно отделён. |
| Senior code review | `completed` | Reviewer: 0 Critical, 2 Important; обе исправлены через RED/GREEN 33/33, 135 assertions. |
| Repository delivery safeguards | `completed` | Смешанный commit удалил tracked hooks/CI; exact canonical SHA-256 восстановлены, `bash -n` и 120-test policy matrix прошли. |
| Exact index/commit/release | `completed` | 20-path manifest/index approved; follow-up `27d516d8` создан в `main`; lease освобождается после closure commit/final push attempt. |
| Ordinary push | `unresolved` | `GIT_TERMINAL_PROMPT=0 git push origin main` не получил HTTPS Username; local commits сохранены, history/remote не переписывались. |
