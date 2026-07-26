<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogMetadataSourceKind: string
{
    case Provider = 'provider';
    case Editorial = 'editorial';
    case Legacy = 'legacy';
}
