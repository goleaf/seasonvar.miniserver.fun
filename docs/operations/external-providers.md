# External provider operations

Проверено: 18.07.2026. Configuration presence is not provider reachability. Secrets/URLs are never printed by health or documentation.

| Provider | Current state | Failure/recovery contract |
| --- | --- | --- |
| Seasonvar source | Configured authorized HTTPS importer source | Laravel HTTP client uses configured User-Agent, SSRF/public-DNS validation, bounded timeout/retry/response size. Failure records sanitized status and preserves existing catalog; no full video download/import. |
| Licensed playback host | Allowlisted HTTPS media boundary | Signed application route reauthorizes the source; provider failure uses localized playback fallback/error, never exposes credentials or proxies arbitrary URL. |
| Mail | `log` driver in verified runtime; external delivery not configured | Application acceptance is not delivery. Verification/reset/notification outage remains visible to operator; no unsafe synchronous fake-success claim. |
| Payment | No active provider configuration/SDK detected | Premium remains server-side and no checkout is invented. If added: hosted flow, webhook signature, idempotency, pending state, refunds/disputes and reconciliation are mandatory. |
| OAuth | No active callback/provider routes detected | No external login is claimed. Future provider requires state/PKCE, verified identity, safe redirect, collision/linking and secret rotation review. |
| S3/object storage | Optional config keys present, credentials absent | Current local disks remain canonical. Enabling S3 requires private ACL, timeout, migration/restore and rollback assessment. |
| Google Search Console/Analytics | Disabled | No telemetry is sent. Enabling requires approved privacy, credentials outside Git and read-only scopes where applicable. |
| HDRezka collection sync | Feature disabled | No scheduled provider work is performed while disabled; preserved local/editorial data remains available. |
| External monitoring/captcha/push | Not installed/configured | No fake health, alert or fallback is shown. |

## Provider rules

1. Use config, never direct `env()` outside config or hardcoded production hostname/path.
2. Validate target URL/host/DNS before outbound request; redirect targets are revalidated.
3. Bound connect/total timeout, body size and attempts. Retry only safe/idempotent temporary failures with jitter/backoff.
4. Non-idempotent payment/mail/provider state requires stable request/event identity and reconciliation.
5. User messages are localized and omit provider internals. Logs use sanitized codes/classes, not payloads/credentials/source URLs.
6. Maintenance and deployment preserve webhook/callback reachability or explicitly enter pending/reconciliation mode.
7. A provider secret rotation is an audited operations event without storing the value.
