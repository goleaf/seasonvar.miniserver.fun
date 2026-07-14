<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Http\Requests\Api\V1\CatalogTitleIndexRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CatalogTitleIndexQuery
{
    public function __construct(private CatalogTitlesPageBuilder $pages) {}

    /** @return LengthAwarePaginator<int, CatalogTitle> */
    public function paginate(CatalogTitleIndexRequest $request): LengthAwarePaginator
    {
        $page = $this->pages->data(
            $request,
            includeFacets: false,
            includeDescription: true,
        );

        return $page['titles'];
    }
}
