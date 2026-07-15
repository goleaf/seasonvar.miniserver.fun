<?php

declare(strict_types=1);

namespace App\Enums;

enum TagVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __("tags.visibility.{$this->value}");
    }
}
