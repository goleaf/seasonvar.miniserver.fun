<?php

declare(strict_types=1);

namespace App\Enums;

enum TagType: string
{
    case System = 'system';
    case Editorial = 'editorial';
    case Imported = 'imported';
    case HiddenInternal = 'hidden_internal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __("tags.types.{$this->value}");
    }
}
