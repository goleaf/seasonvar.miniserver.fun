# Нативный Git через repository-scoped SSH и локальная диагностика

Статус: `approved_for_implementation`.

Дата: 25.07.2026.

## Контекст и подтверждённая причина

Обычный частичный `git commit` уже восстановлен: `pre-commit` проверяет
подготовленный scope и не поглощает посторонние `unstaged`/`untracked` файлы,
а `pre-push` сохраняет обязательную чистоту рабочего дерева. Локальные
commit’ы `6045eec` и `9587cf3` находятся в `main`, но настроенная отправка
через HTTPS завершилась до передачи объектов:

```text
fatal: could not read Username for 'https://github.com': No such device or address
```

Read-only диагностика подтвердила:

- `origin` использует
  `https://github.com/goleaf/seasonvar.miniserver.fun.git`;
- Git credential helper не настроен;
- `gh` и Git Credential Manager отсутствуют;
- SSH agent недоступен, а проверка `git@github.com` получает
  `Permission denied (publickey)`;
- GitHub App connector авторизован для
  `goleaf/seasonvar.miniserver.fun` с `push`/`admin`, но эта app-сессия не
  передаёт credential обычному процессу Git.

Проблема находится в локальной transport authentication boundary, а не в
Laravel, Git hooks, GitHub ruleset или правах repository.

## Цели

1. Дать серверному checkout постоянный нативный путь
   `git commit → git push origin main` без PAT в repository, документации,
   shell history или tracked config.
2. Ограничить credential одним GitHub repository.
3. Добавить безопасную read-only диагностику Git workflow с понятными
   русскими результатами до попытки commit/push.
4. Сохранить `main`-only, staged-only `pre-commit`, strict clean-tree
   `pre-push`, secret scanning, push protection и существующий CI.
5. Доказать изменения TDD-контрактами во временных Git repositories.
6. Обновить единственных владельцев Git/CI/MCP-документации, без второго
   workflow и без фиктивной автоматизации credentials.

## Не входит в scope

- Personal access token, OAuth secret или GitHub App installation token в
  файлах проекта.
- Установка production dependency или обязательного background service.
- Изменение GitHub ruleset, Actions permissions, branch model или создание
  PR/feature/worktree branch.
- Автоматическая запись deploy key в GitHub через неподдерживаемый connector
  tool.
- Обход `pre-push`, `--no-verify`, force push или non-fast-forward update.
- Изменение Laravel routes, database schema/data, translations, cache keys,
  permissions, queues, browser behavior или visitor-facing product.

## Рассмотренные подходы

### 1. Repository-scoped SSH deploy key — выбран

Отдельная Ed25519-пара создаётся вне repository. Public key добавляется в
настройки только `goleaf/seasonvar.miniserver.fun` как deploy key с write
access. Repository-local `core.sshCommand` закрепляет exact identity через
`IdentitiesOnly=yes`; private key остаётся на сервере с режимом `0600`.

Преимущества:

- наименьшая practically доступная область доступа;
- обычный Git transport без внедрения token в URL;
- не требуется `gh`, GCM или интерактивный browser login при каждом push;
- компрометация ключа не даёт доступ к другим repositories пользователя.

Ограничение: GitHub connector текущей сессии не публикует deploy-key tool,
поэтому добавление public key в repository settings является единственным
обязательным подтверждаемым действием владельца. До него remote не
переключается и push не объявляется завершённым.

### 2. HTTPS через GitHub CLI или Git Credential Manager — отклонён

GitHub рекомендует `gh` или GCM для безопасного хранения HTTPS credentials,
но на сервере оба инструмента отсутствуют. Их установка добавляет
machine-level dependency и user-wide OAuth/PAT boundary, тогда как задача
требует доступ только к одному repository.

### 3. Публикация объектов через GitHub App connector — отклонена

Connector может создавать Git objects и перемещать remote ref, но это не
авторизует локальный `git push`. Пересоздание commit’ов через API даёт другую
локальную/remote identity, усложняет fast-forward history и превращает
connector в конкурирующий Git workflow.

## Архитектура

### Authentication boundary вне repository

Private key и `known_hosts` принадлежат user-level environment, а exact
identity выбирается только repository-local значением `core.sshCommand` в
неотслеживаемом `.git/config`; ничто из этого не попадает в tracked Git.
Для unattended repository-scoped push ключ создаётся без passphrase, но
компенсирующие границы обязательны:

