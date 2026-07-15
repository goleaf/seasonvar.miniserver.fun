<?php

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

/**
 * @mixin Genre|Country|Actor|Director|AgeRating|Translation|CatalogStatus|Network|Studio|Tag
 */
class CatalogTaxonomyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isTag = $this->resource instanceof Tag;

        return [
            'type' => $this->type(),
            'id' => $this->id,
            'public_id' => $this->when($isTag, fn (): string => (string) $this->public_id),
            'code' => $this->when($isTag && $this->code !== null, fn (): string => (string) $this->code),
            'tag_type' => $this->when($isTag, fn (): string => $this->resource->type->value),
            'name' => $this->name,
            'slug' => $this->slug,
            'links' => $this->when($isTag, fn (): array => [
                'web' => route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $this->slug]),
            ]),
        ];
    }

    private function type(): string
    {
        return match ($this->resource::class) {
            Genre::class => 'genre',
            Country::class => 'country',
            Actor::class => 'actor',
            Director::class => 'director',
            AgeRating::class => 'age_rating',
            Translation::class => 'translation',
            CatalogStatus::class => 'status',
            Network::class => 'network',
            Studio::class => 'studio',
            Tag::class => 'tag',
        };
    }
}
