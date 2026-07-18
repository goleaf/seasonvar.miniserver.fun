# Service worker deployment

Проверено: 18.07.2026.

Browser application manifest, service-worker source, build entry и browser registration не найдены. `public/build/manifest.json` — только Vite asset manifest и не является PWA manifest. Текущее состояние — `not_installed`; offline cache, push, background sync, update prompt и automatic stale-cache cleanup не заявляются.

Обычный Vite deployment обязан:

1. выполнить reproducible `npm ci` из `package-lock.json`;
2. выполнить `npm run build` до активации code, проверить `public/build/manifest.json` и каждый referenced asset;
3. развернуть manifest и соответствующий полный asset set вместе;
4. не удалять assets, на которые ещё ссылается active HTML;
5. не публиковать private Vite variables или production source maps без policy review.

Если service worker будет добавлен отдельной задачей, он обязан использовать versioned cache names и strict static allowlist. Private/auth/account/admin/payment/webhook/ticket/legal/advertiser/protected-media routes исключаются. Logout/account switch очищают только owned private state; update не прерывает playback принудительной reload. Rollback возвращает matching worker+asset cache version. Generic cache-all PWA package запрещён.
