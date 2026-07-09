<?php

namespace App\View\Components;

use App\Models\CatalogTitle;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class TitleListRow extends Component
{
    public int $seasonsCount;

    public int $episodesCount;

    public int $mediaCount;

    public ?Season $latestSeason;

    public string $posterClass;

    public string $baseClass;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardRelations;

    public function __construct(
        public CatalogTitle $title,
        public bool $compact = false,
        public bool $showDescription = true,
        public bool $readable = false,
    ) {
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->mediaCount = (int) ($title->published_media_count ?? $title->licensed_media_count ?? 0);
        $this->latestSeason = $title->relationLoaded('seasons') ? $title->seasons->sortByDesc('number')->first() : null;
        $this->posterClass = match (true) {
            $readable => 'aspect-[2/3] w-20 sm:w-24',
            $compact => 'h-24 w-16',
            default => 'h-20 w-14 sm:h-24 sm:w-16',
        };
        $this->baseClass = match (true) {
            $readable => 'block p-3 transition hover:bg-emerald-50 sm:p-4',
            $compact => 'block p-3 hover:bg-emerald-50',
            default => 'block px-4 py-3 hover:bg-emerald-50',
        };
        $this->cardRelations = collect()
            ->merge($title->relationLoaded('genres') ? $title->genres : collect())
            ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
            ->merge($title->relationLoaded('translations') ? $title->translations : collect())
            ->merge($title->relationLoaded('tags') ? $title->tags : collect())
            ->take(4);
    }

    public function render(): View
    {
        return view('components.title-list-row');
    }
}
