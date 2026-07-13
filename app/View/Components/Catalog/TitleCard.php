<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class TitleCard extends Component
{
    private const LAYOUTS = ['grid', 'horizontal', 'compact', 'recommendation'];

    public int $seasonsCount;

    public int $episodesCount;

    public int $mediaCount;

    public ?Season $latestSeason;

    public string $layout;

    /**
     * @var Collection<int, Model>
     */
    public Collection $cardRelations;

    public function __construct(
        public CatalogTitle $title,
        string $layout = 'grid',
        public bool $showDescription = true,
        public bool $readable = false,
        public ?int $rank = null,
        /** @var list<string> */
        public array $reasonLabels = [],
    ) {
        $this->layout = in_array($layout, self::LAYOUTS, true) ? $layout : 'grid';
        $this->seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
        $this->episodesCount = (int) ($title->episodes_count ?? 0);
        $this->mediaCount = (int) ($title->published_media_count ?? $title->licensed_media_count ?? 0);
        $this->latestSeason = $title->relationLoaded('latestSeason') ? $title->latestSeason : null;
        $this->cardRelations = collect()
            ->merge($title->relationLoaded('genres') ? $title->genres : collect())
            ->merge($title->relationLoaded('countries') ? $title->countries : collect())
            ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
            ->merge($title->relationLoaded('translations') ? $title->translations : collect())
            ->merge($title->relationLoaded('tags') ? $title->tags : collect())
            ->take($this->layout === 'grid' ? 3 : 4);
    }

    public function render(): View
    {
        return view(match ($this->layout) {
            'grid' => 'components.catalog.title-card-grid',
            'recommendation' => 'components.catalog.title-card-recommendation',
            default => 'components.catalog.title-card-horizontal',
        });
    }
}
