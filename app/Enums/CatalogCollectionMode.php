<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionMode: string
{
    case Manual = 'manual';
    case Smart = 'smart';

    public function label(): string
    {
        return __("collections.smart.modes.{$this->value}");
    }
}
