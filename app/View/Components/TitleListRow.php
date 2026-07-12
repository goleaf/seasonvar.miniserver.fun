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

    public ?string $latestSeasonLabel;

    public string $seasonsLabel;

    public string $episodesLabel;

    public string $mediaLabel;

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
        $this->latestSeason = $title->relationLoaded('latestSeason') ? $title->latestSeason : null;
        $this->latestSeasonLabel = $this->latestSeason ? $this->latestSeason->number.' сезон' : null;
        $this->seasonsLabel = $this->plural($this->seasonsCount, ['сезон', 'сезона', 'сезонов']);
        $this->episodesLabel = $this->plural($this->episodesCount, ['серия', 'серии', 'серий']);
        $this->mediaLabel = $this->plural($this->mediaCount, ['видео', 'видео', 'видео']);
        $this->posterClass = match (true) {
            $readable => 'aspect-[2/3] w-20 sm:w-24',
            $compact => 'h-24 w-16',
            default => 'h-20 w-14 sm:h-24 sm:w-16',
        };
        $this->baseClass = match (true) {
            $readable => 'group relative block overflow-hidden rounded-control p-3 transition hover:bg-emerald-50 sm:p-4',
            $compact => 'group relative block overflow-hidden rounded-control p-3 transition hover:bg-emerald-50',
            default => 'group relative block overflow-hidden rounded-control px-4 py-3 transition hover:bg-emerald-50',
        };
        $this->cardRelations = collect()
            ->merge($title->relationLoaded('genres') ? $title->genres : collect())
            ->merge($title->relationLoaded('countries') ? $title->countries : collect())
            ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
            ->merge($title->relationLoaded('translations') ? $title->translations : collect())
            ->merge($title->relationLoaded('tags') ? $title->tags : collect())
            ->take(4);
    }

    public function render(): View
    {
        return view('components.title-list-row');
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $forms
     */
    private function plural(int $count, array $forms): string
    {
        $absolute = abs($count) % 100;
        $last = $absolute % 10;

        $form = match (true) {
            $absolute > 10 && $absolute < 20 => $forms[2],
            $last === 1 => $forms[0],
            $last >= 2 && $last <= 4 => $forms[1],
            default => $forms[2],
        };

        return $count.' '.$form;
    }
}
