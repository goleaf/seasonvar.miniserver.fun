# Backup and restore

Проверено: 18.07.2026.

## Verified current state

- Production database — SQLite в WAL mode; основной файл велик и изменяется importer workers.
- aaPanel хранит закрытые архивы конфигурации/сайта вне public document root, но подтверждённого актуального database dump в panel backup directory не найдено.
- Найдена историческая локальная копия SQLite, но её freshness, consistency и restore test не подтверждены. Она не считается verified production backup.
- Off-host replication, автоматическое backup alerting, approved retention и выполненная restoration rehearsal не подтверждены.

## Backup scope

| Класс | Действие |
| --- | --- |
| SQLite database | Consistent SQLite backup API/CLI при остановленных writers либо snapshot, поддерживающий SQLite WAL; копирование одного main file во время writes запрещено. |
| `storage/app/uploads` | Private non-reproducible user/ticket/profile content; сохранять вместе с metadata database. |
| Private legal/financial/advertiser files | Включать только если фактически существуют и разрешены policy; private ACL и legal hold сохраняются. |
| `.env` | Backup только в защищённом secret channel, отдельно от обычного archive; никогда не в Git/public web root. |
| Public build, caches, logs | Build/cache воспроизводимы и обычно не backup; logs сохраняются только по incident/retention policy. |
| Source snapshots/import staging | Следовать текущей retention/import policy; не считать их заменой database backup. |

## Создание database backup

1. Авторизовать операцию, проверить disk capacity и destination вне public web root.
2. Запретить новый importer dispatch и дождаться safe boundary. Для in-place high-risk migration использовать maintenance для writes.
3. Выполнить SQLite online backup командой, поддерживающей consistency, либо остановить все DB writers и копировать main database вместе с корректно checkpointed state. Пути и имена задаёт оператор; credentials в command history не помещаются.
4. Сжать и при доступности утверждённого key management зашифровать. File mode должен быть owner-only.
5. Проверить existence, non-zero size, archive readability и checksum. На отдельной копии выполнить read-only schema/table presence и bounded integrity validation.
6. Записать timestamp, source commit/schema version, size, checksum reference, destination class и verification result в private operational record. Не записывать database path/secret.
7. Возобновить один канонический importer profile и проверить heartbeat.

Exit code без проверки файла не доказывает backup success. Активную production database нельзя использовать для destructive restore test.

## Persistent-file backup

Сформировать manifest из стабильных relative paths, size и checksum; не включать symlink target outside approved disks. Архивировать private content с сохранением owner/group/permissions. Проверить manifest/архив и согласовать snapshot database и files по времени. Build assets, framework cache и временные previews исключаются, если они воспроизводимы.

## Retention

Категории: daily, weekly, monthly, release, pre-migration, legal hold, financial retention, temporary operational export. Числа дней не установлены: owner/legal/finance должны утвердить их отдельно. Нельзя удалять единственный verified backup, active legal evidence или rollback backup до завершения verification window.

## Restore

1. Классифицировать incident, подтвердить restore permission и target environment.
2. Перевести приложение в безопасное maintenance state, остановить writers/dispatch, сохранить current broken state.
3. Проверить archive/checksum/key/version compatibility на отдельном path.
4. Восстановить database во временный файл, выполнить read-only schema/integrity/expected-table checks, затем атомарно заменить database только при остановленных PHP/CLI writers; восстановить owner/group и mode.
5. Восстановить persistent files из manifest, не создавая public symlink на private disk и не разрешая script execution.
6. Установить locked dependencies и соответствующие assets, восстановить `.env` через secure channel, не меняя `APP_KEY`.
7. Проверить `storage/`/`bootstrap/cache`, rebuild compiled caches, graceful reload PHP-FPM и restart workers.
8. Сверить search/derived data, payments/webhooks, premium entitlement, importer state и service-worker version.
9. Выполнить production checklist; записать restore audit и gaps.

Полный restore rehearsal в этой задаче не выполнялся, поэтому disaster recovery остаётся документированным, но не доказанным end-to-end.
