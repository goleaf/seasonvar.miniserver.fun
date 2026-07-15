<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionType: string
{
    case User = 'user';
    case Editorial = 'editorial';
    case System = 'system';

    public function label(): string
    {
        return __("collections.types.{$this->value}");
    }
}
