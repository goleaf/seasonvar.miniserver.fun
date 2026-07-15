# Отчёт по video playback

Проверено: 15.07.2026. Video playback рассматривается как отдельная critical subsystem; приложение обслуживает только legally authorized provider links and metadata.

## Подтверждённый data flow

`User/page or API request → catalog visibility and entitlement → episode/media resolution → short-lived same-origin signed grant → viewer-bound reauthorization → allowlisted HTTPS redirect → provider/CDN → native video/Plyr/HLS.js → throttled progress service → history/Continue Watching`.

Laravel не скачивает и не проксирует large video bytes. Stored upstream URL не сериализуется в public Livewire/API state; web/API playback route возвращает redirect only after signature, hierarchy, viewer and source checks.

## Source matrix

| Source / behavior | State | Verification | Remaining risk |
| --- | --- | --- | --- |
| HLS master/media playlist | Native support first, lazy HLS.js MSE fallback | Player unit/feature/browser shell tests | Real provider CORS/manifest changes are external |
| Direct MP4 | Redirect/offload | Signed source and redirect tests; prior provider sample returned 206 | Range/cache/MIME must be monitored per provider |
| External embed | Model/parser support must remain allowlisted; not a generic arbitrary iframe | URL guard/security tests | CSP/frame policy must be explicit per provider |
| Subtitles | Metadata/track paths supported | Parser/player fixtures | External subtitle CORS/encoding failure matrix needs browser fixture |
| Multiple quality/audio | Quality/source metadata exists; browser selection depends on manifest/player | Parser/player tests | Normalized audio-track domain is incomplete |
| Expired/forbidden/missing | Safe 403/404 states; no raw URL in error | Feature/API tests | Manual provider timeout/offline UX still required |

## Progress integrity

- Browser checkpoints are throttled and use meaningful lifecycle events; database is not written on every `timeupdate`.
- Progress session/grant is bounded; user and episode ownership/visibility are rechecked.
- Completed episodes cannot be moved backwards by stale lower progress; retry/idempotency behavior is tested.
- The latest browser regression confirms opening/leaving a title no longer creates a false zero-progress record and confirms saved 120-second progress in Continue Watching.

## Findings

| ID | Класс | Наблюдение | Изменение | Статус | Risk |
| --- | --- | --- | --- | --- | --- |
| VP-01 | Confirmed control | No PHP media proxy and no private URL exposure | Preserve | Verified | Provider remains availability boundary |
| VP-02 | Confirmed problem | Provider capability/error matrix is not fully deterministic in browser CI | Add tiny legal local HLS/MP4/subtitle fixtures or mocked network boundary | Pending P6 | Do not depend on external provider in normal CI |
| VP-03 | Probable | PiP/fullscreen/Media Session/orientation cleanup lacks full automated coverage | Manual + browser capability matrix, listener leak assertions | Pending P6 | CI browser capabilities vary |
| VP-04 | Probable | CSP tightening may block provider media/connect origins | Inventory actual origins before enforcement | Pending security | Playback outage if enforced blindly |
| VP-05 | Intentional | No DRM, transcode, local upload or object-storage video pipeline | Do not invent | Accepted | Requires separate legal/product/infrastructure design |

## Acceptance gates

Tests must cover valid/invalid HLS, expired/forbidden grant, upstream timeout, MP4 range semantics at a deterministic fixture boundary, subtitle failure, duplicate progress, unauthorized progress, completion/next episode and player destruction during navigation. Keyboard, touch, captions, reduced motion, fullscreen and PiP receive explicit browser/manual evidence where the test runtime supports them.
