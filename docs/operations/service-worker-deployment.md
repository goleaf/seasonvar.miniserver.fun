# Развёртывание PWA и service worker

Проверено: 26.07.2026.

Канонический Web App Manifest доступен по `/manifest.webmanifest`, а
единственный worker — по `/service-worker.js` со scope `/`. Worker
генерируется сервером из `resources/pwa/service-worker.js` и текущего
`public/build/manifest.json`; имя precache содержит hash совместимого набора
assets. Manifest, три локальные PNG-иконки, offline shell и Vite assets должны
разворачиваться одной совместимой единицей.

## Production prerequisites

До включения должны одновременно выполняться:

- публичный `APP_URL` использует HTTPS;
- `PWA_ENABLED=true`;
- Vite build и все иконки присутствуют;
- additive migration `2026_07_26_235900_create_web_push_subscriptions.php`
  применена;
- Web Push включён только при `PWA_PUSH_ENABLED=true`, корректной VAPID-паре,
  непустом `PWA_VAPID_SUBJECT` и асинхронной очереди;
- `php artisan app:deployment-check --json` возвращает `pass` для
  `pwa_push`.

VAPID-пару создают вне tracked-файлов:

```bash
php artisan pwa:vapid-keys
```

Команда печатает значения для secret storage. Приватный ключ, endpoint
subscription и production `.env` нельзя помещать в Git, логи или отчёты.

## Порядок rollout

1. Собрать frontend через `npm ci && npm run build`.
2. Проверить наличие `public/build/manifest.json` и размеров иконок
   `192×192`, `512×512`, `512×512 maskable`.
3. Остановить конкурентных SQLite writers по общему deployment runbook,
   создать проверяемую резервную копию и выполнить `php artisan migrate
   --force`.
4. Обновить config/route/view cache штатным production-процессом.
5. Запустить асинхронный queue worker до включения Web Push.
6. Выполнить `php artisan app:deployment-check --json`.
7. Проверить `200` и headers manifest/worker/offline shell, installability,
   online navigation и offline fallback в поддерживаемом браузере.
8. Для авторизованного тестового аккаунта проверить сохранение минимальной
   библиотеки/справки, очередь одного безопасного действия и её flush после
   возврата сети.
9. Только после этого проверить добровольную подписку, payloadless push,
   открытие `/notifications` и отзыв subscription.

Старые hashed assets нельзя удалять до завершения переключения HTML и worker:
у уже открытой вкладки может оставаться предыдущая совместимая версия.
Worker не вызывает `skipWaiting()` и не прерывает текущую вкладку
принудительной перезагрузкой.

## Cache и privacy contract

Precache ограничен offline shell, manifest, локальными иконками и Vite
assets. Runtime cache принимает только успешные same-origin изображения через
явный poster proxy и имеет bounded eviction. Worker никогда не кеширует:

- authenticated HTML, API и Livewire;
- account/settings/admin/payment/ticket/history/progress endpoints;
- CSRF, Authorization, cookies, push endpoint или subscription;
- playback grants, signed URLs, download responses;
- HLS manifests/segments, progressive video и audio.

Личная библиотека хранится в owner-scoped IndexedDB как минимальный bounded
snapshot. Logout и смена аккаунта удаляют только private scope предыдущего
владельца; публичная справка может остаться. Client snapshot не является
authorization или server truth.

## Обновление и failure recovery

- Если Vite manifest недоступен, `/service-worker.js` возвращает JavaScript
  `503` и `no-store`; установленный рабочий worker не отзывается.
- Quota/cache/IndexedDB errors не ломают online portal. Пользователь видит
  только подтверждённые сохранённые данные и может повторить действие.
- Очередь допускает только `watchlist.set` и `rating.set`, имеет UUID,
  optimistic version, TTL и лимиты. Permanent validation/authorization/
  conflict фиксируется как ошибка; transient network/server failure остаётся
  для следующей попытки.
- `404/410` push provider отключает subscription, `400` считается permanent,
  `429/5xx` и connection errors получают bounded retry.
- Недоступность push provider не блокирует создание основной database
  notification.

## Rollback

Безопасный feature rollback:

1. установить `PWA_ENABLED=false` и `PWA_PUSH_ENABLED=false`;
2. развернуть config и cleanup worker по тому же `/service-worker.js`;
3. worker удалит только cache names с префиксом `seasonvar-`, очистит
   PWA IndexedDB и отзовёт свою регистрацию;
4. оставить таблицу `web_push_subscriptions` на месте для обратной
   совместимости, пока прежний code snapshot может быть возвращён;
5. не удалять HLS/video cache — такого cache contract не существует.

Schema rollback допустим только после выключения delivery, остановки jobs и
подтверждения, что старый code не обращается к таблице. При partial migration
используется общий backup/restore runbook; `migrate:fresh`, `db:wipe`,
store-wide cache flush и удаление backlog не являются способом восстановления.
