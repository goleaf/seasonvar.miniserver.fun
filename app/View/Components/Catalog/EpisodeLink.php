<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class EpisodeLink extends Component
{
    public bool $selected;

    public bool $hasMedia;

    public string $url;

    public string $statusIcon;

    public string $statusLabel;

    public string $statusVariant;

    public ?string $releasedAtLabel;

    /**
     * @var Collection<int, string>
     */
    public Collection $variantBadges;

    public function __construct(
        public CatalogTitle $title,
        public Episode $episode,
        public CatalogShowViewModel $showView,
    ) {
        $this->selected = $showView->isSelectedEpisode($episode);
        $this->hasMedia = $showView->episodeHasMedia($episode);
        $this->url = route('titles.show', $showView->episodeQuery($episode)).'#player';
        $this->statusIcon = $this->hasMedia ? 'fa-solid fa-play' : 'fa-solid fa-clock';
        $this->statusLabel = $this->hasMedia ? 'видео' : '';
        $this->statusVariant = $this->hasMedia ? 'success' : 'muted';
        $this->releasedAtLabel = $episode->released_at?->format('d.m.Y');
        $this->variantBadges = $showView->episodeVariantBadges($episode);
    }

    public function classes(): string
    {
        $stateClasses = $this->selected
            ? 'bg-emerald-50'
            : 'bg-white hover:bg-emerald-50';

        return 'block min-h-16 rounded-lg px-3 py-3 text-sm transition '.$stateClasses;
    }

    /**
     * @return array<string, string>
     */
    public function linkAttributes(): array
    {
        $attributes = ['class' => $this->classes()];

        if ($this->selected) {
            $attributes['aria-current'] = 'true';
        }

        return $attributes;
    }

    public function render(): View
    {
        return view('components.catalog.episode-link');
    }
}
