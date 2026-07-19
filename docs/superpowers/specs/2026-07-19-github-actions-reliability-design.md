# Надёжность GitHub Actions: дизайн

Обновлено: 19.07.2026

## Цель и честная граница

Устранить все воспроизводимые причины текущих падений CI, перенести их обнаружение до commit/push и уменьшить будущий drift инфраструктуры. Абсолютная гарантия «ошибок никогда не будет» невозможна: GitHub, package registries, новые security advisories и реальные регрессии приложения остаются внешними или полезными fail-closed сигналами. Workflow не должен маскировать такие ошибки через `continue-on-error`, отключение audit/tests или фиктивный success.

## Подтверждённая причина

Последний публичный run `29567874996` на remote SHA `190d0d30` воспроизведён в чистом snapshot тем же `bash scripts/ci-check.sh backend`. Composer validate/audit, changelog policy, Pint, Rector, PHP syntax и Larastan прошли; первый отказ произошёл на `php artisan project:docs-refresh --check`: пять managed документов не соответствовали repository contracts.

Текущий pre-push уже обнаруживает такой drift, но pre-commit его не проверяет. `post-commit` пытается обновить managed Markdown после создания product commit, то есть broken commit уже может существовать и попасть на remote при пропуске локального pre-push. Это системная prevention-gap, а не случайный runner failure.

## Выбранная архитектура

1. `scripts/ci-check.sh` получает отдельный read-only профиль `docs`. Он задаёт `DB_CONNECTION=sqlite` и `DB_DATABASE=:memory:` до Laravel boot и запускает канонический `project:docs-refresh --check` без записи в project files.
2. Backend вызывает тот же `run_docs`, поэтому локальный hook и GitHub Actions не расходятся.
3. `pre-commit` запускает `bash scripts/ci-check.sh docs` после guard проверок чистоты. Из-за existing no-unstaged/no-untracked contract рабочее дерево совпадает со staged snapshot, и stale managed block блокирует commit до его создания.
4. GitHub-hosted jobs используют явный `ubuntu-24.04` вместо мигрирующего `ubuntu-latest`.
5. Существующие одобренные action majors не обновляются. Их текущие release commits закрепляются полными SHA, а комментарии сохраняют читаемую major-версию. Это исключает mutable tag drift и supply-chain подмену без package/runtime upgrade.
6. `actions/checkout` не сохраняет credentials после извлечения: workflow имеет только `contents: read` и не выполняет Git-запись.
7. Quality gate задаёт process-local maintenance driver `cache` и store `array`. Это отделяет тесты от общего `storage/framework/down`, не изменяя и не снимая реальный production marker.

## Рассмотренные варианты

- **Только исправить managed Markdown.** Закрывает один run, но оставляет причину повторения; отклонено.
- **Оставить проверку только в pre-push.** Не предотвращает broken local commits и обходится прямым push/отключённым hook; отклонено.
- **Автоматически изменять docs внутри pre-commit.** Скрытая mutation staging area усложняет review и может захватить чужие изменения; отклонено. Gate сообщает точную команду исправления.
- **Отключить strict checks или сделать их non-blocking.** Создаёт зелёный статус при реальной ошибке; запрещено.
- **Одновременно обновить action majors, Node, PHP или packages.** Нет подтверждённой причины и отдельной compatibility evidence; отложено.

## Compatibility, production и rollback

Изменение касается только development/CI workflow и документации. Public routes, Laravel runtime API, database schema/state, caches, sessions, queues, imports, search, authentication, authorization, translations, SEO, notifications, player, premium, payments, advertisements, administration, privacy, regional/legal access и service worker не меняются.

Compatibility domains классифицированы как `already_compliant` и функционально не затронуты: home; search; alphabetical catalogue; advanced filters; title detail; seasons/episodes; player; progress/history; library; collections; tags; comments; reviews; profiles; authentication; settings; release calendar; recommendations; content requests; technical tickets; help center; Premium/payments; mobile/PWA; rights-holder/legal restrictions; advertisers; administration; Seasonvar importer; production operations. Общими остаются только development documentation и CI execution; новых UI, routes, translations, cache identities, notifications, provider calls или persisted values нет.

Ubuntu 24.04 уже является поддерживаемым GitHub-hosted image; PHP 8.5, Node 26, Redis 7 и Memcached 1.6 contracts сохраняются. Action SHA соответствуют уже используемым tags `checkout v6`, `cache v5`, `setup-node v6`, `upload-artifact v7`, `setup-php v2`; dependency manifests/locks не меняются.

Миграция и backup не нужны. Rollout — обычный push workflow. Rollback возвращает предыдущие action refs/runner и удаляет `docs` profile/hook call; data restore, cache flush и worker restart не нужны. При GitHub/registry outage job честно остаётся failed и повторяется после восстановления внешней системы.

## Проверка

- TDD contract подтверждает точные SHA, отсутствие floating refs/`ubuntu-latest`, отказ от сохранения checkout credentials, общий `run_docs` и порядок pre-commit gate.
- Отдельный TDD contract подтверждает process-scoped maintenance state; воспроизведённые `503` от общего file marker исчезают без вызова `php artisan up`.
- Red state должен падать на прежнем workflow/hook; green — проходить после реализации.
- Stale snapshot должен завершать `ci-check.sh docs` ненулевым exit; после `project:docs-refresh` тот же профиль должен проходить.
- Выполняются shell syntax, focused contract, docs policies, full backend/frontend/browser gates и repository-wide scan legacy refs.
- После push проверяется новый Actions run; недоступность credentials или external service фиксируется как `unresolved`, не как success.

## Источники решения

- GitHub рекомендует закреплять third-party actions полным commit SHA как единственной immutable release-формой.
- GitHub runner-images предупреждает, что `-latest` мигрирует постепенно и может давать разные OS в пределах migration window; явный image label устраняет этот drift.
