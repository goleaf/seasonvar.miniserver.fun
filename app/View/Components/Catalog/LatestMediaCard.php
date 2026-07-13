<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class LatestMediaCard extends Component
{
    public ?CatalogTitle $title;

    public ?Season $season;

    public ?Episode $episode;

    public ?string $seasonLabel;

    public ?string $episodeLabel;

    public ?string $qualityLabel;

    public string $meta;

    public function __construct(public LicensedMedia $media)
    {
        $this->title = $media->relationLoaded('catalogTitle') ? $media->catalogTitle : null;
        $this->season = $media->relationLoaded('season') ? $media->season : null;
        $this->episode = $media->relationLoaded('episode') ? $media->episode : null;
        $this->seasonLabel = $this->season?->number !== null ? 'Сезон '.$this->season->number : null;
        $this->episodeLabel = $this->episode?->number !== null ? $this->episode->number.' серия' : null;
        $this->qualityLabel = filled($media->quality) ? mb_strtoupper((string) $media->quality) : null;
        $this->meta = filled($media->card_meta) ? (string) $media->card_meta : 'Видео сериала';
    }

    public function shouldRender(): bool
    {
        return $this->title instanceof CatalogTitle;
    }

    public function url(): string
    {
        return route('titles.show', [
            'catalogTitle' => $this->title,
            'episode' => $this->media->episode_id,
            'media' => $this->media->id,
        ]).'#player';
    }

    public function posterAlt(): string
    {
        return 'Постер '.($this->title?->display_title ?? 'сериала');
    }

    public function render(): View
    {
        return view('components.catalog.latest-media-card');
    }
}
