# Design: безопасный workflow общей `main`

Дата: 26.07.2026.

## Цель

Сделать уже подготовленные границы совместной работы обязательными:

- в shared checkout одновременно существует ровно один repository-local owner lease;
- до staging владелец объявляет точный набор task paths;
- commit допускается только для полностью совпадающего объявленного набора;
- после human review фиксируется SHA-256 полного Git index, а hook повторно
  проверяет тот же снимок непосредственно перед commit;
- `docs/plans/current-task-plan.md` остаётся коротким единым реестром, а
  прежние полные тела и подтверждения сохраняются в датированном архиве.

Изменение не затрагивает Laravel runtime, HTTP contracts, БД, кеш, очереди,
frontend assets или production data.

## Подтверждённое исходное состояние

- `scripts/task-workspace-lease.sh` уже реализует атомарные `acquire`,
  `declare-paths`, `verify-paths`, `approve-index`, `verify-index`, `release`
  и explicit stale recovery.
- Lease хранится только под точным repository-local `.git` path; raw token
  существует только в process environment, на диске находится его SHA-256.
- Path manifest является NUL-delimited, canonical, mode `0600` и связан с
  task ID и digest.
- Index approval является mode-`0600` metadata с task ID, UTC timestamp и
  SHA-256 вывода `git ls-files --stage -z`.
- `scripts/check-current-plan-policy.php` уже валидирует один current-plan
  registry и archive links, но реальный 13 139-строчный план ещё не
  мигрирован, а policy ещё не подключена к docs gate.
- Hooks пока проверяют ветку, конфликты, unsafe paths, документацию и clean
  tree перед push, но не требуют owner lease, manifest или index approval.

## Рассмотренные варианты

### A. Активировать существующие standalone boundaries

Подключить существующий lease script к hooks, добавить минимальную
`verify-owner` команду для post-commit/pre-push состояния, включить
current-plan policy в единый docs gate и выполнить lossless migration старого
плана в архив.

Плюсы: минимальный новый код, максимальное повторное использование уже
проверенных границ, понятный rollback, нет новой инфраструктуры.

Риск: первый activation commit выполняется в уже загрязнённом shared checkout,
поэтому его index нужно собрать изолированно и доказать exact manifest.

### B. Создать новый orchestration tool

Один новый CLI мог бы управлять lease, staging, commit, registry и push.

Отклонено: дублирует существующие скрипты и Git, увеличивает attack surface,
создаёт второй workflow и потребует отдельного lifecycle/compatibility слоя.

### C. Ограничиться документацией

Отклонено: не предотвращает второй owner, чужой staged path или изменение
индекса после review.

## Решение

Выбран вариант A.

### Owner boundary

Hooks получают только два обязательных process-scoped значения:

```text
SEASONVAR_TASK_ID
SEASONVAR_TASK_LEASE_TOKEN
```

`verify-owner <task-id>` повторно использует существующий exact metadata/token
validator и не печатает token, digest, manifest или абсолютные пути.

### Pre-commit order

1. Проверить `main`, conflicts, staged unsafe paths и matching owner lease.
2. Только matching owner запускает существующий idempotent CHANGELOG updater.
3. Повторно проверить conflicts и staged unsafe paths, потому что updater
   является единственной разрешённой index mutation.
4. Проверить exact equality declared paths и staged path set.
5. Проверить reviewed index SHA-256.
6. Запустить docs, README и CHANGELOG policies.
7. Ещё раз проверить reviewed index SHA-256 непосредственно перед выходом
   hook, чтобы read-only gates не могли незаметно изменить commit snapshot.

Если updater впервые изменил `CHANGELOG.md`, approval закономерно устаревает:
владелец проверяет итоговый diff и повторяет `approve-index`.

### Pre-push order

Pre-push требует тот же owner lease, `main`, отсутствие conflicts, safe tracked
paths и полностью clean tree, затем запускает существующий `pre-push` profile.
Index approval после commit не требуется, потому что нормальный index пуст.
Lease освобождается только после фактического результата push.

### Current-plan registry

Существующий накопленный `docs/plans/current-task-plan.md` переносится без
потери текста в
`docs/plans/archive/2026-07-26-shared-main-workflow-evidence.md`. Archive index
фиксирует SHA-256 исходного файла, а после переноса механически нормализуются
только относительные Markdown link targets на один дополнительный уровень и
фиксируется SHA-256 итогового архива. Новый current file содержит один H1, короткие
active/blocked tables, task-specific compliance matrix и ссылки на archive.
Исторические тела не удаляются и не копируются обратно в current registry.

`scripts/ci-check.sh docs` запускает read-only current-plan policy до
`project:docs-refresh --check`, поэтому тот же contract действует локально,
в pre-commit и CI.

## Security и failure behavior

- Missing task ID/token, wrong owner, malformed metadata, symlink, missing
  declaration, path mismatch, empty/stale approval или index drift fail closed.
- Hook никогда не выводит token или digest и не читает secrets.
- Скрипт не выполняет `reset`, `restore`, `stash`, branch/worktree operations,
  recursive deletion или automatic recovery.
- `SEASONVAR_SKIP_GIT_GUARD=1` сохраняется только как уже существующая
  аварийная граница; штатная инструкция его не использует.
- При занятом lease следующий владелец ждёт handoff; `recover` разрешён только
  exact task ID и только для действительно отсутствующего PID.

## Совместимость

Сохраняются `main`, direct-to-main process, все публичные routes/API, Laravel
runtime, schema, data, cache keys, translations, frontend assets, existing
hook safety/documentation checks и ручной review staged diff.

## Rollback

Откат integration commit удаляет только новые hook-вызовы, `verify-owner`,
docs-gate invocation и новые workflow-тесты/документацию. Standalone
lease/path/index commands и архивное evidence сохраняются. Перед откатом
активный владелец штатно освобождает exact lease; source files и чужой worktree
не удаляются.
