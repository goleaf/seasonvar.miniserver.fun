<?php

namespace App\View\Components;

use App\Models\CatalogTitle;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class TitleListRow extends Component
{
    public int $seasonsCount;

    public int $episodesCount;

    public ?Season $latestSeason;

    public string $posterClass;

    public string $baseClass;

    public function __construct(
        public CatalogTitle $title,
        public bool $compact = false,
        public bool $showDescription = true,
        public bool $readable = false,
    ) {
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->latestSeason = $title->relationLoaded('seasons') ? $title->seasons->sortByDesc('number')->first() : null;
        $this->posterClass = $compact ? 'h-24 w-16' : 'h-20 w-14 sm:h-24 sm:w-16';
        $this->baseClass = $compact ? 'block p-3 hover:bg-emerald-50' : 'block px-4 py-3 hover:bg-emerald-50';
    }

    public function render(): View
    {
        return view('components.title-list-row');
    }
}
