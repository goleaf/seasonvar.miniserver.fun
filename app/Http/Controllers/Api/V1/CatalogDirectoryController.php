<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogDirectoryIndexRequest;
use App\Http\Resources\Api\V1\CatalogDirectoryItemResource;
use App\Http\Resources\Api\V1\CatalogDirectoryResource;
use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Support\CatalogAlphabet;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogDirectoryController extends Controller
{
    public function index(CatalogDirectoryRegistry $directories): AnonymousResourceCollection
    {
        return CatalogDirectoryResource::collection($directories->all()->values());
    }

    public function show(
        CatalogDirectoryIndexRequest $request,
        string $directory,
        CatalogDirectoryRegistry $directories,
        CatalogDirectoryQuery $query,
    ): AnonymousResourceCollection {
        $definition = $directories->find($directory);
        abort_if($definition === null, 404);

        $summary = $query->summary($definition);
        $items = $query->paginate(
            $definition,
            $request->search(),
            $request->letter(),
            $request->sort(),
            $request->decade(),
            $summary['values'],
            $request->perPage(),
        );
        $groups = CatalogAlphabet::availableGroups($query->letters($definition));

        return CatalogDirectoryItemResource::collection($items)->additional([
            'meta' => [
                'directory' => (new CatalogDirectoryResource($definition))->resolve($request),
                'summary' => $summary,
                'alphabet' => [
                    'cyrillic' => $groups['cyrillic'],
                    'latin' => $groups['latin'],
                    'other' => $groups['symbols'],
                ],
                'decades' => $definition->isYear() ? $query->decades()->all() : [],
            ],
        ]);
    }
}
