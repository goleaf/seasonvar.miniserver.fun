# Production rollback runbook

Проверено: 18.07.2026. Текущий сервер использует in-place checkout; атомарный release switch не подтверждён. Откат Git сам по себе не восстанавливает schema, backfill, persistent files, `.env`, Composer dependencies, assets, cache или service worker.

## До deployment

1. Записать intended commit, предыдущий known-good commit, hash `composer.lock`, `package-lock.json` и `public/build/manifest.json`.
2. Проверить clean `main`, приватный согласованный backup базы и persistent files, его размер/читабельность и доступность ключа шифрования без вывода значения.
3. Классифицировать migrations и определить, совместим ли previous code с новой schema. Для forward-only изменений выбрать forward-fix или восстановление backup, а не `migrate:rollback` вслепую.
4. Сохранить server-only `.env`; не копировать его в release, Git, лог или incident report.
5. Определить maintenance decision. Billing webhook нельзя блокировать без плана provider reconciliation.

## Триггеры

- приложение не загружается, manifest/assets отсутствуют, login/session нарушены;
- migration завершилась частично или новая schema несовместима;
- authorization, premium, region, legal или advertiser exclusion стали fail-open;
- playback source boundary или payment/webhook integrity нарушены;
- после bounded cache/runtime refresh проблема не устранена.

## Порядок

1. Остановить дальнейший deployment и назначить operational owner role.
2. Сохранить redacted logs, commit/build identity, migration status и текущее состояние; не удалять evidence.
3. Перевести пользовательские write routes в maintenance, если это безопаснее. Остановить новые importer dispatches и дождаться безопасной job boundary; не очищать queue/backlog.
4. Вернуть previous code и соответствующие lock-built `vendor/`/`public/build/` artifacts. Не смешивать прежний manifest с новыми chunks.
5. Если schema совместима, не восстанавливать database. Если несовместима и forward-fix невозможен, отдельно авторизовать restore проверенного pre-migration backup с сохранением текущего повреждённого состояния.
6. Вернуть server-only config только через protected operations. Не менять `APP_KEY`.
7. Перестроить config/routes/views/events caches только при успешном boot; очистить лишь несовместимые feature caches. Не flush Redis целиком.
8. Graceful reload фактического PHP-FPM unit и `php artisan queue:restart`; process manager должен вернуть все workers.
9. Если service worker появится в будущем, активировать предыдущую cache version и сохранить private-route denylist. Сейчас service worker не установлен.
10. Выполнить [`production-checklist.md`](production-checklist.md), затем вывести из maintenance и наблюдать DB/WAL, disk, logs, queues и provider reconciliation.
11. Зафиксировать rollback event, причину, affected data, verification и follow-up без secrets.

## Ограничения

- In-place rollback не является zero-downtime.
- Unverified database copy нельзя использовать как основание заявления о recoverability.
- Pending serialized jobs могут требовать прежние классы: перед удалением/переименованием job обязателен compatibility adapter.
- Provider-side operation не откатывается локальным commit: webhook/payment/mail/source состояние сверяется отдельно.
