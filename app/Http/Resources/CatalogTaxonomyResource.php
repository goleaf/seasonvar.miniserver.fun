<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\Country;
use App\Models\Director;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * @mixin Genre|Country|Actor|Director|AgeRating|Translation|CatalogStatus|Network|Studio|Tag
 */
final class CatalogTaxonomyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $taxonomy = $this->taxonomy();
        $tag = $taxonomy instanceof Tag ? $taxonomy : null;
        $slug = (string) $taxonomy->getAttribute('slug');

        return [
            'type' => $this->type($taxonomy),
            'id' => (int) $taxonomy->getKey(),
            'public_id' => $this->when($tag !== null, $tag === null ? null : (string) $tag->getAttribute('public_id')),
            'code' => $this->when($tag?->getAttribute('code') !== null, (string) $tag?->getAttribute('code')),
            'tag_type' => $this->when($tag !== null, $tag?->type->value),
            'name' => (string) $taxonomy->getAttribute('name'),
            'slug' => $slug,
            'links' => $this->when($tag !== null, fn (): array => [
                'web' => route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $slug]),
            ]),
        ];
    }

    private function taxonomy(): Genre|Country|Actor|Director|AgeRating|Translation|CatalogStatus|Network|Studio|Tag
    {
        $resource = $this->resource;

        if ($resource instanceof Genre
            || $resource instanceof Country
            || $resource instanceof Actor
            || $resource instanceof Director
            || $resource instanceof AgeRating
            || $resource instanceof Translation
            || $resource instanceof CatalogStatus
            || $resource instanceof Network
            || $resource instanceof Studio
            || $resource instanceof Tag) {
            return $resource;
        }

        throw new LogicException('Unsupported catalog taxonomy resource.');
    }

    private function type(Genre|Country|Actor|Director|AgeRating|Translation|CatalogStatus|Network|Studio|Tag $taxonomy): string
    {
        return match (true) {
            $taxonomy instanceof Genre => 'genre',
            $taxonomy instanceof Country => 'country',
            $taxonomy instanceof Actor => 'actor',
            $taxonomy instanceof Director => 'director',
            $taxonomy instanceof AgeRating => 'age_rating',
            $taxonomy instanceof Translation => 'translation',
            $taxonomy instanceof CatalogStatus => 'status',
            $taxonomy instanceof Network => 'network',
            $taxonomy instanceof Studio => 'studio',
            $taxonomy instanceof Tag => 'tag',
        };
    }
}
