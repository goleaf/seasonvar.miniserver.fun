# План надёжности непрерывного импорта Seasonvar

> Выполнять по TDD: сначала наблюдать падение нового focused test, затем вносить минимальное production-изменение.

**Цель:** безопасно завершать устаревшие refresh jobs, согласовать retry deadline с page claim и добавить постоянный однопоточный systemd-профиль.

**Стек:** PHP 8.5, Laravel 13.19, PHPUnit 12.5, systemd.

## Задача 1. Удалённый CatalogTitle как успешный no-op

**Файлы:**

- Изменить: `tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php`
- Изменить: `app/Jobs/RefreshSeasonvarCatalogTitle.php`

1. Добавить тест: записать queued state, удалить тайтл, выполнить job, подтвердить empty state и отсутствие dispatch jobs.
2. Запустить focused test и подтвердить исходное падение на `ModelNotFoundException`.
3. Заменить `findOrFail()` на `find()`; при `null` вызвать `forget()` и вернуть управление.
4. Повторить focused test.

## Задача 2. Retry deadline не короче claim lease

**Файлы:**

- Изменить: `tests/Feature/SeasonvarParallelImportTest.php`
- Изменить: `app/Jobs/PrepareSeasonvarImportTitlePage.php`
- Изменить: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`

1. Добавить claim-bound и retry-bound assertions для обоих jobs.
2. Запустить focused test и подтвердить падение при `claim_seconds > retry_window_seconds`.
3. Рассчитать общее окно через `max(300, retry, claim)`; сохранить finalizer unique grace 300 секунд.
4. Повторить focused test.

## Задача 3. Persistent single-thread profile

**Файлы:**

- Создать: `deploy/systemd/seasonvar-import-forever.service`
- Изменить: `tests/Unit/TitleBackgroundRefreshDocumentationTest.php`
- Изменить: `README.md`
- Изменить: `docs/deployment.md`
- Изменить: `docs/importer.md`
- Изменить: `docs/queues.md`
- Изменить: `CHANGELOG.md`

1. Добавить failing contract test systemd unit.
2. Создать unit с единственным `seasonvar:import --forever`, `Restart=always` и без `queue:work`.
3. Описать установку, проверку и обязательное отключение queued workers/cron при выборе последовательного профиля.
4. Повторить unit test.

## Задача 4. Финальная проверка

1. Запустить `./vendor/bin/pint --dirty --format agent`.
2. Запустить focused importer/unit tests.
3. Запустить `php artisan project:docs-refresh --check`.
4. Запустить `php artisan test`.
5. Проверить `git diff --check`, status и закоммитить разрешённые изменения в `main`.
