<?php

namespace App\View\ViewModels;

use App\DTOs\PlaybackSourceData;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Media\ExternalMediaMetadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        'age_rating' => 'Возрастной рейтинг',
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

    public ?string $selectedVariantKey;

    public ?string $selectedQuality;

    public ?string $selectedFormat;

    public string $selectedPlaybackLabel;

    /**
     * @var Collection<int, string>
     */
    public Collection $selectedMediaBadges;

    /**
     * @var array<int, array{label: string, icon: string, options: list<array{label: string, detail: string|null, icon: string, url: string, active: bool}>}>
     */
    public array $playbackOptionGroups;

    public ?int $selectedSeasonId;

    public ?Season $selectedSeason;

    public int $episodeCount;

    public int $taxonomyCount;

    public int $mediaCount;

    public int $parsedSeasonCount;

    /**
     * @var array<int, array{variant_type: string, variant_name: string|null, variant_key: string, has_subtitles: bool}>
     */
    private array $playbackVariantCache = [];

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
        public readonly ExternalMediaMetadata $mediaMetadata,
        public readonly ?PlaybackSourceData $playbackSource = null,
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
        $this->selectedMediaUrl = $this->playbackSource?->url;
        $this->selectedMediaFormat = $this->playbackSource?->format ?? '';
        $this->selectedMediaType = $this->playbackSource?->mimeType;
        $this->selectedEpisodeMediaItems = $this->selectedEpisode
            ? $this->mediaByEpisodeId->get($this->selectedEpisode->id, collect())
            : collect();
        $this->selectedVariantKey = $this->selectedMedia ? $this->mediaVariantKey($this->selectedMedia) : null;
        $this->selectedQuality = $this->selectedMedia ? $this->mediaQuality($this->selectedMedia) : null;
        $this->selectedFormat = $this->selectedMedia ? $this->mediaFormat($this->selectedMedia) : null;
        $this->selectedPlaybackLabel = $this->selectedMedia ? $this->playbackLabel($this->selectedMedia) : 'Вариант не выбран';
        $this->selectedMediaBadges = $this->buildSelectedMediaBadges();
        $this->playbackOptionGroups = $this->buildPlaybackOptionGroups();
        $this->selectedSeasonId = $this->selectedEpisode?->season_id ?? $this->selectedMedia?->season_id;
        $this->selectedSeason = $this->selectedSeasonId !== null
            ? $this->seasons->firstWhere('id', $this->selectedSeasonId)
            : null;
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
     * @return array{catalogTitle: CatalogTitle, episode: int|null, media: int, variant?: string, quality?: string, format?: string}
     */
    public function variantQuery(LicensedMedia $episodeMedia): array
    {
        return $this->mediaQuery($episodeMedia);
    }

    public function variantLabel(LicensedMedia $episodeMedia): string
    {
        return $this->playbackLabel($episodeMedia);
    }

    /**
     * @return array{catalogTitle: CatalogTitle, media: int, episode?: int, variant?: string, quality?: string, format?: string}
     */
    public function mediaQuery(LicensedMedia $media): array
    {
        $query = ['catalogTitle' => $this->title, 'media' => $media->id];

        if ($media->episode_id) {
            $query['episode'] = $media->episode_id;
        }

        return $this->withMediaProfile($query, $media);
    }

    /**
     * @return array{catalogTitle: CatalogTitle, episode: int, media?: int, variant?: string, quality?: string, format?: string}
     */
    public function episodeQuery(Episode $episode): array
    {
        $query = [
            'catalogTitle' => $this->title,
            'episode' => $episode->id,
        ];
        $media = $this->preferredMediaForEpisode($episode);

        if ($media !== null) {
            $query['media'] = $media->id;

            return $this->withMediaProfile($query, $media);
        }

        foreach ($this->selectedProfileQuery() as $key => $value) {
            $query[$key] = $value;
        }

        return $query;
    }

    public function mediaDetailsLabel(LicensedMedia $media): string
    {
        $details = collect([
            $media->season ? 'Сезон '.$media->season->number : null,
            $media->episode ? 'Серия '.$media->episode->number : null,
            $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : null,
            $this->variantDisplayLabel($media),
            $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : null,
        ])->filter()->implode(' / ');

        return $details !== '' ? $details : 'Видео сериала';
    }

    /**
     * @return Collection<int, string>
     */
    public function episodeVariantBadges(Episode $episode): Collection
    {
        $mediaItems = $this->episodeMediaItems($episode);

        if ($mediaItems->isEmpty()) {
            return collect();
        }

        return collect([
            $mediaItems->count() > 1 ? $mediaItems->count().' '.$this->variantPlural($mediaItems->count()) : null,
            $mediaItems->contains(fn (LicensedMedia $media): bool => $this->mediaHasSubtitles($media)) ? 'субтитры' : null,
            $this->bestQualityLabel($mediaItems),
        ])->filter()->unique()->values();
    }

    /**
     * @return array<int, array{label: string, icon: string, options: list<array{label: string, detail: string|null, icon: string, url: string, active: bool}>}>
     */
    private function buildPlaybackOptionGroups(): array
    {
        if ($this->selectedEpisodeMediaItems->isEmpty()) {
            return [];
        }

        $variantMediaItems = $this->selectedVariantMediaItems();
        $qualityMediaItems = $this->selectedQualityMediaItems($variantMediaItems);

        return collect([
            [
                'label' => 'Варианты перевода',
                'icon' => 'fa-solid fa-language',
                'options' => $this->playbackOptions(
                    $this->selectedEpisodeMediaItems,
                    'variant',
                    fn (LicensedMedia $media): ?string => $this->mediaVariantKey($media),
                    fn (LicensedMedia $media): string => $this->variantDisplayLabel($media),
                    'fa-solid fa-language',
                ),
            ],
            [
                'label' => 'Качество',
                'icon' => 'fa-solid fa-display',
                'options' => $this->playbackOptions(
                    $variantMediaItems,
                    'quality',
                    fn (LicensedMedia $media): ?string => $this->mediaQuality($media),
                    fn (LicensedMedia $media): string => $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : 'Без качества',
                    'fa-solid fa-display',
                ),
            ],
            [
                'label' => 'Формат',
                'icon' => 'fa-solid fa-file-video',
                'options' => $this->playbackOptions(
                    $qualityMediaItems,
                    'format',
                    fn (LicensedMedia $media): ?string => $this->mediaFormat($media),
                    fn (LicensedMedia $media): string => $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : 'Поток',
                    'fa-solid fa-file-video',
                ),
            ],
        ])
            ->map(fn (array $group): array => [
                'label' => $group['label'],
                'icon' => $group['icon'],
                'options' => $group['options'],
            ])
            ->filter(fn (array $group): bool => count($group['options']) > 1)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     * @return list<array{label: string, detail: string|null, icon: string, url: string, active: bool}>
     */
    private function playbackOptions(Collection $mediaItems, string $key, callable $valueResolver, callable $labelResolver, string $icon): array
    {
        return $mediaItems
            ->map(function (LicensedMedia $media) use ($key, $valueResolver, $labelResolver, $icon): ?array {
                $value = $valueResolver($media);

                if (! is_string($value) || $value === '') {
                    return null;
                }

                $query = $this->mediaQuery($media);
                $query[$key] = $value;

                return [
                    'label' => $labelResolver($media),
                    'detail' => $this->mediaDetailsLabel($media),
                    'icon' => $icon,
                    'url' => route('titles.show', $query).'#player',
                    'active' => $this->selectedMedia?->id === $media->id,
                ];
            })
            ->filter()
            ->sortBy(fn (array $option): string => ($option['active'] ? '0' : '1').$option['label'])
            ->unique(fn (array $option): string => $option['label'])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, LicensedMedia>
     */
    private function selectedVariantMediaItems(): Collection
    {
        if ($this->selectedVariantKey === null) {
            return $this->selectedEpisodeMediaItems;
        }

        $matches = $this->selectedEpisodeMediaItems
            ->filter(fn (LicensedMedia $media): bool => $this->mediaVariantKey($media) === $this->selectedVariantKey)
            ->values();

        return $matches->isNotEmpty() ? $matches : $this->selectedEpisodeMediaItems;
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     * @return Collection<int, LicensedMedia>
     */
    private function selectedQualityMediaItems(Collection $mediaItems): Collection
    {
        if ($this->selectedQuality === null) {
            return $mediaItems;
        }

        $matches = $mediaItems
            ->filter(fn (LicensedMedia $media): bool => $this->sameNormalizedValue($this->mediaQuality($media), $this->selectedQuality))
            ->values();

        return $matches->isNotEmpty() ? $matches : $mediaItems;
    }

    /**
     * @return Collection<int, string>
     */
    private function buildSelectedMediaBadges(): Collection
    {
        if ($this->selectedMedia === null) {
            return collect();
        }

        return collect([
            $this->variantDisplayLabel($this->selectedMedia),
            $this->selectedQuality ? Str::upper($this->selectedQuality) : null,
            $this->selectedFormat ? Str::upper($this->selectedFormat) : null,
        ])->filter()->unique()->values();
    }

    private function playbackLabel(LicensedMedia $media): string
    {
        return collect([
            $this->variantDisplayLabel($media),
            $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : null,
            $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : null,
        ])->filter()->implode(' / ') ?: 'Видео';
    }

    private function variantDisplayLabel(LicensedMedia $media): string
    {
        $variant = $this->mediaVariant($media);

        return match ($variant['variant_type']) {
            'subtitles' => 'Субтитры',
            'original' => 'Оригинал',
            'trailer' => 'Трейлер',
            default => $variant['variant_name'] ?: $media->translation_name ?: 'Озвучка',
        };
    }

    private function mediaVariantKey(LicensedMedia $media): string
    {
        return $this->mediaVariant($media)['variant_key'];
    }

    /**
     * @return array{variant_type: string, variant_name: string|null, variant_key: string, has_subtitles: bool}
     */
    private function mediaVariant(LicensedMedia $media): array
    {
        $cacheKey = (int) ($media->getKey() ?? spl_object_id($media));

        if (isset($this->playbackVariantCache[$cacheKey])) {
            return $this->playbackVariantCache[$cacheKey];
        }

        $url = $this->mediaUrl($media);
        $detected = $url !== null
            ? $this->mediaMetadata->playbackVariant($media->title, $media->source_url, $url)
            : [
                'variant_type' => 'voiceover',
                'variant_name' => null,
                'variant_key' => 'voiceover-default',
                'has_subtitles' => false,
            ];
        $variantType = is_string($media->variant_type) && $media->variant_type !== ''
            ? $media->variant_type
            : $detected['variant_type'];
        $variantName = is_string($media->variant_name) && $media->variant_name !== ''
            ? $media->variant_name
            : $detected['variant_name'];
        $variantKey = is_string($media->variant_key) && $media->variant_key !== ''
            ? $media->variant_key
            : $this->mediaMetadata->playbackVariantKey($variantType, $variantName);

        return $this->playbackVariantCache[$cacheKey] = [
            'variant_type' => $variantType,
            'variant_name' => $variantName,
            'variant_key' => $variantKey,
            'has_subtitles' => (bool) $media->has_subtitles || $detected['has_subtitles'],
        ];
    }

    private function mediaQuality(LicensedMedia $media): ?string
    {
        if (is_string($media->quality) && $media->quality !== '') {
            return Str::lower($media->quality);
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->quality($media->title, $url) : null;
    }

    private function mediaFormat(LicensedMedia $media): ?string
    {
        if (is_string($media->format) && $media->format !== '') {
            return Str::lower($media->format);
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->format($url) : null;
    }

    private function mediaHasSubtitles(LicensedMedia $media): bool
    {
        return $this->mediaVariant($media)['has_subtitles'];
    }

    private function mediaUrl(LicensedMedia $media): ?string
    {
        $url = $media->playback_url ?: $media->path;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function preferredMediaForEpisode(Episode $episode): ?LicensedMedia
    {
        $mediaItems = $this->episodeMediaItems($episode);

        if ($mediaItems->isEmpty()) {
            return null;
        }

        $candidates = $mediaItems;

        if ($this->selectedVariantKey !== null) {
            $matches = $candidates->filter(fn (LicensedMedia $media): bool => $this->mediaVariantKey($media) === $this->selectedVariantKey)->values();
            $candidates = $matches->isNotEmpty() ? $matches : $candidates;
        }

        if ($this->selectedQuality !== null) {
            $matches = $candidates->filter(fn (LicensedMedia $media): bool => $this->sameNormalizedValue($this->mediaQuality($media), $this->selectedQuality))->values();
            $candidates = $matches->isNotEmpty() ? $matches : $candidates;
        }

        if ($this->selectedFormat !== null) {
            $matches = $candidates->filter(fn (LicensedMedia $media): bool => $this->sameNormalizedValue($this->mediaFormat($media), $this->selectedFormat))->values();
            $candidates = $matches->isNotEmpty() ? $matches : $candidates;
        }

        return $candidates->first();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withMediaProfile(array $query, LicensedMedia $media): array
    {
        foreach ([
            'variant' => $this->mediaVariantKey($media),
            'quality' => $this->mediaQuality($media),
            'format' => $this->mediaFormat($media),
        ] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function selectedProfileQuery(): array
    {
        return collect([
            'variant' => $this->selectedVariantKey,
            'quality' => $this->selectedQuality,
            'format' => $this->selectedFormat,
        ])
            ->filter(fn (?string $value): bool => is_string($value) && $value !== '')
            ->all();
    }

    private function bestQualityLabel(Collection $mediaItems): ?string
    {
        return $mediaItems
            ->map(fn (LicensedMedia $media): ?string => $this->mediaQuality($media))
            ->filter()
            ->sortBy(fn (string $quality): int => $this->qualityRank($quality))
            ->map(fn (string $quality): string => Str::upper($quality))
            ->first();
    }

    private function qualityRank(string $quality): int
    {
        return match (Str::lower($quality)) {
            '4320p' => 0,
            '2160p' => 1,
            '1440p' => 2,
            '1080p' => 3,
            '720p' => 4,
            '576p', '540p' => 5,
            '480p' => 6,
            '360p' => 7,
            '240p' => 8,
            default => 99,
        };
    }

    private function variantPlural(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'вариант',
            in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) => 'варианта',
            default => 'вариантов',
        };
    }

    private function sameNormalizedValue(?string $actual, string $expected): bool
    {
        return $actual !== null && Str::lower($actual) === Str::lower($expected);
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
            ['label' => 'Возрастной рейтинг', 'items' => $this->ageRatings, 'icon' => $this->taxonomyIcon('age_rating')],
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
