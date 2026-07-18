<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumFeature;

final class PremiumFeatureRegistry
{
    /** @return list<array{code: string, label: string, description: string, implementation: string}> */
    public function active(): array
    {
        return [[
            'code' => PremiumFeature::PremiumAccess->value,
            'label' => PremiumFeature::PremiumAccess->label(),
            'description' => PremiumFeature::PremiumAccess->description(),
            'implementation' => 'account_premium_summary',
        ]];
    }

    public function supports(string $code): bool
    {
        return PremiumFeature::tryFrom($code) !== null;
    }
}
