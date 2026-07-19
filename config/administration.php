<?php

declare(strict_types=1);

$emailAllowlist = static fn (string $environmentKey): array => array_values(array_filter(array_map(
    static fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', (string) env($environmentKey, '')),
)));

return [
    // Explicit bootstrap boundary only. It does not inherit billing or legal-document permissions.
    'bootstrap_superadministrator_emails' => $emailAllowlist('ADMIN_BOOTSTRAP_SUPERADMIN_EMAILS'),
    'per_page' => 25,
    'maximum_per_page' => 100,
    'maximum_bulk_items' => 50,
];
