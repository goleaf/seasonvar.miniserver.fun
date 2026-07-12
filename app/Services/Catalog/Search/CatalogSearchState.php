<?php

namespace App\Services\Catalog\Search;

enum CatalogSearchState: string
{
    case Empty = 'empty';
    case Ready = 'ready';
    case Insufficient = 'insufficient';
}
