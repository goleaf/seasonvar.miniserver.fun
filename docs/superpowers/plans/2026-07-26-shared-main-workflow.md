# Task 102: безопасный совместный workflow общей `main`

> Выполнять последовательно в существующей `main`. Один владелец:
> `task-102-shared-main-workflow`. Другие ветки, worktrees и subagents не
> используются.

## Этап 1 — анализ и ownership

- [x] **Critical — прочитать requirements и фактический stack.**
  Что: перечитать `AGENTS.md`, requirements index/owners, Markdown по
  development/maintenance/production/integration, Composer/npm manifests,
  hooks, scripts и тесты. Почему: решения зависят от реального contract.
  Файлы: read-only repository inventory. Зависимости: нет. Риск: принять
  исторический план за реализацию. Проверка: версии и `git status` записаны в
  current compliance matrix.
- [x] **Critical — снять Git baseline.**
  Что: записать `main`, HEAD, upstream, status и shared index tree.
  Почему: нельзя присвоить pre-existing staged/unstaged paths. Файлы: `.git`
  read-only. Риск: конкурентное изменение после снимка. Проверка: повторять
  status/tree перед staging и commit.
- [x] **Critical — получить один lease и объявить total scope до edits.**
  Что: `acquire task-102-shared-main-workflow`, затем NUL-safe
  `declare-paths` для 19 путей. Почему: исключить второго owner и scope creep.
  Файлы: exact repository-local lease metadata под `.git`. Зависимости:
  живой owner shell. Риск: потеря raw token. Проверка: safe `status` показывает
  task ID, `paths_declared=yes`, `index_approved=no`.

## Этап 2 — design, plan и evidence

- [x] **High — выбрать минимальную интеграцию.**
  Что: сравнить activation существующих boundaries, новый monolithic tool и
  docs-only вариант. Почему: избежать второй архитектуры. Файлы: design.
  Зависимости: аудит Tasks 30/32/33/34. Риск: ослабить старые guards.
  Проверка: design сохраняет каждую текущую safety gate.
- [x] **Critical — сохранить старый current plan без потерь.**
  Что: создать lossless dated archive snapshot, сохранить source hash,
  нормализовать только относительные link targets для нового уровня и
  зафиксировать archive hash. Почему: новый реестр должен быть коротким, но история не удаляется. Файлы:
  `docs/plans/current-task-plan.md`, `docs/plans/archive/*`. Зависимости:
  снятый pre-edit hash. Риск: потерять concurrent plan evidence. Проверка:
  source/archive SHA-256 и полный managed-link gate.
- [x] **High — создать живой plan/compliance matrix.**
  Что: заменить current body одним реестром и task-specific matrix. Почему:
  выполнить canonical workflow до production-code edits. Файлы: current plan.
  Зависимости: archive snapshot. Риск: broken links/status. Проверка:
  current-plan policy.

## Этап 3 — TDD

- [x] **Critical — написать RED для owner hook boundary.**
  Что: тесты missing/wrong/matching owner, отсутствие утечки token и отсутствие
  index/worktree mutations. Почему: новая команда security-sensitive. Файлы:
  `GitWorkspaceLeaseScriptTest.php`. Зависимости: существующий temp-repo
  harness. Риск: тест затронет real `.git`. Проверка: все случаи используют
  только temp repository.
- [x] **Critical — написать RED hook-order contracts.**
  Что: доказать updater-before-approval, owner/path/index-before-docs,
  final index recheck и owner requirement в pre-push. Почему: порядок является
  частью целостности snapshot. Файлы: `CiQualityGateContractTest.php`.
  Зависимости: hook text/temp repo. Риск: brittle assertion. Проверка:
  behavioral temp-repo cases плюс минимальные position assertions.
- [x] **High — обновить current-plan RED/GREEN contract.**
  Что: repository current plan теперь обязан проходить, а docs gate обязан
  запускать policy до managed docs. Почему: standalone parser становится
  acceptance gate. Файлы: `CurrentPlanPolicyScriptTest.php`,
  `CiQualityGateContractTest.php`. Риск: archive migration неполна. Проверка:
  focused tests.

## Этап 4 — implementation

- [x] **Critical — добавить `verify-owner`.**
  Что: переиспользовать `require_matching_owner`, обновить usage/dispatch.
  Почему: pre-push после commit не имеет непустого index. Файлы:
  `scripts/task-workspace-lease.sh`. Зависимости: active lease/token.
  Риски: лишний вывод или ослабление validation. Проверка: temp-repo tests,
  shell syntax.
- [x] **Critical — добавить Git guard helpers.**
  Что: валидировать required env и вызывать exact lease commands.
  Почему: не дублировать security parsing в hooks. Файлы:
  `.githooks/lib/git-guard.sh`. Зависимости: `verify-owner`, существующие
  verify commands. Риск: command/env injection. Проверка: task ID валидирует
  lease script, аргументы quoted.
- [x] **Critical — интегрировать pre-commit.**
  Что: owner/path/index checks после updater и final recheck после policies.
  Почему: commit tree должен совпасть с human-reviewed index. Файлы:
  `.githooks/pre-commit`. Зависимости: declared manifest и approval.
  Риск: updater создаёт expected first-attempt failure. Проверка: тест и
  documented retry sequence.
