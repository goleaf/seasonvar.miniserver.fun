<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogHomeResource;
use App\Services\Catalog\CatalogHomePageBuilder;

final class CatalogHomeController extends Controller
{
    public function __invoke(CatalogHomePageBuilder $home): CatalogHomeResource
    {
        return new CatalogHomeResource($home->data());
    }
}
