# Task 100 compliance matrix — честная PWA и Web Push

Обновлено: 26.07.2026.

Статусы: `completed`, `already_compliant`, `not_applicable`, `unresolved`,
`pending`.

| Требование / domain | Статус | Evidence / финальный gate |
| --- | --- | --- |
| Root/index/canonical fresh-read | `completed` | Read order выполнен; новый owner `requirements/pwa-and-push.md` |
| Actual runtime/package/DB/frontend inventory | `completed` | PHP 8.5.8, Laravel 13.22, Livewire 4.3.3, SQLite, Tailwind 4.3/Vite 8 |
| Git/main/shared-tree audit | `completed` | Existing `main`; чужие staged/unstaged/untracked Tasks 96–99 защищены |
| Existing implementation traced | `completed` | Layout/mobile runtime, library/help, API sync, poster guard, notifications, queue, logout/delete |
| Alternatives/design/rollback | `completed` | Linked design rejects private page caching, fake static-only PWA and new package |
| Detailed no-limit plan | `completed` | 57-step Task 100 checklist |
| Canonical permanent requirement | `completed` | New PWA/push owner registered before implementation |
| Prepared-plan reread and TDD RED | `completed` | Expected missing-route, missing-schema and missing-runtime failures recorded before production code |
| Manifest/icons/installability | `pending` | HTTP/image/browser evidence |
| Service worker/offline shell | `pending` | Strict denylist, asset versioning, failure-only fallback |
| Offline personal library | `pending` | Owner/minimal/bounded/opaque-scope tests |
| Offline public help | `pending` | Published `everyone` only, sanitized/plain/bounded |
| Poster/metadata cache | `pending` | Visible same-origin proxy, SSRF/type/size/bounded eviction |
| Safe action queue | `pending` | `watchlist.set`/`rating.set`, CSRF/session/version/idempotency |
| Push subscription storage | `pending` | Encrypted endpoint/hash/ownership/revoke/cascade |
| VAPID payloadless delivery | `pending` | ES256 exact audience, empty POST, provider failure tests |
| Notification integration | `pending` | Database channel after-commit queue fan-out |
| Push permission/settings UX | `pending` | Explicit gesture, enable/revoke/unsupported/denied states |
| No offline protected video | `pending` | Worker/cache/browser negative evidence |
| Validation/normalization | `pending` | Form Requests and strict schemas |
| Authorization/IDOR/CSRF | `pending` | Owner routes, active account, visible titles, CSRF tests |
| XSS/SSRF/secrets/log privacy | `pending` | Safe DOM/proxy/push guard/diff scans |
| Database migration/index/down | `pending` | Additive reversible schema and EXPLAIN/index evidence |
| Query/payload/cache performance | `pending` | Bounds, query budget, chunked fan-out |
| Error/recovery behavior | `pending` | Offline/quota/provider/worker/migration recovery |
| RU/EN parity/a11y/mobile | `pending` | Translation and Playwright/axe evidence |
| Authentication/logout/account deletion | `pending` | Local cleanup/current installation revoke/FK cascade |
| Help/library/API/player/Premium compatibility | `pending` | Related regression matrix |
| Search/SEO/admin/import/calendar impact | `pending` | Explicit no-contract-change regression/docs |
| Dependency change | `not_applicable` | No production package; native browser/OpenSSL/Laravel HTTP APIs |
| New cache/queue infrastructure | `not_applicable` | Existing Cache Storage/browser IndexedDB and project queue only |
| README/thematic docs/CHANGELOG | `pending` | Updated only after verified implementation |
| Focused/full/static/build/browser checks | `pending` | Exact commands/results |
| Final requirement reread/legacy/debug/secret audit | `pending` | Final evidence |
| Exact Task 100 commit on `main` | `pending` | Scoped staged diff and commit hash |
| Ordinary push | `pending` | Remote result or exact `unresolved` refusal |

## Expected changed files

Новые production boundaries ожидаются в `config/pwa.php`,
`app/Services/Pwa/*`, `app/Http/Requests/Pwa/*`,
`app/Http/Responders/Pwa/*`, `app/Models/WebPushSubscription.php`,
`app/Jobs/DeliverWebPushNotification.php`,
`app/Listeners/QueueWebPushForDatabaseNotification.php`,
`app/Livewire/PwaOfflinePage.php`, одной новой migration,
`resources/pwa/*`, `resources/js/pwa*.js`,
`resources/views/layouts/offline.blade.php`,
`resources/views/livewire/pwa-offline-page.blade.php`,
`resources/images/pwa-icon.svg`, `public/icons/*` и focused tests.

Точечно ожидаются изменения `routes/web.php`, `app/Models/User.php`,
`app/Services/Auth/WebAuthenticationService.php`,
`app/Providers/AppServiceProvider.php`, `resources/js/app.js`,
`resources/views/layouts/app.blade.php`,
`resources/views/livewire/settings/account-settings-page.blade.php`,
`lang/{ru,en}/settings.php`, `.env.example`, thematic docs, `README.md` и
`CHANGELOG.md`. Перечень обновляется при discovery до изменения scope.

## Protected compatibility contracts

- Existing `main` и все чужие shared-tree hunks Tasks 96–99.
- Все public/localized route names и current API response shapes.
- Bearer mobile API и idempotent sync receipts.
- Private page/API `no-store`, session/CSRF/auth active/policy/gate boundaries.
- Catalog title visibility, Premium/region/publication and soft-delete rules.
- Help audience/publication/translation fallback.
- Existing database notification payloads/inbox/read/export behavior.
- Player source health/fallback, signed grants/downloads and direct external
  delivery.
- Existing cache keys/invalidation, public page cache and server cache stores.
- No HLS/progressive/protected video caching or offline-playback promise.
- No VAPID private key, push endpoint, token, email/user ID or provider URL in
  tracked files, client storage, logs or final report.
