<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionVisibility: string
{
    case Private = 'private';
    case Unlisted = 'unlisted';
    case Public = 'public';

    public function label(): string
    {
        return __("collections.visibility.{$this->value}");
    }

    public function isDirectlyViewable(): bool
    {
        return $this !== self::Private;
    }

    public function isDirectoryVisible(): bool
    {
        return $this === self::Public;
    }
}
