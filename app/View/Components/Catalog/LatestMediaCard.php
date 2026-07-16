<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Auth\AccountDateTimeFormatter;
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
     *     update_type: string,
     *     update_type_label: string,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, translation: string|null, format: string|null, published_date: string|null, accessibility_label: string}>
     * }>
     */
    public array $items;

    public string $titleUrl;

    public string $posterAlt;

    public string $displayTitle;

    /**
     * @param  Collection<int, Episode>  $episodes
     * @param  Collection<int, LicensedMedia>  $media
     */
    public function __construct(
        public CatalogTitle $title,
        public Collection $episodes,
        public Collection $media,
        private readonly AccountDateTimeFormatter $dates,
        public ?string $timezone = null,
    ) {
        $this->titleUrl = route('titles.show', $title);
        $this->displayTitle = filled($title->display_title)
            ? (string) $title->display_title
            : __('catalog.title.untitled');
        $this->posterAlt = __('catalog.seo.poster_alt', ['title' => $this->displayTitle]);
        $this->items = $this->releaseItems();
    }

    /**
     * @return list<array{
     *     key: string,
     *     season_label: string|null,
     *     episode_label: string|null,
     *     update_type: string,
     *     update_type_label: string,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, translation: string|null, format: string|null, published_date: string|null, accessibility_label: string}>
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
     *     update_type: string,
     *     update_type_label: string,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, translation: string|null, format: string|null, published_date: string|null, accessibility_label: string}>
     * }
     */
    private function episodeItem(Episode $episode, ?Season $season): array
    {
        return [
            'key' => 'episode-'.$episode->id,
            'season_label' => $season?->number !== null
                ? __('catalog.release.season', ['number' => $season->number])
                : null,
            'episode_label' => $episode->number !== null
                ? __('catalog.release.episode', ['number' => $episode->number])
                : __('catalog.release.episode_without_number'),
            'update_type' => 'new_episode',
            'update_type_label' => __('home.update_types.new_episode'),
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
     *     update_type: string,
     *     update_type_label: string,
     *     title: string|null,
     *     added_at: int,
     *     media: list<array{key: int, url: string, title: string, quality: string|null, translation: string|null, format: string|null, published_date: string|null, accessibility_label: string}>
     * }
     */
    private function standaloneMediaItem(LicensedMedia $media, ?Season $season): array
    {
        return [
            'key' => 'media-'.$media->id,
            'season_label' => $season?->number !== null
                ? __('catalog.release.season', ['number' => $season->number])
                : null,
            'episode_label' => null,
            'update_type' => 'video_added',
            'update_type_label' => __('home.update_types.video_added'),
            'title' => filled($media->title) ? (string) $media->title : null,
            'added_at' => $media->created_at?->getTimestamp() ?? 0,
            'media' => [],
        ];
    }

    /** @return array{key: int, url: string, title: string, quality: string|null, translation: string|null, format: string|null, published_date: string|null, accessibility_label: string} */
    private function mediaItem(LicensedMedia $media): array
    {
        $title = filled($media->title) ? (string) $media->title : __('home.updates.video');

        return [
            'key' => (int) $media->id,
            'url' => route('titles.show', [
                'catalogTitle' => $this->title,
                'episode' => $media->episode_id,
                'media' => $media->id,
            ]).'#player',
            'title' => $title,
            'quality' => filled($media->quality) ? mb_strtoupper((string) $media->quality) : null,
            'translation' => filled($media->translation_name) ? (string) $media->translation_name : null,
            'format' => filled($media->format) ? mb_strtoupper((string) $media->format) : null,
            'published_date' => $media->published_at === null
                ? null
                : $this->dates->date(
                    $media->published_at,
                    app()->currentLocale(),
                    $this->timezone ?? (string) config('account-settings.default_timezone', 'UTC'),
                ),
            'accessibility_label' => __('home.updates.open_media', ['title' => $title]),
        ];
    }

    public function render(): View
    {
        return view('components.catalog.latest-media-card');
    }
}
