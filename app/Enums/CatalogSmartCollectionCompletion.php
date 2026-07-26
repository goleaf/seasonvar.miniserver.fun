<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogSmartCollectionCompletion: string
{
    case Completed = 'completed';
    case Ongoing = 'ongoing';

    public function label(): string
    {
        return __("collections.smart.completion.{$this->value}");
    }
}
