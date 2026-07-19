<?php

namespace App\View\ViewModels;

use App\DTOs\PlaybackSourceData;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Media\ExternalMediaMetadata;
use App\Support\PlainText;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogShowViewModel
{
    /**
     * @var array<string, string>
     */
    public array $taxonomyLabels;

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

    public ?string $selectedMediaFileSizeLabel;

    public bool $selectedMediaIsDirectFile;

    public ?string $selectedMediaDownloadUrl;

    public ?string $selectedMediaLoginUrl;

    public ?string $selectedMediaDownloadFilename;

    public ?string $selectedMediaDownloadUnavailableReason;

    public ?string $selectedMediaDownloadDetail;

    /**
     * @var Collection<int, non-empty-string>
     */
    public Collection $selectedMediaBadges;

    /**
     * @var array<int, array{key: string, label: string, icon: string, options: list<array{mediaId: int, label: string, detail: string|null, icon: string, url: string, active: bool}>}>
     */
    public array $playbackOptionGroups;

    public ?int $selectedSeasonId;

    public ?Season $selectedSeason;

    public int $episodeCount;

    public int $taxonomyCount;

    public int $mediaCount;

    public int $parsedSeasonCount;

    public string $displayTitle;

    public string $displayOriginalTitle;

    public string $displayDescription;

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
        ?string $selectedMediaFileSizeLabel = null,
        bool $selectedMediaIsDirectFile = false,
        ?string $selectedMediaDownloadUrl = null,
        ?string $selectedMediaLoginUrl = null,
        ?string $selectedMediaDownloadFilename = null,
        ?string $selectedMediaDownloadUnavailableReason = null,
        ?int $episodeCount = null,
        ?int $taxonomyCount = null,
        ?int $parsedSeasonCount = null,
        ?int $mediaCount = null,
    ) {
        $this->taxonomyLabels = [
            'genre' => __('catalog.taxonomy.genres'),
            'country' => __('catalog.taxonomy.countries'),
            'actor' => __('catalog.taxonomy.actors'),
            'director' => __('catalog.taxonomy.directors'),
            'age_rating' => __('catalog.taxonomy.age_rating'),
            'translation' => __('catalog.taxonomy.translation'),
            'status' => __('catalog.taxonomy.status'),
            'network' => __('catalog.taxonomy.networks'),
            'studio' => __('catalog.taxonomy.studios'),
            'tag' => __('catalog.taxonomy.tags'),
        ];
        $titleAttributes = $this->title->getAttributes();
        $this->displayTitle = $this->title->display_title;
        $this->displayOriginalTitle = $this->title->display_original_title ?? '';
        $this->displayDescription = PlainText::clean($titleAttributes['description'] ?? '', 20000);
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
        $this->selectedMediaFormat = $this->playbackSource === null
            ? ''
            : ($this->playbackSource->format ?? '');
        $this->selectedMediaType = $this->playbackSource?->mimeType;
        $this->selectedEpisodeMediaItems = $this->selectedEpisode
            ? $this->mediaByEpisodeId->get($this->selectedEpisode->id, collect())
            : collect();
        $this->selectedVariantKey = $this->selectedMedia ? $this->mediaVariantKey($this->selectedMedia) : null;
        $this->selectedQuality = $this->selectedMedia ? $this->mediaQuality($this->selectedMedia) : null;
        $this->selectedFormat = $this->selectedMedia ? $this->mediaFormat($this->selectedMedia) : null;
        $this->selectedPlaybackLabel = $this->selectedMedia ? $this->playbackLabel($this->selectedMedia) : __('catalog.player.variant_not_selected');
        $this->selectedMediaFileSizeLabel = $selectedMediaFileSizeLabel;
        $this->selectedMediaIsDirectFile = $selectedMediaIsDirectFile;
        $this->selectedMediaDownloadUrl = $selectedMediaDownloadUrl;
        $this->selectedMediaLoginUrl = $selectedMediaLoginUrl;
        $this->selectedMediaDownloadFilename = $selectedMediaDownloadFilename;
        $this->selectedMediaDownloadUnavailableReason = $selectedMediaDownloadUnavailableReason;
        $this->selectedMediaDownloadDetail = collect([
            $selectedMediaFileSizeLabel,
            $this->selectedFormat ? Str::upper($this->selectedFormat) : null,
        ])->filter()->implode(' · ') ?: null;
        $this->selectedMediaBadges = $this->buildSelectedMediaBadges();
        $this->playbackOptionGroups = $this->buildPlaybackOptionGroups();
        $this->selectedSeasonId = $this->selectedEpisode !== null
            ? $this->selectedEpisode->season_id
            : $this->selectedMedia?->season_id;
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
     * @return Collection<int, non-empty-string>
     */
    public function seasonStatusBadges(Season $season): Collection
    {
        $releasedEpisodeLabel = null;

        if ($season->episodes_released !== null) {
            $releasedEpisodeLabel = trans_choice('catalog.counts.episodes', (int) $season->episodes_released);
        }

        $totalEpisodeLabel = $season->episodes_released !== null
            ? ($season->episodes_total !== null ? __('catalog.player.of_total', ['count' => $season->episodes_total]) : null)
            : null;

        return $this->stringBadges([
            $season->latest_episode_released_at?->format('d.m.Y'),
            $releasedEpisodeLabel,
            $totalEpisodeLabel,
            $season->translation_name,
        ]);
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

    public function episodeSelectedProfileLabel(Episode $episode): ?string
    {
        $media = $this->preferredMediaForEpisode($episode);

        return $media !== null ? $this->playbackLabel($media) : null;
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
            $media->season ? __('catalog.player.media_season', ['number' => $media->season->number]) : null,
            $media->episode ? __('catalog.player.media_episode', ['number' => $media->episode->number]) : null,
            $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : null,
            $this->variantDisplayLabel($media),
            $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : null,
        ])->filter()->implode(' / ');

        return $details !== '' ? $details : __('catalog.player.series_video');
    }

    /**
     * @return Collection<int, non-empty-string>
     */
    public function episodeVariantBadges(Episode $episode): Collection
    {
        $mediaItems = $this->episodeMediaItems($episode);

        if ($mediaItems->isEmpty()) {
            return collect();
        }

        return $this->stringBadges([
            $mediaItems->count() > 1 ? trans_choice('catalog.counts.variants', $mediaItems->count()) : null,
            $mediaItems->contains(fn (LicensedMedia $media): bool => $this->mediaHasSubtitles($media)) ? __('catalog.player.subtitles_lower') : null,
            $this->bestQualityLabel($mediaItems),
        ])->unique()->values();
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, options: list<array{mediaId: int, label: string, detail: string|null, icon: string, url: string, active: bool}>}>
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
                'key' => 'variant',
                'label' => __('catalog.player.translation_variants'),
                'icon' => 'fa-solid fa-language',
                'options' => $this->playbackOptions(
                    $this->selectedEpisodeMediaItems,
                    'variant',
                    fn (LicensedMedia $media): string => $this->mediaVariantKey($media),
                    fn (LicensedMedia $media): string => $this->variantDisplayLabel($media),
                    'fa-solid fa-language',
                ),
            ],
            [
                'key' => 'quality',
                'label' => __('catalog.player.quality'),
                'icon' => 'fa-solid fa-display',
                'options' => $this->playbackOptions(
                    $variantMediaItems,
                    'quality',
                    fn (LicensedMedia $media): ?string => $this->mediaQuality($media),
                    fn (LicensedMedia $media): string => $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : __('catalog.player.quality_missing'),
                    'fa-solid fa-display',
                ),
            ],
            [
                'key' => 'format',
                'label' => __('catalog.player.format'),
                'icon' => 'fa-solid fa-file-video',
                'options' => $this->playbackOptions(
                    $qualityMediaItems,
                    'format',
                    fn (LicensedMedia $media): ?string => $this->mediaFormat($media),
                    fn (LicensedMedia $media): string => $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : __('catalog.player.stream'),
                    'fa-solid fa-file-video',
                ),
            ],
        ])
            ->map(fn (array $group): array => [
                'key' => $group['key'],
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
     * @return list<array{mediaId: int, format: string|null, label: string, detail: string|null, icon: string, url: string, active: bool}>
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
                    'mediaId' => $media->id,
                    'format' => $this->mediaFormat($media),
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
     * @return Collection<int, non-empty-string>
     */
    private function buildSelectedMediaBadges(): Collection
    {
        if ($this->selectedMedia === null) {
            return collect();
        }

        return $this->stringBadges([
            $this->variantDisplayLabel($this->selectedMedia),
            $this->selectedQuality ? Str::upper($this->selectedQuality) : null,
            $this->selectedFormat ? Str::upper($this->selectedFormat) : null,
        ])->unique()->values();
    }

    private function playbackLabel(LicensedMedia $media): string
    {
        return collect([
            $this->variantDisplayLabel($media),
            $this->mediaQuality($media) ? Str::upper($this->mediaQuality($media)) : null,
            $this->mediaFormat($media) ? Str::upper($this->mediaFormat($media)) : null,
        ])->filter()->implode(' / ') ?: __('catalog.player.video');
    }

    private function variantDisplayLabel(LicensedMedia $media): string
    {
        $variant = $this->mediaVariant($media);

        return match ($variant['variant_type']) {
            'subtitles' => __('catalog.player.subtitles'),
            'original' => __('catalog.player.original'),
            'trailer' => __('catalog.player.trailer'),
            default => $variant['variant_name'] ?: $media->translation_name ?: __('catalog.player.voiceover'),
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

        return trim($url) !== '' ? $url : null;
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
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->all();
    }

    /** @param Collection<int, LicensedMedia> $mediaItems */
    private function bestQualityLabel(Collection $mediaItems): ?string
    {
        return $mediaItems
            ->map(fn (LicensedMedia $media): ?string => $this->mediaQuality($media))
            ->filter()
            ->sortBy(fn (string $quality): int => $this->qualityRank($quality))
            ->map(fn (string $quality): string => Str::upper($quality))
            ->first();
    }

    /**
     * @param  list<mixed>  $values
     * @return Collection<int, non-empty-string>
     */
    private function stringBadges(array $values): Collection
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values();
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
            ['label' => __('catalog.taxonomy.genre'), 'items' => $this->genres, 'icon' => $this->taxonomyIcon('genre')],
            ['label' => __('catalog.taxonomy.age_rating'), 'items' => $this->ageRatings, 'icon' => $this->taxonomyIcon('age_rating')],
            ['label' => __('catalog.taxonomy.country'), 'items' => $this->countries, 'icon' => $this->taxonomyIcon('country')],
            ['label' => __('catalog.taxonomy.director'), 'items' => $this->directors, 'icon' => $this->taxonomyIcon('director')],
            ['label' => __('catalog.taxonomy.translation'), 'items' => $this->translations, 'icon' => $this->taxonomyIcon('translation')],
            ['label' => __('catalog.taxonomy.status'), 'items' => $this->statuses, 'icon' => $this->taxonomyIcon('status')],
            ['label' => __('catalog.taxonomy.network'), 'items' => $this->networks, 'icon' => $this->taxonomyIcon('network')],
            ['label' => __('catalog.taxonomy.studio'), 'items' => $this->studios, 'icon' => $this->taxonomyIcon('studio')],
            ['label' => __('catalog.taxonomy.tags'), 'items' => $this->tags, 'icon' => $this->taxonomyIcon('tag')],
        ];
    }
}
