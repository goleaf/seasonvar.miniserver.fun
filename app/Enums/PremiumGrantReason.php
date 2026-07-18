<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumGrantReason: string
{
    case SupportCompensation = 'support_compensation';
    case PartnerAccess = 'partner_access';
    case MigrationCorrection = 'migration_correction';
    case EditorialPolicy = 'editorial_policy';

    public function label(): string
    {
        return __("premium.admin.reasons.{$this->value}");
    }
}
