# Security audit

Проверено: 13.07.2026. Метод: route/middleware/policy/Livewire/media/import review, dependency audit, focused HTTP tests и OWASP/Laravel documentation comparison.

## Исправлено

- HTML responses теперь получают детерминированный `Content-Security-Policy-Report-Only`; JSON API не получает body-dependent policy. Fixed directives запрещают objects, ограничивают base/form/frame, не содержат `unsafe-eval`; configurable sources проходят allowlist parser, отклоняющий пробелы, `;` и control characters.
- Laravel Boost MCP запускается с абсолютным `cwd`, `--env=local` и наследуемым `APP_ENV=local`; без последнего дочерний Artisan process видел production и не регистрировал Boost tools.
- Session config больше не привязывает array/database test drivers к Redis connection `sessions`.

## Подтверждённые controls

- CSRF/session cookies/Laravel signed URLs используются framework boundary; debug выключен. Existing headers: nosniff, frame deny, strict referrer, HSTS для secure requests, Permissions Policy.
- Admin/import writes защищены route middleware, gates/policies, server-side validation и optimistic locks. Playback entitlement перепроверяется на signed endpoint и привязан к viewer.
- External URL guards требуют HTTPS allowlisted hosts, отклоняют credentials/private/link-local/metadata DNS, не следуют redirects и ограничивают response size/time. Raw provider URLs, source HTML, tokens и stack traces не отдаются API/admin/livewire/log context.
- Blade использует escaped output; JSON-LD кодируется с `JSON_HEX_*`. `@php`, Volt, untrusted raw HTML и DOM insertion sinks не найдены.
- Upload foundation private и не исполняет/транскодирует media. Видео не загружается и не зеркалируется приложением.
- SQL user input проходит validation/query builder; raw expressions фиксированы или bound. Mass assignment использует явные model rules/validated payloads.
- `composer audit` и `npm audit --audit-level=high`: 0 advisories.

## Отложено

- CSP остаётся report-only. Enforcement до наблюдения реального трафика может сломать provider images/media/Livewire; следующий шаг — собрать browser violations через внешний операторский collector или console sampling, перечислить точные origins, затем staged enforcement.
- Нет отдельной RBAC/role модели и append-only admin field-diff audit. Текущий email allowlist — документированная product boundary; расширять её без доменной модели нельзя.
- Region/rights/subscription/profile/DRM controls отсутствуют вместе с соответствующими таблицами. Нельзя считать их реализованными только из-за enum-ready entitlement responses.
- Live database migrations требуют backup и controlled deploy; они не были применены автономно.

## Принятые риски

- `style-src 'unsafe-inline'` оставлен только в report-only policy из-за текущих framework/UI styles; `unsafe-eval` запрещён. Риск ограничен наблюдением до перехода к nonce/hash или подтверждённой совместимости.
- `https:` разрешён для image/media/connect, потому что каталог уже использует несколько внешних licensed-provider/CDN origins. Это шире идеального CSP, поэтому enforcement не включён.
- Player browser сам получает provider bytes после signed redirect; приложение не может навязать provider range/CORS headers, но оно не проксирует payload через PHP и повторно проверяет доступ перед redirect.

Источники и причины решений: `docs/research/laravel-video-portal-sources.md`. Точный тест: `SecurityHardeningTest`.
