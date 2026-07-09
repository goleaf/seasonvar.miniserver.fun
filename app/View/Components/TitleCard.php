<?php

namespace App\View\Components;

use App\Models\CatalogTitle;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class TitleCard extends Component
{
    public int $seasonsCount;

    public int $episodesCount;

    public int $mediaCount;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardRelations;

    public function __construct(public CatalogTitle $title)
    {
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->mediaCount = (int) ($title->published_media_count ?? $title->licensed_media_count ?? 0);
        $this->cardRelations = collect()
            ->merge($title->relationLoaded('genres') ? $title->genres : collect())
            ->merge($title->relationLoaded('countries') ? $title->countries : collect())
            ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
            ->merge($title->relationLoaded('translations') ? $title->translations : collect())
            ->merge($title->relationLoaded('tags') ? $title->tags : collect())
            ->take(3);
    }

    public function render(): View
    {
        return view('components.title-card');
    }
}
