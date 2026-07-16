<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogPersonalizationConfidence: string
{
    case Cold = 'cold';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