- уникальная key pair только для этого repository;
- private key mode `0600`, директория SSH mode `0700`;
- public deploy key имеет write access только к одному repository;
- local `core.sshCommand` использует exact key и `IdentitiesOnly=yes`;
- remote меняется на SSH только после успешного read-only доступа;
- public/private key contents не печатаются в tracked logs, plans,
  changelog или final summary;
- при утрате контроля deploy key сначала отзывается на GitHub, затем
  удаляются exact local `core.sshCommand` и private/public files.

GitHub App connector остаётся отдельной user/app integration для repository
metadata и явно запрошенных API actions. Он не становится скрытым credential
helper локального Git.

### Read-only `scripts/git-doctor.sh`

Новый Bash-скрипт работает из любого вложенного каталога repository и имеет
два режима:

```bash
bash scripts/git-doctor.sh
bash scripts/git-doctor.sh --remote
```

Default mode не обращается к сети и проверяет:

1. запуск внутри Git repository;
2. текущую ветку `main` и отсутствие detached HEAD;
3. `core.hooksPath=.githooks`;
4. наличие, tracked state и executable bit для `pre-commit`, `pre-push`,
   `post-commit` и `lib/git-guard.sh`;
5. shell syntax versioned hooks;
6. наличие `origin` и отсутствие embedded userinfo/token в remote URL;
7. тип transport: `ssh`, `https` или unsupported;
8. наличие локального HTTPS credential mechanism как capability signal, без
   чтения credential;
9. количество staged, unstaged и untracked paths без вывода содержимого;
10. ahead/behind относительно доступного локального `origin/main`, не
    выполняя fetch;
11. наличие unresolved conflicts.

Режим `--remote` дополнительно выполняет bounded read-only
`git ls-remote origin refs/heads/main`. Он подтверждает transport
authentication и repository read access, но честно не объявляет write access:
его доказывает только обычный fast-forward push после strict `pre-push`.

Выход:

- `0` — все обязательные проверки выбранного режима прошли;
- `1` — обнаружен обязательный blocker;
- `2` — неверный CLI argument.

Каждая строка имеет стабильный уровень `[OK]`, `[WARN]` или `[FAIL]`.
Warnings не маскируют blockers. Скрипт не выполняет `git add`, commit, push,
fetch, reset, stash, checkout, key generation или config mutation.

### Composer interface

В `composer.json` добавляется:

```json
"git:doctor": "bash scripts/git-doctor.sh"
```

`composer hooks:install` остаётся единственным mutation command для
`core.hooksPath`. Диагностика не объединяется с установкой hooks, чтобы
read-only проверка не меняла Git config.

### Native publish flow

После однократной user-level SSH настройки рабочий поток остаётся нативным:

```bash
composer git:doctor
git add -- <точные пути>
git commit -m "<сообщение>"
composer git:doctor
git push origin main
```

Отдельный `git-publish` wrapper не создаётся. Порядок и security checks уже
принадлежат versioned hooks; второй wrapper дублировал бы Git transport и
создал новый путь обхода.

## Ошибки и безопасные сообщения

- HTTPS без helper: `[FAIL]` с рекомендацией настроить SSH либо официальный
  `gh`/GCM; token не запрашивается и не принимается аргументом.
- SSH remote без доступа: `[FAIL]` только с безопасным stderr summary без
  private key path, key body или environment dump.
- Missing/non-executable hook: `[FAIL]` и точная безопасная команда
  `composer hooks:install`; doctor не исправляет файл сам.
- Dirty tree: default doctor показывает counts. Это warning для partial
  commit и blocker для publish readiness; `pre-push` остаётся окончательной
  strict boundary.
- Отсутствующий локальный `origin/main`: `[WARN]`, потому что doctor не
  выполняет fetch автоматически.
- Remote URL с userinfo: `[FAIL]`; URL целиком не повторяется в выводе.
- Network/provider outage в `--remote`: `[FAIL]` без заявления, что
  credentials неверны, если Git не дал однозначный authentication diagnostic.

## TDD и verification

### RED

Новый `tests/Unit/GitWorkflowDoctorTest.php` сначала задаёт ожидаемый CLI
contract, которого нет:

- clean `main` repository с установленными executable hooks проходит;
- missing hook и неверный `core.hooksPath` дают exit `1`;
- branch не `main` даёт exit `1`;
- partial dirty state сообщает counts, но не раскрывает content;
- embedded credential remote отклоняется без echo URL;
- неизвестный argument даёт exit `2`;
- default mode не вызывает network.

`tests/Unit/CiQualityGateContractTest.php` получает contract на
`composer git:doctor`, versioned executable script и сохранённый порядок
hooks.

