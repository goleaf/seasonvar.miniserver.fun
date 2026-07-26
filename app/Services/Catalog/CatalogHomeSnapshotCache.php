<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\LicensedMedia;
use App\Models\Tag;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Illuminate\Database\Eloquent\Builder;

final class CatalogHomeSnapshotCache
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogHomeContentAdditionQuery $contentAdditions,
        private readonly CatalogFacetSnapshotCache $facetSnapshots,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
    ) {}

    /**
     * @return array{
     *     latest_title_ids: list<int>,
     *     latest_title_updates: list<array{id: int, added_at: string}>,
     *     featured_title_ids: list<int>,
     *     video_title_ids: list<int>,
     *     latest_media_ids: list<int>,
     *     year_buckets: list<array{year: int, titles_count: int}>,
     *     subtitle_tag: array<string, mixed>|null
     * }
     */
    public function snapshot(): array
    {
        return $this->read(refresh: false);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        return $this->read(refresh: true);
    }

    /** @return array<string, mixed> */
    private function read(bool $refresh): array
    {
        $arguments = [
            CacheDomain::Homepage,
            'content-index-v2',
            ['audience' => 'public', 'locale' => app()->getLocale(), 'year' => (int) now()->format('Y')],
            $this->ttl->for(CacheDomain::Homepage),
            fn (): array => $this->build(),
        ];
        $result = $refresh
            ? $this->cache->refresh(...$arguments)
            : $this->cache->remember(...$arguments);

        return is_array($result->value) ? $result->value : $this->emptySnapshot();
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $latestTitleUpdates = $this->contentAdditions->latestTitleUpdates();
        $latestTitleIds = collect($latestTitleUpdates)->pluck('id')->all();
        $featuredTitleIds = $this->titles->visibleTo(null)
            ->whereNotNull('poster_url')
            ->latest('indexed_at')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $media = new LicensedMedia;
        $availableMedia = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->whereColumn(
                $media->qualifyColumn('catalog_title_id'),
                'catalog_titles.id',
            )
            ->selectRaw('1')
            ->toBase();
        $videoTitleIds = $this->titles->visibleTo(null)
            ->whereExists($availableMedia)
            ->latest('indexed_at')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $visibleTitle = $this->titles
            ->visibleTo(null)
            ->whereColumn(
                'catalog_titles.id',
                $media->qualifyColumn('catalog_title_id'),
            )
            ->selectRaw('1')
            ->toBase();
        $latestMediaIds = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->whereExists($visibleTitle)
            ->latest('published_at')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $yearBuckets = $this->yearBuckets();
        $subtitleTag = $this->subtitleTag();

        return [
            'latest_title_ids' => $latestTitleIds,
            'latest_title_updates' => $latestTitleUpdates,
            'featured_title_ids' => $featuredTitleIds,
            'video_title_ids' => $videoTitleIds,
            'latest_media_ids' => $latestMediaIds,
            'year_buckets' => $yearBuckets,
            'subtitle_tag' => $subtitleTag,
        ];
    }

    /**
     * @return list<array{year: int, titles_count: int}>
     */
    private function yearBuckets(): array
    {
        $currentYear = (int) now()->format('Y');

        return $this->facetSnapshots->remember(
            'homepage-year-buckets-v1',
            [
                'audience' => 'public',
                'limit' => 12,
                'year' => $currentYear,
            ],
            fn (): array => $this->titles->visibleTo(null)
                ->select('year')
                ->selectRaw('count(*) as titles_count')
                ->whereNotNull('year')
                ->where('year', '>=', 1900)
                ->where('year', '<=', $currentYear + 1)
                ->groupBy('year')
                ->orderByDesc('year')
                ->limit(12)
                ->get()
                ->map(fn ($bucket): array => [
                    'year' => (int) $bucket->year,
                    'titles_count' => (int) $bucket->getAttribute('titles_count'),
                ])
                ->all(),
        );
    }

    /** @return array<string, mixed>|null */
    private function subtitleTag(): ?array
    {
        $canonicalSchema = Tag::usesCanonicalSchema();
        $rows = $this->facetSnapshots->remember(
            'homepage-subtitle-tag-v1',
            [
                'audience' => 'public',
                'schema' => $canonicalSchema ? 'canonical' : 'legacy',
            ],
            function () use ($canonicalSchema): array {
                $query = Tag::query()->select(['id', 'name', 'slug']);

                if ($canonicalSchema) {
                    $query
                        ->where('code', 'subtitle-available')
                        ->publiclyEligible();
                } else {
                    $query->where('slug', 'subtitry');
                }

                $tag = $query
                    ->withCount([
                        'catalogTitles' => fn (Builder $query): Builder => $this->titles->constrainVisible($query, null),
                    ])
                    ->first();

                return $tag instanceof Tag ? [$tag->getAttributes()] : [];
            },
        );
        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(): array
    {
        return [
            'latest_title_ids' => [],
            'latest_title_updates' => [],
            'featured_title_ids' => [],
            'video_title_ids' => [],
            'latest_media_ids' => [],
            'year_buckets' => [],
            'subtitle_tag' => null,
        ];
    }
}
