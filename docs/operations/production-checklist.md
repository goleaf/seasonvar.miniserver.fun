# Production acceptance checklist

Проверено: 18.07.2026. Выполнять после deployment/rollback/restore. Не проводить реальную оплату, external OAuth login или production mail без отдельной авторизации.

## Before activation

- [ ] Intended и previous known-good commit записаны; `main` clean, `.env`/uploads вне Git.
- [ ] Backup database/files проверен и доступен; rollback schema/data decision записан.
- [ ] `composer install` использовал lock без dev/uncontrolled update; platform requirements прошли.
- [ ] `npm ci` использовал единственный lock; production build/manifest/all referenced assets проверены.
- [ ] Pending migrations классифицированы; backup и maintenance decision подтверждены.
- [ ] Writable `storage/`/`bootstrap/cache`, private upload disk и modes проверены; `777` отсутствует.
- [ ] Config/routes/views/events caches собраны только после успешного boot; PHP-FPM graceful reload и worker restart подтверждены.
- [ ] Ровно один importer profile активен; scheduler/cron/workers соответствуют документированному pool.

## Public and user journeys

- [ ] `/health/ready` минимален, no-store/noindex и не содержит component/secret/path details.
- [ ] Главная, locale switch/localized route, search, catalogue/filter, title, season и episode отвечают.
- [ ] Player получает только authorized source; subtitle/audio/quality controls и error fallback не раскрывают URL/credentials.
- [ ] Login/logout/session persistence, settings/profile, progress/history/bookmarks/library/collections работают.
- [ ] Help center, content request, technical ticket и rights-holder public forms открываются там, где разрешено.

## Security and business boundaries

- [ ] Premium, region, legal, advertiser ad-free exclusion и admin permission resolve server-side и fail closed.
- [ ] Payment browser return не выдаёт entitlement; webhook route/signature/config state проверены без реального charge.
- [ ] Private ticket/legal/profile files недоступны по public path; uploads не исполняются.
- [ ] Admin/advertiser/private pages noindex и не попадают в service-worker cache (service worker сейчас отсутствует).
- [ ] Public canonical/`hreflang`/sitemap/robots/structured data не содержат localhost/staging/private host/source URL.

## Operations

- [ ] Database/cache/session/queue/storage/asset state проверены; Memcached `unavailable` не маскируется как healthy.
- [ ] Import/worker heartbeat, backlog, scheduler freshness и failed-job summary просмотрены без mass retry/clear.
- [ ] Logs не содержат очевидных secrets/tokens/cookies/source URLs/private contents; disk/WAL/log growth bounded.
- [ ] Mail/payment/OAuth/source/storage provider configured state отмечен честно; unavailable state имеет recovery path.
- [ ] Latest verified backup state и restore-test limitation записаны.
- [ ] Maintenance выключен только после smoke; deployment/rollback event и verification evidence записаны.
