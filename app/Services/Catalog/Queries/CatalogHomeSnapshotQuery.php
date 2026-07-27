<?php

declare(strict_types=1);

namespace App\Services\Catalog\Queries;

use App\Models\LicensedMedia;
use App\Models\Tag;
use App\Services\Catalog\CatalogFacetSnapshotCache;
use App\Services\Catalog\CatalogHomeContentAdditionQuery;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;

final readonly class CatalogHomeSnapshotQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogHomeContentAdditionQuery $contentAdditions,
        private CatalogFacetSnapshotCache $facetSnapshots,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $latestTitleUpdates = $this->contentAdditions->latestTitleUpdates();
        $media = new LicensedMedia;
        $availableMedia = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->whereColumn($media->qualifyColumn('catalog_title_id'), 'catalog_titles.id')
            ->selectRaw('1')
            ->toBase();
        $visibleTitle = $this->titles
            ->visibleTo(null)
            ->whereColumn('catalog_titles.id', $media->qualifyColumn('catalog_title_id'))
            ->selectRaw('1')
            ->toBase();

        return [
            'latest_title_ids' => collect($latestTitleUpdates)->pluck('id')->all(),
            'latest_title_updates' => $latestTitleUpdates,
            'featured_title_ids' => $this->titles->visibleTo(null)
                ->whereNotNull('poster_url')
                ->latest('indexed_at')
                ->orderByDesc('id')
                ->limit(12)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'video_title_ids' => $this->titles->visibleTo(null)
                ->whereExists($availableMedia)
                ->latest('indexed_at')
                ->orderByDesc('id')
                ->limit(8)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'latest_media_ids' => LicensedMedia::query()
                ->published()
                ->forAvailableReleases(null)
                ->whereExists($visibleTitle)
                ->latest('published_at')
                ->orderByDesc('id')
                ->limit(12)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'year_buckets' => $this->yearBuckets(),
            'subtitle_tag' => $this->subtitleTag(),
        ];
    }

    /** @return list<array{year: int, titles_count: int}> */
    private function yearBuckets(): array
    {
        $currentYear = (int) now()->format('Y');

        return $this->facetSnapshots->remember(
            'homepage-year-buckets-v2',
            ['audience' => 'public', 'year' => $currentYear],
            fn (): array => $this->titles->visibleTo(null)
                ->select('year')
                ->selectRaw('count(*) as titles_count')
                ->whereNotNull('year')
                ->where('year', '>=', 1900)
                ->where('year', '<=', $currentYear + 1)
                ->groupBy('year')
                ->orderByDesc('year')
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
                    $query->where('code', 'subtitle-available')->publiclyEligible();
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
}