- [x] **High — интегрировать pre-push.**
  Что: потребовать owner до safe tracked/clean-tree/full gate. Почему: lease
  живёт до фактической доставки. Файлы: `.githooks/pre-push`. Зависимости:
  matching token. Риск: foreign dirty tree честно блокирует push. Проверка:
  hook contract и фактическая попытка push.
- [x] **High — активировать current-plan docs gate.**
  Что: запустить policy первой в `run_docs()`. Почему: одинаковый local/hook/CI
  contract. Файлы: `scripts/ci-check.sh`. Зависимости: migrated current plan.
  Риск: hidden invalid archive link. Проверка: focused + docs profile.

## Этап 5 — документация и постоянные правила

- [x] **Critical — обновить canonical owner `docs/development.md`.**
  Что: описать acquire → declare → edit → stage → updater/review → approve →
  commit → push → release, token handling, failures и rollback. Почему:
  permanent workflow сначала принадлежит одному canonical owner. Риск:
  создать второй Git workflow. Проверка: docs map указывает только на owner.
- [x] **High — синхронизировать agent constraint.**
  Что: кратко обязать lease/manifest/snapshot в `AGENTS.md`, не копируя
  подробный runbook. Почему: агенты должны выполнять owner boundary до edits.
  Риск: дублирование. Проверка: детали остаются только в development doc.
- [x] **Medium — обновить docs map, system master, README и CHANGELOG.**
  Что: зафиксировать activation/status и developer-facing change. Почему:
  source-of-truth и техническая история должны совпадать. Риски: смешать
  visitor/product copy с внутренним workflow, захватить foreign hunks.
  Проверка: scoped diff, README/CHANGELOG policies.

## Этап 6 — verification, delivery и rollback

- [x] **Critical — focused GREEN.**
  Команды: lease/current-plan/CI contract tests, `bash -n`, `php -l`.
  Риск: ложный GREEN из-за real shared repository. Проверка: temp fixtures и
  read-only repository policy.
- [x] **High — style/docs/broad relevant checks.**
  Команды: Pint dirty, docs-refresh check, docs profile, Unit suite,
  `git diff --check`. Риск: foreign application failures. Проверка: отделить
  scoped failure от pre-existing blockers без `|| true`.

  Результат: focused-набор прошёл 84 теста / 453 утверждения; Pint, PHP syntax,
  shell syntax, current-plan policy, временно изолированный docs profile и
  PHPStan прошли. Unit suite выполнил 574 теста: 573 прошли, один чужой PWA
  Blade contract отказал. Полный suite остановлен PHP memory exhaustion в
  чужом PWA middleware/`ExternalPlaylistImportTest`. Rector показал только
  три `never`-сигнатуры в двух чужих collection-классах. Обычный managed-doc
  check ожидаемо видит чужую незакоммиченную PWA migration; временный refresh
  прошёл и `docs/MAINTENANCE_LOG.md` восстановлен с исходным SHA-256.
- [ ] **Critical — exact isolated staging и approval.**
  Что: собрать index только из declared Task 102 paths, запустить changelog
  updater, проверить cached diff/secrets, `verify-paths`, `approve-index`,
  `verify-index`. Почему: shared real index уже содержит foreign entries.
  Риск: случайно stage foreign hunk. Проверка: path equality и reviewed tree.

  Во время staging обнаружены два внешних commit в общей `main`: `6660dac` и
  `8f12e95`. Их пересекающиеся documentation paths сохранены, а Task 102 index
  сверяется относительно нового `HEAD`. После одной диагностической команды,
  ошибочно использовавшей shared index, он немедленно восстановлен в точное
  pre-task дерево `b6703cc571906542b69cd394eab7d89af1b1659e`;
  worktree не изменялся. Дальнейшие операции используют явный
  `GIT_INDEX_FILE`. Для прохождения уже обязательной CHANGELOG policy в
  staged-варианте от актуального `HEAD` механически нормализовано только
  оформление технических терминов трёх ранее commit-backed записей; их факты
  и пункты сохранены, а foreign working hunks не включены.
- [ ] **Critical — commit только в `main`.**
  Что: commit exact approved snapshot с точным subject. Почему: завершить
  логическую activation. Риск: hook drift. Проверка: hook и commit tree.
- [ ] **Critical — обычный push и factual evidence.**
  Что: push current `main` без force/bypass, затем release. Почему: завершить
  delivery либо честно записать external blocker. Риски: foreign dirty
  pre-push, authentication, remote divergence. Проверка: фактический stdout,
  stderr, branch и commit hash.

## Не применимо

- База данных, migrations, Eloquent/SQL/EXPLAIN, backend request validation,
  policies/gates, routes/API, Blade/Livewire/Vue, frontend build и browser UX:
  задача меняет только repository tooling и документацию.
- Production rollout, backup/data migration, cache invalidation, queue restart
  и dependency installation: runtime/dependencies/data не меняются.
