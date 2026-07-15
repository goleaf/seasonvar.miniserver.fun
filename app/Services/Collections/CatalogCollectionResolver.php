<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class CatalogCollectionResolver
{
    public function __construct(private readonly CatalogCollectionSchema $schema) {}

    /** @return array{collection: CatalogCollection, historical: bool} */
    public function resolve(string $slug): array
    {
        abort_unless($this->schema->available(), 404);

        $normalized = Str::lower(trim($slug));
        abort_if($normalized === '' || mb_strlen($normalized) > 180, 404);

        $collection = CatalogCollection::query()->where('slug', $normalized)->first();

        if ($collection !== null) {
            return ['collection' => $collection, 'historical' => $normalized !== $slug];
        }

        $collection = CatalogCollection::query()
            ->whereHas('historicalSlugs', fn (Builder $query): Builder => $query->where('slug', $normalized))
            ->firstOrFail();

        return ['collection' => $collection, 'historical' => true];
    }

    public function byPublicId(string $publicId, bool $withTrashed = false): CatalogCollection
    {
        abort_unless($this->schema->available(), 404);
        abort_unless(Str::isUuid($publicId), 404);
        $query = CatalogCollection::query();

        return ($withTrashed ? $query->withTrashed() : $query)
            ->where('public_id', Str::lower($publicId))
            ->firstOrFail();
    }
}
