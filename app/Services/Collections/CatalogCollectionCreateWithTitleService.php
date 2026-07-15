<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionData;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionCreateWithTitleService
{
    public function __construct(
        private readonly CatalogCollectionService $collections,
        private readonly CatalogCollectionItemService $items,
    ) {}

    public function create(User $actor, CatalogTitle $title, CatalogCollectionData $data): CatalogCollection
    {
        return DB::transaction(function () use ($actor, $title, $data): CatalogCollection {
            $collection = $this->collections->create($actor, $data);
            $this->items->add($actor, $collection, $title);

            return $collection;
        }, attempts: 3);
    }
}
