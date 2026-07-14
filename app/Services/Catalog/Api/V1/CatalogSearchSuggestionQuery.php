<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\Search\CatalogPeopleLookup;
use App\Services\Catalog\Search\CatalogSearchQuery;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CatalogSearchSuggestionQuery
{
    private const LIMIT_PER_TYPE = 5;

    public function __construct(
        private CatalogSearchQueryParser $parser,
        private CatalogTitleSearch $search,
        private CatalogTitleQuery $titles,
        private CatalogPeopleLookup $people,
    ) {}

    /** @return array{query: string, items: Collection<int, array<string, int|string|null>>} */
    public function search(string $query, ?User $user): array
    {
        $parsed = $this->parser->parse($query);
        $titleItems = $this->titleCandidates($parsed, $user)
            ->map(static fn (CatalogTitle $title): array => [
                'type' => 'title',
                'label' => $title->display_title,
                'slug' => (string) $title->slug,
                'title_slug' => (string) $title->slug,
                'count' => 0,
            ]);
        $peopleItems = collect(['actor', 'director'])->flatMap(fn (string $type): Collection => $this->people
            ->search($type, $parsed->raw, $user)
            ->take(self::LIMIT_PER_TYPE)
            ->map(static fn ($person): array => [
                'type' => (string) $person->filter_type,
                'label' => (string) $person->name,
                'slug' => (string) $person->slug,
                'title_slug' => null,
                'count' => (int) $person->public_titles_count,
            ]));

        return [
            'query' => $parsed->raw,
            'items' => $titleItems->concat($peopleItems)->values(),
        ];
    }

    /** @return Collection<int, CatalogTitle> */
    private function titleCandidates(CatalogSearchQuery $query, ?User $user): Collection
    {
        $candidateQuery = $this->search->candidateQuery($query);
        $candidateIds = $candidateQuery?->limit(self::LIMIT_PER_TYPE)->pluck('catalog_title_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values() ?? collect();

        if ($candidateIds->isNotEmpty()) {
            $titlesById = $this->titleSummaryQuery($user)
                ->whereKey($candidateIds)
                ->get()
                ->keyBy('id');

            return $candidateIds
                ->map(fn (int $id): ?CatalogTitle => $titlesById->get($id))
                ->filter()
                ->values();
        }

        $search = str_replace(['%', '_'], '', $query->raw);
        $slugSearch = str($search)->slug()->toString();

        return $this->titleSummaryQuery($user)
            ->where(function (Builder $builder) use ($search, $slugSearch): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('original_title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$slugSearch}%")
                    ->orWhereHas('aliases', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"));
            })
            ->orderBy('title')
            ->orderBy('id')
            ->limit(self::LIMIT_PER_TYPE)
            ->get();
    }

    /** @return Builder<CatalogTitle> */
    private function titleSummaryQuery(?User $user): Builder
    {
        return $this->titles->visibleTo($user)->select([
            'id',
            'slug',
            'title',
            'original_title',
        ]);
    }
}
