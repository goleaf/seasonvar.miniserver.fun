<?php

namespace App\Enums;

enum CatalogFilterType: string
{
    case Genre = 'genre';
    case Country = 'country';
    case Actor = 'actor';
    case Director = 'director';
    case AgeRating = 'age_rating';
    case Translation = 'translation';
    case Status = 'status';
    case Network = 'network';
    case Studio = 'studio';
    case Tag = 'tag';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public static function routePattern(): string
    {
        return implode('|', array_map(
            fn (string $value): string => preg_quote($value, '/'),
            self::values(),
        ));
    }

    public function label(): string
    {
        return (string) __("catalog.taxonomy.{$this->value}");
    }
}
