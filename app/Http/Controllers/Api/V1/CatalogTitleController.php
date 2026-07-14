<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogTitleIndexRequest;
use App\Http\Resources\Api\V1\CatalogTitleResource;
use App\Http\Resources\Api\V1\EpisodeResource;
use App\Http\Resources\Api\V1\SeasonResource;
use App\Http\Resources\Api\V1\TitleCardResource;
use App\Services\Catalog\Api\V1\CatalogTitleDetailQuery;
use App\Services\Catalog\Api\V1\CatalogTitleIndexQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogTitleController extends Controller
{
    public function index(
        CatalogTitleIndexRequest $request,
        CatalogTitleIndexQuery $titles,
    ): AnonymousResourceCollection {
        return TitleCardResource::collection($titles->paginate($request));
    }

    public function show(
        Request $request,
        string $titleSlug,
        CatalogTitleDetailQuery $titles,
    ): CatalogTitleResource {
        return new CatalogTitleResource($titles->title($titleSlug, $request->user()));
    }

    public function seasons(
        Request $request,
        string $titleSlug,
        CatalogTitleDetailQuery $titles,
    ): AnonymousResourceCollection {
        $title = $titles->visibleTitle($titleSlug, $request->user());

        return SeasonResource::collection($titles->seasons($title, $request->user()));
    }

    public function episodes(
        Request $request,
        string $titleSlug,
        int $season,
        CatalogTitleDetailQuery $titles,
    ): AnonymousResourceCollection {
        $title = $titles->visibleTitle($titleSlug, $request->user());

        return EpisodeResource::collection($titles->episodes($title, $season, $request->user()));
    }
}
