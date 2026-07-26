<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionInclusionReason: string
{
    case CategoryGenre = 'category_genre';
    case CategoryCountry = 'category_country';
    case CategoryPlatform = 'category_platform';
    case CategoryType = 'category_type';
    case TitleTheme = 'title_theme';
    case SourceRule = 'source_rule';
    case EditorialChoice = 'editorial_choice';
    case ManualChoice = 'manual_choice';
    case SmartRule = 'smart_rule';

    public function isPrecise(): bool
    {
        return ! in_array($this, [self::EditorialChoice, self::ManualChoice], true);
    }
}
