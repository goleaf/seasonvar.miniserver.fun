<?php

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class LatestMediaCard extends Component
{
    /**
     * @var list<array{
     *     key: string,
     *     season_label: string|null,
     *     episode_label: string|null,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, meta: string}>
     * }>
     */
    public array $items;

    public string $titleUrl;

    public string $posterAlt;

    /**
     * @param  Collection<int, Episode>  $episodes
     * @param  Collection<int, LicensedMedia>  $media
     */
    public function __construct(
        public CatalogTitle $title,
        public Collection $episodes,
        public Collection $media,
    ) {
        $this->titleUrl = route('titles.show', $title);
        $this->posterAlt = 'Постер '.$title->display_title;
        $this->items = $this->releaseItems();
    }

    /**
     * @return list<array{
     *     key: string,
     *     season_label: string|null,
     *     episode_label: string|null,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, meta: string}>
     * }>
     */
    private function releaseItems(): array
    {
        $items = [];

        foreach ($this->episodes as $episode) {
            $season = $episode->relationLoaded('season') ? $episode->season : null;
            $items['episode-'.$episode->id] = $this->episodeItem($episode, $season);
        }

        foreach ($this->media as $media) {
            $episode = $media->relationLoaded('episode') ? $media->episode : null;
            $season = $media->relationLoaded('season') ? $media->season : null;
            $key = $episode !== null ? 'episode-'.$episode->id : 'media-'.$media->id;

            if (! isset($items[$key])) {
                $items[$key] = $episode !== null
                    ? $this->episodeItem($episode, $season)
                    : $this->standaloneMediaItem($media, $season);
            }

            $items[$key]['media'][] = $this->mediaItem($media);
            $items[$key]['added_at'] = max(
                $items[$key]['added_at'],
                $media->created_at?->getTimestamp() ?? 0,
            );
        }

        return collect($items)
            ->sortByDesc('added_at')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     key: string,
     *     season_label: string|null,
     *     episode_label: string|null,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, meta: string}>
     * }
     */
    private function episodeItem(Episode $episode, ?Season $season): array
    {
        return [
            'key' => 'episode-'.$episode->id,
            'season_label' => $season?->number !== null ? 'Сезон '.$season->number : null,
            'episode_label' => $episode->number.' серия',
            'title' => filled($episode->title) ? (string) $episode->title : null,
            'added_at' => $episode->created_at?->getTimestamp() ?? 0,
            'media' => [],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     season_label: string|null,
     *     episode_label: null,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, meta: string}>
     * }
     */
    private function standaloneMediaItem(LicensedMedia $media, ?Season $season): array
    {
        return [
            'key' => 'media-'.$media->id,
            'season_label' => $season?->number !== null ? 'Сезон '.$season->number : null,
            'episode_label' => null,
            'title' => filled($media->title) ? (string) $media->title : null,
            'added_at' => $media->created_at?->getTimestamp() ?? 0,
            'media' => [],
        ];
    }

    /** @return array{key: int, url: string, title: string, quality: string|null, meta: string} */
    private function mediaItem(LicensedMedia $media): array
    {
        return [
            'key' => (int) $media->id,
            'url' => route('titles.show', [
                'catalogTitle' => $this->title,
                'episode' => $media->episode_id,
                'media' => $media->id,
            ]).'#player',
            'title' => (string) $media->title,
            'quality' => filled($media->quality) ? mb_strtoupper((string) $media->quality) : null,
            'meta' => collect([
                $media->translation_name,
                $media->format ? mb_strtoupper((string) $media->format) : null,
                $media->published_at?->format('d.m.Y'),
            ])->filter()->implode(' / ') ?: 'Видео сериала',
        ];
    }

    public function render(): View
    {
        return view('components.catalog.latest-media-card');
    }
}
