<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogTopListCategory: string
{
    case Movies = 'movies';
    case Series = 'series';
    case Anime = 'anime';
    case Cartoons = 'cartoons';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __("top_lists.categories.{$this->value}.label");
    }

    public function title(): string
    {
        return __("top_lists.categories.{$this->value}.title");
    }

    public function description(): string
    {
        return __("top_lists.categories.{$this->value}.description");
    }

    public function accessibilityLabel(): string
    {
        return __("top_lists.categories.{$this->value}.accessibility");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Movies => 'fa-solid fa-film',
            self::Series => 'fa-solid fa-clapperboard',
            self::Anime => 'fa-solid fa-wand-magic-sparkles',
            self::Cartoons => 'fa-solid fa-shapes',
        };
    }
}
