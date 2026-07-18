<?php

declare(strict_types=1);

$emailAllowlist = static fn (string $environmentKey): array => array_values(array_filter(array_map(
    static fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', (string) env($environmentKey, '')),
)));

return [
    // Public safety metadata only. Secrets remain in provider-specific environment-backed config.
    // 'providers' => ['provider_code' => ['checkout_hosts' => ['checkout.provider.example']]],
    'providers' => [],
    'supported_currencies' => [],
    'base_currency' => null,
    // Restricted plans fail closed until a trusted server-side region resolver is configured.
    'server_region_code' => null,
    'checkout_ttl_minutes' => 30,
    'webhook_max_bytes' => 262144,
    'history_per_page' => 15,
    'administration' => [
        'grant_emails' => $emailAllowlist('PREMIUM_GRANT_ADMIN_EMAILS'),
        'promotion_emails' => $emailAllowlist('PREMIUM_PROMOTION_ADMIN_EMAILS'),
        'billing_audit_emails' => $emailAllowlist('PREMIUM_BILLING_AUDIT_EMAILS'),
        'reconciliation_emails' => $emailAllowlist('PREMIUM_RECONCILIATION_ADMIN_EMAILS'),
    ],
    'rate_limits' => [
        'checkout_per_minute' => 6,
        'coupon_per_minute' => 8,
        'administration_per_minute' => 30,
        'webhook_per_minute' => 120,
    ],
];
