<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Contracts\View\View;
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

    public function __construct(
        public CatalogTitle $title,
        public Episode $episode,
        public CatalogShowViewModel $showView,
    ) {
        $this->selected = $showView->isSelectedEpisode($episode);
        $this->hasMedia = $showView->episodeHasMedia($episode);
        $this->url = route('titles.show', ['catalogTitle' => $title, 'episode' => $episode->id]).'#player';
        $this->statusIcon = $this->hasMedia ? 'fa-solid fa-play' : 'fa-solid fa-clock';
        $this->statusLabel = $this->hasMedia ? 'видео' : 'готовится';
        $this->statusVariant = $this->hasMedia ? 'success' : 'muted';
        $this->releasedAtLabel = $episode->released_at?->format('d.m.Y');
    }

    public function classes(): string
    {
        $stateClasses = $this->selected
            ? 'bg-emerald-50 ring-emerald-200'
            : 'bg-white ring-slate-200 hover:bg-emerald-50 hover:ring-emerald-200';

        return 'block rounded-lg px-3 py-2 text-sm ring-1 transition '.$stateClasses;
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
