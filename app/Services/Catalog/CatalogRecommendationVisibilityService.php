<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;

final class CatalogRecommendationVisibilityService
{
    /** @var array<string, string> */
    private const FILTER_RELATIONS = [
        'genre' => 'genres',
        'country' => 'countries',
        'tag' => 'tags',
        'actor' => 'actors',
        'director' => 'directors',
        'translation' => 'translations',
        'studio' => 'studios',
    ];

    public function __construct(private readonly CatalogTitleQuery $titles) {}

    /**
     * @param  list<int>  $excludedIds
     * @return Builder<CatalogTitle>
     */
    public function eligible(
        CatalogRecommendationContext $context,
        bool $watchable,
        array $excludedIds = [],
    ): Builder {
        $query = $this->titles->visibleTo($context->user);

        if ($watchable) {
            $query->whereExists($this->mediaQuery($context)->selectRaw('1')->toBase());
        }

        if ($excludedIds !== []) {
            $query->whereKeyNot($excludedIds);
        }

        foreach (self::FILTER_RELATIONS as $key => $relation) {
            $slug = $context->filters[$key] ?? null;

            if (is_string($slug) && $slug !== '') {
                $query->whereHas($relation, function (Builder $query) use ($key, $slug): void {
                    $query->where('slug', $slug);

                    if ($key === 'tag') {
                        $query->whereIn('tags.id', Tag::query()->publiclyEligible()->select('tags.id'));
                    }
                });
            }
        }

        $yearFrom = $this->integerFilter($context, 'year_from', 1900, now()->year + 5);
        $yearTo = $this->integerFilter($context, 'year_to', 1900, now()->year + 5);

        if ($yearFrom !== null) {
            $query->where('catalog_titles.year', '>=', $yearFrom);
        }

        if ($yearTo !== null) {
            $query->where('catalog_titles.year', '<=', $yearTo);
        }

        $quality = $context->filters['quality'] ?? null;

        if (is_string($quality) && in_array($quality, config('playback.supported_qualities', []), true)) {
            $query->whereExists($this->mediaQuery($context)
                ->where('quality', $quality)
                ->selectRaw('1')
                ->toBase());
        }

        if (($context->filters['subtitles'] ?? null) === 'available') {
            $query->whereExists($this->mediaQuery($context)
                ->where('has_subtitles', true)
                ->selectRaw('1')
                ->toBase());
        }

        $ratingMin = $context->filters['rating_min'] ?? null;
        $votesMin = $this->integerFilter($context, 'votes_min', 0, 100_000_000);

        if (is_numeric($ratingMin) || $votesMin !== null) {
            $provider = $this->provider($context->ratingSource);
            $query->whereHas('ratings', fn (Builder $query): Builder => $query
                ->where('provider', $provider)
                ->when(is_numeric($ratingMin), fn (Builder $query): Builder => $query->where('rating', '>=', (float) $ratingMin))
                ->when($votesMin !== null, fn (Builder $query): Builder => $query->where('votes', '>=', $votesMin)));
        }

        return $query;
    }

    /** @return Builder<LicensedMedia> */
    private function mediaQuery(CatalogRecommendationContext $context): Builder
    {
        return LicensedMedia::query()
            ->whereColumn('licensed_media.catalog_title_id', 'catalog_titles.id')
            ->published()
            ->forAvailableReleases($context->user)
            ->withoutKnownFailures()
            ->withPlaybackLocation();
    }

    private function provider(string $source): string
    {
        return in_array($source, ['imdb', 'kinopoisk'], true) ? $source : 'kinopoisk';
    }

    private function integerFilter(
        CatalogRecommendationContext $context,
        string $key,
        int $minimum,
        int $maximum,
    ): ?int {
        $value = $context->filters[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }
}
