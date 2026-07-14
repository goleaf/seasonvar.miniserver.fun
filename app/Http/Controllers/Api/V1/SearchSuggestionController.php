<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchSuggestionRequest;
use App\Http\Resources\Api\V1\SearchSuggestionResource;
use App\Services\Catalog\Api\V1\CatalogSearchSuggestionQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SearchSuggestionController extends Controller
{
    public function __invoke(
        SearchSuggestionRequest $request,
        CatalogSearchSuggestionQuery $suggestions,
    ): AnonymousResourceCollection {
        $result = $suggestions->search($request->queryValue(), $request->user());

        return SearchSuggestionResource::collection($result['items'])->additional([
            'meta' => ['query' => $result['query']],
        ]);
    }
}
