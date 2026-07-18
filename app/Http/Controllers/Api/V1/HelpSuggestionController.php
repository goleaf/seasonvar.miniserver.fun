<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HelpSuggestionRequest;
use App\Http\Resources\Api\V1\HelpSuggestionResource;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpSearchService;
use Illuminate\Http\JsonResponse;

final class HelpSuggestionController extends Controller
{
    public function __invoke(
        HelpSuggestionRequest $request,
        HelpSearchService $search,
        HelpCenterSchema $schema,
    ): JsonResponse {
        $suggestions = $schema->ready()
            ? $search->suggestions($request->queryValue(), $request->localeValue(), null)
            : [];

        $response = HelpSuggestionResource::collection($suggestions)
            ->additional(['meta' => ['locale' => $request->localeValue()]])
            ->response();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
