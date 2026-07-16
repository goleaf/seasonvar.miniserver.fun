<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogTitleRelationSource: string
{
    case Editorial = 'editorial';
    case ImportedProvider = 'imported_provider';
}
