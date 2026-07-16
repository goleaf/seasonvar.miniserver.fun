<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionSourceMatchStatus: string
{
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
}
