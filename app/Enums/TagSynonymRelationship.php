<?php

declare(strict_types=1);

namespace App\Enums;

enum TagSynonymRelationship: string
{
    case RelatedSearch = 'related_search';
    case Editorial = 'editorial';
}
