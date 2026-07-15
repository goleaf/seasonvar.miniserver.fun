<?php

declare(strict_types=1);

namespace App\Enums;

enum TagAliasSource: string
{
    case Editorial = 'editorial';
    case Provider = 'provider';
    case Legacy = 'legacy';
    case FormerLabel = 'former_label';
}
