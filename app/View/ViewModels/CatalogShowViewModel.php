<?php

namespace App\View\ViewModels;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Support\Collection;

class CatalogShowViewModel
{
    /**
     * @var array<string, string>
     */
    public array $taxonomyLabels = [
        'genre' => 'Жанры',
        'country' => 'Страны',
        'actor' => 'Актеры',
        'director' => 'Режиссеры',
        'age_rating' => 'Возраст',
        'translation' => 'Перевод',
        'status' => 'Статус',
        'network' => 'Каналы',
        'studio' => 'Студии',
        'tag' => 'Теги',
    ];

    /**
     * @var array<string, string>
     */
    public array $taxonomyIcons = [
        'genre' => 'fa-solid fa-masks-theater',
        'country' => 'fa-solid fa-earth-europe',
        'actor' => 'fa-solid fa-user-group',
        'director' => 'fa-solid fa-video',
        'age_rating' => 'fa-solid fa-shield-halved',
        'translation' => 'fa-solid fa-language',
        'status' => 'fa-solid fa-signal',
        'network' => 'fa-solid fa-tower-broadcast',
        'studio' => 'fa-solid fa-building',
        'tag' => 'fa-solid fa-tag',
    ];

    /**
     * @var Collection<string, Collection<int, mixed>>
     */
    public Collection $taxonomyGroups;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $genres;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $countries;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $actors;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $directors;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $ageRatings;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $translations;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $statuses;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $networks;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $studios;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $tags;

    /**
     * @var array<int, array{label: string, items: Collection<int, mixed>, icon: string}>
     */
    public array $taxonomyRows;

    /**
     * @var Collection<int, Collection<int, LicensedMedia>>
     */
    public Collection $mediaByEpisodeId;

    /**
     * @var Collection<int, LicensedMedia>
     */
    public Collection $selectedEpisodeMediaItems;

    /**
     * @var Collection<int, mixed>
     */
    public Collection $topTaxonomies;

    public ?string $selectedMediaUrl;

    public string $selectedMediaFormat;

    public ?string $selectedMediaType;

    public ?int $selectedSeasonId;

    public int $episodeCount;

    public int $taxonomyCount;

    public int $mediaCount;

    public int $parsedSeasonCount;

    /**
     * @param  Collection<string, Collection<int, mixed>>  $taxonomiesByType
     * @param  Collection<int, Season>  $seasons
     * @param  Collection<int, LicensedMedia>  $mediaItems
     */
    public function __construct(
        public readonly CatalogTitle $title,
        public readonly Collection $taxonomiesByType,
        public readonly Collection $seasons,
        public readonly Collection $mediaItems,
        public readonly ?Episode $selectedEpisode,
        public readonly ?LicensedMedia $selectedMedia,
        ?int $episodeCount = null,
        ?int $taxonomyCount = null,
        ?int $parsedSeasonCount = null,
        ?int $mediaCount = null,
    ) {
        $this->taxonomyGroups = $this->taxonomiesByType;
        $this->genres = $this->taxonomies('genre');
        $this->countries = $this->taxonomies('country');
        $this->actors = $this->taxonomies('actor');
        $this->directors = $this->taxonomies('director');
        $this->ageRatings = $this->taxonomies('age_rating');
        $this->translations = $this->taxonomies('translation');
        $this->statuses = $this->taxonomies('status');
        $this->networks = $this->taxonomies('network');
        $this->studios = $this->taxonomies('studio');
        $this->tags = $this->taxonomies('tag');
        $this->taxonomyRows = $this->buildTaxonomyRows();
        $this->mediaByEpisodeId = $this->mediaItems->whereNotNull('episode_id')->groupBy('episode_id');
        $this->selectedMediaUrl = $this->selectedMedia ? ($this->selectedMedia->playback_url ?: $this->selectedMedia->path) : null;
        $selectedMediaPath = parse_url((string) $this->selectedMediaUrl, PHP_URL_PATH);
        $this->selectedMediaFormat = strtolower($this->selectedMedia?->format ?: pathinfo((string) $selectedMediaPath, PATHINFO_EXTENSION));
        $this->selectedMediaType = match ($this->selectedMediaFormat) {
            'm3u8' => 'application/x-mpegURL',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => null,
        };
        $this->selectedEpisodeMediaItems = $this->selectedEpisode
            ? $this->mediaByEpisodeId->get($this->selectedEpisode->id, collect())
            : collect();
        $this->selectedSeasonId = $this->selectedEpisode?->season_id ?? $this->selectedMedia?->season_id;
        $this->episodeCount = $episodeCount ?? $this->seasons->sum(fn (Season $season): int => $this->seasonEpisodeCount($season));
        $this->taxonomyCount = $taxonomyCount ?? $this->taxonomiesByType->sum(fn (Collection $items): int => $items->count());
        $this->mediaCount = $mediaCount ?? $this->mediaItems->count();
        $this->parsedSeasonCount = $parsedSeasonCount ?? $this->seasons->filter(fn (Season $season): bool => $season->episodes->isNotEmpty())->count();
        $this->topTaxonomies = $this->genres
            ->merge($this->countries)
            ->merge($this->ageRatings)
            ->merge($this->translations)
            ->merge($this->statuses)
            ->merge($this->tags)
            ->take(16);
    }

