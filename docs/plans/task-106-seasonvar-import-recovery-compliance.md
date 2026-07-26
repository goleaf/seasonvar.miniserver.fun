# Task 106 — compliance matrix

| Требование | Статус | Evidence |
|---|---|---|
| `AGENTS.md`, requirement index и применимые canonical owners прочитаны до edits | completed | `AGENTS.md`, `docs/requirements/index.md`, production/maintenance/system integration owners |
| Все ссылки requirement index существуют | completed | repository link audit до планирования |
| Importer, queue, data, provider, deployment, backup и rollback docs прочитаны | completed | `docs/importer.md`, `docs/queues.md`, `docs/DATA_RELATIONS.md`, `docs/SOURCE_PARITY.md`, `docs/operations/*` |
| Framework/runtime/packages проверены фактически | completed | PHP 8.5.8, Laravel 13.22.0, Boost 2.4.13, PHPUnit 12.5.32, SQLite |
| Laravel version-dependent behavior проверено официальным источником | completed | Laravel Boost: migrations, isolated migration lock, queue restart, PHPUnit execution |
| Existing implementation и production failure исследованы до замены | completed | status/DB/process/systemd/journal/migration evidence в current plan |
| Exclusive `main` lease и exact task paths | completed | `task-106-seasonvar-import-recovery`, 17 declared paths после documented owner-doc discovery |
| Interrupted foreign task сохранена и не смешивается | completed | `archive/2026-07-26-title-similar-recommendations-interrupted-evidence.md`; foreign paths остаются unstaged |
| Мёртвый sync-run без cache lock восстанавливается безопасно | completed | exact RED: 3/3 ожидаемо failed без новых call sites; GREEN: 3/3, 17 assertions |
| Живой sync-процесс не закрывается новым cron/CLI | completed | GREEN подтвердил сохранение live run и отсутствие второго lifecycle row |
| `--queued` cron самостоятельно освобождает global lifecycle от мёртвого sync-run | completed | GREEN regression; после terminal sync `#1289` production `--queued` создал новый lifecycle `#1294`, значит прежняя sync boundary его не заблокировала |
| PHPUnit не создаёт production daily log | completed | exact focused test: 1/1, 1 assertion; `LOG_CHANNEL=null` принудительно задан в `phpunit.xml` |
| Production import scheduler имеет только один активный producer profile и не запускает Laravel от `root` | completed | aaPanel sync `status=0`, body/script fail-safe `www`, root entry отсутствует, один canonical `www --queued` cron; 13 worker processes и daily log принадлежат `www` |
| Additive schema совместима с deployed importer | completed | verified SQLite backup; три точные migrations имеют status `Ran`; backup `quick_check=ok`, foreign-key violations отсутствуют |
| Queue jobs/claims/failed rows сохраняются | completed | после прерванного окна pending/delayed/reserved rows не очищались и не повторялись массово; exact before-state сохранён для worker recovery |
| Новые episodes/media доходят до публичного каталога | completed | 14 title groups `completed`, 30/30 pages applied, 0 failed; 8 title routes и home дали HTTP 200 |
| Cache/search/recommendation finalization | unresolved | targeted invalidation и Redis refresh states `completed`; пустой global run `#1294` ждёт canonical stale recovery, поэтому его global maintenance не подтверждён |
| Auth/policies/API/routes/translations/premium/region/legal contracts | already_compliant | код этих boundaries и public contracts не меняется; повторная проверка перед final |
| Dependencies/build tooling/assets | not_applicable | dependency, lock, JS/CSS и build changes отсутствуют |
| Documentation, `README.md`, `CHANGELOG.md` | completed | importer/queues/deployment/development owners, visitor history, русский changelog и archived evidence обновлены |
| Legacy/duplicate/stale implementation search | completed | `schedule:work` доказанно относится к другому checkout; environment/deployment и `TD-015` исправлены, root sync producer отсутствует |
| Focused и широкая verification | unresolved | focused 88/88, 591 assertions GREEN; wide Seasonvar 302/304 с двумя сохранёнными baseline blockers вне Task 106 |
| Commit только в `main` и push | unresolved | foreign Task 107 worktree может блокировать clean-tree pre-push |
