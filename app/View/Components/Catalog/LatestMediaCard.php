<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Services\Auth\AccountDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\View\Component;

final class LatestMediaCard extends Component
{
    public string $titleUrl;

    public string $episodesUrl;

    public ?string $latestUrl;

    public string $posterAlt;

    public string $displayTitle;

    public string $summaryLabel;

    public string $metadataLabel;

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
        public int $episodeCount = 0,
        public int $mediaCount = 0,
        public ?int $episodeMin = null,
        public ?int $episodeMax = null,
    ) {
        $this->titleUrl = route('titles.show', $title);
        $this->episodesUrl = $this->titleUrl.'#seasons';
        $this->displayTitle = filled($title->display_title)
            ? (string) $title->display_title
            : __('catalog.title.untitled');
        $this->posterAlt = __('catalog.seo.poster_alt', ['title' => $this->displayTitle]);
        $this->episodeCount = $episodeCount > 0 ? $episodeCount : $episodes->count();
        $this->mediaCount = $mediaCount > 0 ? $mediaCount : $media->count();
        $episodeNumbers = $episodes
            ->pluck('number')
            ->filter(fn (mixed $number): bool => is_numeric($number))
            ->map(fn (mixed $number): int => (int) $number);
        $this->episodeMin = $episodeMin ?? ($episodeNumbers->isEmpty() ? null : $episodeNumbers->min());
        $this->episodeMax = $episodeMax ?? ($episodeNumbers->isEmpty() ? null : $episodeNumbers->max());
        $this->latestUrl = $this->latestMediaUrl();
        $this->summaryLabel = $this->summary();
        $this->metadataLabel = $this->metadata();
    }

    public function render(): View
    {
        return view('components.catalog.latest-media-card');
    }

    private function summary(): string
    {
        if ($this->episodeCount === 1 && $this->episodeMin !== null) {
            return __('home.updates.episode_single', [
                'number' => Number::format($this->episodeMin, locale: app()->currentLocale()),
            ]);
        }

        if ($this->episodeCount > 0
            && $this->episodeMin !== null
            && $this->episodeMax !== null
            && ($this->episodeMax - $this->episodeMin + 1) === $this->episodeCount) {
            return __('home.updates.episodes_range', [
                'first' => Number::format($this->episodeMin, locale: app()->currentLocale()),
                'last' => Number::format($this->episodeMax, locale: app()->currentLocale()),
            ]);
        }

        $count = $this->episodeCount > 0 ? $this->episodeCount : $this->mediaCount;

        return trans_choice(
            $this->episodeCount > 0 ? 'home.updates.episodes_added' : 'home.updates.videos_added',
            $count,
            ['count' => Number::format($count, locale: app()->currentLocale())],
        );
    }

    private function metadata(): string
    {
        $seasons = $this->episodes
            ->concat($this->media)
            ->map(fn (Episode|LicensedMedia $item): mixed => $item->relationLoaded('season') ? $item->season?->number : null)
            ->filter(fn (mixed $number): bool => $number !== null)
            ->unique()
            ->values();
        $seasonLabel = $seasons->count() === 1
            ? trans_choice('catalog.counts.seasons', 1, [
                'count' => Number::format(1, locale: app()->currentLocale()),
            ])
            : trans_choice('catalog.counts.seasons', $seasons->count(), [
                'count' => Number::format($seasons->count(), locale: app()->currentLocale()),
            ]);
        $newCount = $this->episodeCount > 0 ? $this->episodeCount : $this->mediaCount;
        $newLabel = trans_choice(
            $this->episodeCount > 0 ? 'home.updates.new_episodes_count' : 'home.updates.new_videos_count',
            $newCount,
            ['count' => Number::format($newCount, locale: app()->currentLocale())],
        );
        $addedAt = $this->latestAddedAt();

        if (! $addedAt instanceof CarbonInterface) {
            return $seasonLabel.' · '.$newLabel;
        }

        $locale = app()->currentLocale();
        $timezone = $this->timezone ?? (string) config('account-settings.default_timezone', 'UTC');

        return __('home.updates.metadata', [
            'season' => $seasonLabel,
            'count' => $newLabel,
            'date' => $this->dates->dateGroup($addedAt, $locale, $timezone),
            'time' => $this->dates->time($addedAt, $locale, $timezone),
        ]);
    }

    private function latestAddedAt(): ?CarbonInterface
    {
        return $this->episodes
            ->concat($this->media)
            ->map(fn (Episode|LicensedMedia $item): mixed => $item->created_at)
            ->filter(fn (mixed $value): bool => $value instanceof CarbonInterface)
            ->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())
            ->first();
    }

    private function latestMediaUrl(): ?string
    {
        $media = $this->media->first();

        if (! $media instanceof LicensedMedia) {
            return null;
        }

        return route('titles.show', [
            'catalogTitle' => $this->title,
            'episode' => $media->episode_id,
            'media' => $media->id,
        ]).'#player';
    }
}
