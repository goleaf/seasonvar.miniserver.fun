# Демонстрационные пользовательские данные, private cache и изображения

Дата: 19.07.2026

## Цель

Сделать повторяемое демонстрационное наполнение заявок, личной библиотеки и личных тегов для ста известных demo-аккаунтов, восстановить показываемые обложки коллекций и профилей, нормализовать новые avatar/cover в WebP нужного дизайну размера и добавить безопасный owner-scoped cache для страниц личного кабинета.

## Подтверждённые причины

- `DemoContentRequestStage` уже существует, но текущий production-корпус содержит `0` заявок: этот этап не был выполнен для уже созданного набора.
- `DemoRasterAsset` записывает profile/collection файлы под `demo-data/...`, тогда как responders допускают только `user-profiles/{public_id}/{kind}/...` и `catalog-collections/{public_id}/...`; URL формируется, но доставка закономерно отвечает `404`.
- Profile upload сохраняет исходный JPEG/PNG/WebP без derivative pipeline, поэтому формат, metadata и итоговые размеры не соответствуют новому требованию.
- `/library` и owner profile pages всегда пересчитывают агрегаты и ID pagination из БД. Authenticated HTML корректно остаётся `private, no-store`, поэтому full-response/browser cache для него недопустим.

## Решение

### Demo corpus

Единственным владельцем остаётся `DemoDataOrchestrator`. Существующие stages дополняются проверяемыми контрактами, но не создаётся второй seeder. Известные `user1@example.com` … `user100@example.com` получают:

- 3–10 заявок с существующим enum/status coverage;
- заполненные состояния личной библиотеки и историю из текущего `DemoCatalogActivityStage`;
- 12–40 личных тегов и назначения из текущего `DemoOrganizationStage`;
- avatar 320×320 WebP и cover 1280×360 WebP по разрешённому profile path;
- коллекционные WebP 960×540 по разрешённому collection path.

Seed остаётся идемпотентным, deterministic и запрещённым вне `dev|testing`. Для уже существующего production demo-корпуса вводится отдельная bounded repair-команда: она работает только с точным allowlist demo email pattern, поддерживает dry-run, не затрагивает обычных пользователей и требует explicit `--force`; production write дополнительно требует `--backup-confirmed` и `--writers-paused`. Перед её применением обязательны SQLite backup/checkpoint, проверка свободного места и post-run audit.

### Owner-scoped cache

HTML, CSRF, sessions, tokens, signed URLs и Eloquent object graphs не кэшируются. Новый `user-portal` cache хранит только bounded compact arrays: ID pages, totals и агрегаты. Scope строится по стабильному `users.public_id`; email и внутренний ID не входят в data key. Dimensions включают locale, section, validated filters/sort/page и projection format.

При чтении:

1. current user и authorization определяются из session;
2. cache возвращает только ID/aggregate snapshot этого owner scope;
3. модели повторно загружаются из authoritative owner/visibility query;
4. miss/outage перестраивается из БД, не расширяя доступ.

При записи после успешного commit повышается только версия этого пользователя. Уникальная `WarmUserPortalCache` job прогревает bounded default snapshots на `cache-warm-v2`; queue failure не влияет на correctness. Security page с password/session/token state всегда bypass-ит persistent cache. Notification read state и одноразовые action tokens также не попадают в payload.

Команда `cache:warm-user-portal {users*}` принимает exact public UUID, username или email. Один пользователь прогревается синхронно; два и более автоматически отправляются в очередь немедленно. `--all-demo` использует только известный demo allowlist. Store-wide flush и shell `exec()` из application code не используются.

### Profile images

Upload boundary повторно проверяет actual MIME, bytes, dimensions и pixel budget, учитывает JPEG EXIF orientation, выполняет center crop/resample и заново кодирует файл:

- avatar: 320×320 WebP;
- cover: 1280×360 WebP;
- quality: bounded config default 82.

Re-encode удаляет исходные metadata и client filename. Новый файл сначала записывается под generated private path, затем DB metadata меняется транзакционно; при ошибке новый файл удаляется, старый остаётся. После commit старый owned file удаляется best effort и owner cache invalidируется.

### Collection cover compatibility

Новые demo covers пишутся сразу в responder-compatible WebP path. Для старых несогласованных rows URL service fail-closed возвращает `null`, чтобы card presenter использовал существующий poster fallback вместо битой картинки. Repair-команда создаёт корректный локальный WebP и атомарно повышает cover/content version для demo collections.

## Cross-feature impact

- Authentication/authorization/privacy: owner берётся только server-side; security/session/token pages не кэшируются.
- Search/SEO/sitemap/public cache: private snapshots не участвуют; изменение публичного profile/collection продолжает использовать существующие invalidators.
- Notifications: cached discussion data не содержит action token/read-sensitive payload; default warm не помечает уведомления прочитанными.
- Imports/recommendations: collection source sync и recommendation handoff не меняются; demo repair не делает network requests.
- Mobile/Livewire: markup и routes остаются совместимыми; cache работает ниже full-page component boundary.
- Production: migrations и dependencies не нужны; требуется worker с очередью `cache-warm-v2`, backup перед data repair и reload long-lived workers после deploy.

## Rollback

Код откатывается forward commit без удаления cache keys: новый namespace станет недостижимым и истечёт по TTL. Queue jobs идемпотентны и безопасно становятся no-op при отсутствии user. WebP database rows остаются поддерживаемыми прежними responders. Для production repair исходная SQLite backup является data rollback; после новых пользовательских записей предпочтителен roll-forward, чтобы не терять изменения.
