<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSlug;
use Illuminate\Support\Str;

final class CatalogCollectionSlugService
{
    public function generate(string $name, string $publicId, ?CatalogCollection $except = null): string
    {
        $identity = Str::lower($publicId);
        $base = Str::slug($name) ?: 'collection';
        $base = Str::limit($base, 180 - mb_strlen('-'.$identity), '');
        $candidate = $base.'-'.$identity;

        for ($suffix = 2; $this->isTaken($candidate, $except); $suffix++) {
            $candidate = Str::limit($base, 180 - mb_strlen('-'.$identity.'-'.$suffix), '')
                .'-'.$identity.'-'.$suffix;
        }

        return $candidate;
    }

    public function change(CatalogCollection $collection, string $name): bool
    {
        $next = $this->generate($name, $collection->public_id, $collection);

        if ($next === $collection->slug) {
            return false;
        }

        CatalogCollectionSlug::query()->firstOrCreate([
            'slug' => $collection->slug,
        ], [
            'catalog_collection_id' => $collection->id,
        ]);
        CatalogCollectionSlug::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('slug', $next)
            ->delete();
        $collection->slug = $next;

        return true;
    }

    private function isTaken(string $slug, ?CatalogCollection $except): bool
    {
        $current = CatalogCollection::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($current) {
            return true;
        }

        return CatalogCollectionSlug::query()
            ->where('slug', $slug)
            ->when($except !== null, fn ($query) => $query->where('catalog_collection_id', '!=', $except->id))
            ->exists();
    }
}
