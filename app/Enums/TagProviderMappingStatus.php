<?php

declare(strict_types=1);

namespace App\Enums;

enum TagProviderMappingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __("tags.provider_mapping_status.{$this->value}");
    }
}
