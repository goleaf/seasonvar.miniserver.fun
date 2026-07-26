<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogView: string
{
    case Grid = 'grid';
    case List = 'list';

    public function label(): string
    {
        return (string) __("catalog.catalog.views.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Grid => 'fa-solid fa-table-cells-large',
            self::List => 'fa-solid fa-list',
        };
    }
}