Первый focused run обязан упасть из-за отсутствующего
`scripts/git-doctor.sh`/Composer alias, а не из-за test fixture.

### GREEN

После минимального Bash implementation выполняются:

```bash
php artisan test tests/Unit/GitWorkflowDoctorTest.php
php artisan test tests/Unit/CiQualityGateContractTest.php
bash -n scripts/git-doctor.sh .githooks/pre-commit .githooks/pre-push \
  .githooks/post-commit .githooks/lib/git-guard.sh
composer git:doctor
```

Remote verification запускается только после добавления public deploy key:

```bash
bash scripts/git-doctor.sh --remote
git push origin main
git ls-remote origin refs/heads/main
```

Push считается завершённым, только если remote SHA совпадает с локальным
`main` и GitHub ruleset принимает fast-forward update.

## Документация и план

Изменяются единственные владельцы:

- `docs/development.md` — установка, doctor, SSH lifecycle и publish flow;
- `docs/ci.md` — связь local doctor с неизменённым CI/pre-push;
- `docs/mcp.md` — GitHub App не является Git CLI credential helper;
- `README.md` — краткий Git quick-start без visitor-history записи;
- `CHANGELOG.md` — русская техническая запись;
- `docs/plans/current-task-plan.md` — Task 55 compliance/evidence;
- новый полный implementation plan в `docs/superpowers/plans`.

Visitor product не меняется, поэтому новая датированная запись в разделе
`История обновлений для посетителей` не создаётся.

## Compatibility и cross-feature impact

| Domain | Решение |
| --- | --- |
| Git commit | Сохраняется уже проверенный partial-commit contract |
| Git push | Меняется только user-level transport authentication; strict `pre-push` сохраняется |
| GitHub | Ruleset, Actions permissions, secret scanning и push protection не меняются |
| MCP/connectors | GitHub App остаётся отдельным connector, не credential bridge |
| Secrets/privacy | Private key только вне repository; содержимое не логируется и не документируется |
| CI | Workflow и pinned actions не меняются |
| Laravel/product | Не затрагиваются |
| Routes/API/schema/data | Не затрагиваются |
| Translations/cache/permissions/queues | Не затрагиваются |
| Browser/mobile/SEO | Не затрагиваются |
| Production runtime | Application runtime не меняется; только developer Git transport |

## Rollout

1. Реализовать doctor по TDD и зафиксировать repository changes в `main`.
2. Создать repository-specific Ed25519 pair вне checkout.
3. Передать владельцу только public key для добавления как write deploy key.
4. После подтверждения проверить SSH authentication и exact repository.
5. Сохранить repository-local `core.sshCommand` с exact identity.
6. Переключить `origin` на SSH.
7. Запустить local doctor, focused/wide checks и strict pre-push.
8. Выполнить обычный fast-forward `git push origin main`.
9. Сверить local/remote SHA и GitHub Actions state.

## Rollback

До успешной SSH-проверки HTTPS remote не меняется. После переключения:

1. вернуть `origin` на документированный HTTPS URL;
2. подтвердить read-only `git ls-remote`;
3. отозвать deploy key в GitHub;
4. удалить только exact repository-local `core.sshCommand` и key pair;
5. не трогать другие SSH identities, credentials или repositories;
6. откатить doctor/Composer/docs обычным новым commit’ом в `main`, без
   переписывания истории.

Database restore, cache invalidation, asset rebuild, queue restart,
maintenance mode и application rollback не требуются.

## Acceptance criteria

- Partial commit продолжает проходить при unrelated dirty files.
- `composer git:doctor` даёт deterministic local result без network.
- `--remote` подтверждает SSH-доступ без раскрытия secrets.
- Remote не содержит embedded credentials.
- `origin` переключён только после добавления и проверки deploy key.
- Обычный `git push origin main` проходит strict pre-push и fast-forward
  ruleset.
- Local `main`, `origin/main` и GitHub ref совпадают.
- Все repository changes имеют тесты, русскую документацию, compliance
  evidence, commit и подтверждённую отправку либо честный `unresolved`.

## Источники

- GitHub Docs, SSH:
  <https://docs.github.com/en/authentication/connecting-to-github-with-ssh/about-ssh>
- GitHub Docs, deploy keys:
  <https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys>
- GitHub Docs, remote URL:
  <https://docs.github.com/en/get-started/git-basics/managing-remote-repositories>
- GitHub Docs, HTTPS credential storage:
  <https://docs.github.com/en/get-started/git-basics/caching-your-github-credentials-in-git>
