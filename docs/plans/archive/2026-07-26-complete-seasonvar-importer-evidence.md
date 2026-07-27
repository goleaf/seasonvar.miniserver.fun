# Task 108 — evidence завершения импортёра Seasonvar

## Результат

Программа завершения импортёра реализована через существующую публичную
команду `php artisan seasonvar:import`, текущие queue names, scalar job
constructors и catalog identity. Сезоны и серии остаются внутри одного
`CatalogTitle`; полные видеофайлы не загружаются и не сохраняются.

## Реализованные границы

- Bounded dispatcher регистрирует страницы bulk-пакетами и держит профиль
  100 страниц не выше 120 SQL queries.
- Prepared ledger, claim token, CAS и run/group counters обеспечивают
  идемпотентное продолжение после повторной доставки.
- Global finalization хранит versioned durable stage и после interruption не
  повторяет уже завершённое обслуживание.
- Compact payload storage имеет versioned legacy fallback и disabled-first
  writer switch; исходный public queue payload не менялся.
- File-size schedule projection имеет additive indexed schema, observer,
  bounded rebuild и fallback; direct-file metadata по-прежнему читается
  только через HEAD или минимальный Range.
- Parser facade разделён на structured-data, episode-script, media-candidate
  и taxonomy collaborators. Fixture corpus фиксирует fingerprints,
  provenance и complete/partial convergence.
- Catalog writer, media synchronizer и maintenance pipeline разделяют
  catalog write, media identity и terminal maintenance без изменения public
  methods.
- Admin UI и CLI используют один read model: выбранный run, его exact claims,
  transport/queue, worker profile, durable phase и прогресс. Targeted title
  refresh больше не выглядит как зависший общий импорт.
- Sitemap gzip/XML decoding ограничен по compressed/decompressed size,
  recursion и entry count; parser collections и nesting также имеют hard
  bounds.

## Production recovery

Production preflight подтвердил отсутствие maintenance mode и активного
import backlog. На короткое migration window были остановлены только четыре
import и восемь title-refresh workers. Перед schema change создана закрытая
SQLite backup, проверенная через `quick_check` и `foreign_key_check`.

Additive migrations compact payload и file-size projection применены
успешно. После повторных integrity checks те же workers восстановлены.
Проблемный тайтл повторно обновлён; три последовательных targeted runs
завершились без failed pages/groups. Точный приватный путь backup и checksum
намеренно не помещены в repository.

Rollback сохраняет additive columns/tables и сначала возвращает предыдущий
код с legacy fallback и выключенными writer/projection switches. Destructive
down migration не является штатным production rollback; при data incident
используется проверенная backup по закрытому operations runbook.

## Verification evidence

- Importer regression: `164 tests`, `977 assertions`.
- Failure/load/parser/storage matrix: `63 tests`, `497 assertions`.
- Итоговый Seasonvar slice после delivery follow-up: `376 tests`,
  `2344 assertions` для importer, LicensedMedia, live-refresh, CLI и
  admin-status contracts.
- Parser equivalence: `25 tests`, `123 assertions`, включая 2600 episodes.
- Merge profile: 30 seasons, 930 episodes, 957 media — 2708 queries при
  ceiling 5000.
- Media identity: 1000 неизменённых media — не более 20 identity queries.
- Changed-scope PHPStan: без ошибок.
- Full PHPStan: без ошибок.
- Locked Composer и npm audits не нашли известных уязвимостей; Vite build и
  player release check прошли.
- Isolated Playwright admin importer: desktop/mobile/tablet `3/3`, HTTP 200,
  no overflow/console/page/local response errors, scoped axe без
  serious/critical violations. Idle run показывает dispatch «нет данных».
- Full shared-tree PHPUnit: `2241 passed`, `18 failed`, `11 skipped`.
  Failures относятся к соседним незавершённым UI/offline/rate-limit/session
  workstreams; Seasonvar slice остаётся полностью зелёным.

Post-restart canary после activation нового worker code создал run `1375`:
8 из 8 страниц подготовлены и применены, group/run `completed`, failed `0`,
live claims `0`. Оба worker profiles сообщили fresh `ok` heartbeat и пустой
transport. Public home/title вернули HTTP 200, `/health/ready` —
`ready=true`, текст «Обновляем данные» на проверенной карточке отсутствовал.

## Unresolved

- Foreign staged Task 107 index не принадлежит importer scope и не может
  быть изменён или включён в commit. Exact commit/push выполняется только
  после освобождения общего index; Git doctor дополнительно не обнаружил
  credential helper для configured HTTPS remote.
- Общий read-only `app:health` остаётся `degraded` из-за Memcached и full
  cache warming вне importer scope; database, Redis, import queues/workers и
  публичная readiness-проверка исправной границы импортёра прошли.
- Optional provider parity не активируется без отдельного legal/source
  authority и не относится к исправлению текущего отказа.