    public function taxonomyIcon(string $taxonomyType): string
    {
        return $this->taxonomyIcons[$taxonomyType] ?? 'fa-solid fa-tag';
    }

    public function taxonomyLabel(string $taxonomyType): string
    {
        return $this->taxonomyLabels[$taxonomyType] ?? $taxonomyType;
    }

    public function seasonEpisodeCount(Season $season): int
    {
        return (int) $season->episodes->count();
    }

    public function isSelectedSeason(Season $season, bool $isFirst): bool
    {
        return ($this->selectedSeasonId !== null && (int) $this->selectedSeasonId === (int) $season->id)
            || ($this->selectedSeasonId === null && $isFirst);
    }

    /**
     * @return Collection<int, string>
     */
    public function seasonStatusBadges(Season $season): Collection
    {
        $releasedEpisodeLabel = null;

        if ($season->episodes_released !== null) {
            $releasedEpisodeLabel = $season->episodes_released.' '.$this->episodePlural((int) $season->episodes_released);
        }

        $totalEpisodeLabel = $season->episodes_released !== null
            ? ($season->episodes_total !== null ? 'из '.$season->episodes_total : null)
            : null;

        return collect([
            $season->latest_episode_released_at?->format('d.m.Y'),
            $releasedEpisodeLabel,
            $totalEpisodeLabel,
            $season->translation_name,
        ])->filter()->values();
    }

    /**
     * @return Collection<int, LicensedMedia>
     */
    public function episodeMediaItems(Episode $episode): Collection
    {
        return $this->mediaByEpisodeId->get($episode->id, collect());
    }

    public function episodeHasMedia(Episode $episode): bool
    {
        return $this->episodeMediaItems($episode)->isNotEmpty();
    }

    public function isSelectedEpisode(Episode $episode): bool
    {
        return $this->selectedEpisode?->id === $episode->id;
    }

    /**
     * @return array{catalogTitle: CatalogTitle, episode: int|null, media: int}
     */
    public function variantQuery(LicensedMedia $episodeMedia): array
    {
        return [
            'catalogTitle' => $this->title,
            'episode' => $this->selectedEpisode?->id,
            'media' => $episodeMedia->id,
        ];
    }

    public function variantLabel(LicensedMedia $episodeMedia): string
    {
        return collect([
            $episodeMedia->quality ? strtoupper($episodeMedia->quality) : null,
            $episodeMedia->translation_name,
            $episodeMedia->format ? strtoupper($episodeMedia->format) : null,
        ])->filter()->implode(' / ') ?: 'Видео';
    }

    /**
     * @return array{catalogTitle: CatalogTitle, media: int, episode?: int}
     */
    public function mediaQuery(LicensedMedia $media): array
    {
        $query = ['catalogTitle' => $this->title, 'media' => $media->id];

        if ($media->episode_id) {
            $query['episode'] = $media->episode_id;
        }

        return $query;
    }

    public function mediaDetailsLabel(LicensedMedia $media): string
    {
        $details = collect([
            $media->season ? 'Сезон '.$media->season->number : null,
            $media->episode ? 'Серия '.$media->episode->number : null,
            $media->quality ? strtoupper($media->quality) : null,
            $media->format ? strtoupper($media->format) : null,
        ])->filter()->implode(' / ');

        return $details !== '' ? $details : 'Видео сериала';
    }

    /**
     * @return Collection<int, mixed>
     */
    private function taxonomies(string $type): Collection
    {
        return $this->taxonomiesByType->get($type, collect());
    }

    /**
     * @return array<int, array{label: string, items: Collection<int, mixed>, icon: string}>
     */
    private function buildTaxonomyRows(): array
    {
        return [
            ['label' => 'Жанр', 'items' => $this->genres, 'icon' => $this->taxonomyIcon('genre')],
            ['label' => 'Ограничение', 'items' => $this->ageRatings, 'icon' => $this->taxonomyIcon('age_rating')],
            ['label' => 'Страна', 'items' => $this->countries, 'icon' => $this->taxonomyIcon('country')],
            ['label' => 'Режиссер', 'items' => $this->directors, 'icon' => $this->taxonomyIcon('director')],
            ['label' => 'Перевод', 'items' => $this->translations, 'icon' => $this->taxonomyIcon('translation')],
            ['label' => 'Статус', 'items' => $this->statuses, 'icon' => $this->taxonomyIcon('status')],
            ['label' => 'Канал', 'items' => $this->networks, 'icon' => $this->taxonomyIcon('network')],
            ['label' => 'Студия', 'items' => $this->studios, 'icon' => $this->taxonomyIcon('studio')],
        ];
    }

    private function episodePlural(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'серия',
            in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) => 'серии',
            default => 'серий',
        };
    }
}
