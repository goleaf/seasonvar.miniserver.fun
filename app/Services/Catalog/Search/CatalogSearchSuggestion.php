<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Support\Collection;

final readonly class CatalogSearchSuggestion
{
    private const CANDIDATE_LIMIT = 60;

    private const RESULT_LIMIT = 3;

    private const MINIMUM_SIMILARITY = 0.55;

    public function __construct(
        private CatalogTitleSearch $search,
        private CatalogTitleQuery $titles,
        private CatalogSearchNormalizer $normalizer,
    ) {}

    /** @return Collection<int, CatalogTitle> */
    public function forQuery(CatalogSearchQuery $query, ?User $user = null): Collection
    {
        if (! $query->isReady() || ! $this->search->isReady() || mb_strlen($query->normalized) < 2) {
            return collect();
        }

        $searchCandidates = $this->search->candidateQuery($query);

        if ($searchCandidates === null || (clone $searchCandidates)->limit(1)->exists()) {
            return collect();
        }

        $trigrams = $this->grams($query->normalized);

        if ($trigrams === []) {
            return collect();
        }

        $candidateQuery = $this->titles
            ->constrainVisible(CatalogTitle::query(), $user)
            ->select([
                'catalog_titles.id',
                'catalog_titles.slug',
                'catalog_titles.title',
                'catalog_titles.original_title',
                'catalog_title_search_documents.suggestion_names',
            ])
            ->join(
                'catalog_title_search_documents',
                'catalog_title_search_documents.catalog_title_id',
                '=',
                'catalog_titles.id',
            )
            ->where(function ($builder) use ($trigrams): void {
                foreach ($trigrams as $trigram) {
                    $builder->orWhereRaw(
                        'instr(catalog_title_search_documents.suggestion_names, ?) > 0',
                        [$trigram],
                    );
                }
            });
        $hitExpression = collect($trigrams)
            ->map(fn (): string => 'CASE WHEN instr(catalog_title_search_documents.suggestion_names, ?) > 0 THEN 1 ELSE 0 END')
            ->implode(' + ');

        return $candidateQuery
            ->selectRaw("({$hitExpression}) AS trigram_hits", $trigrams)
            ->orderByDesc('trigram_hits')
            ->orderBy('catalog_titles.id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->map(function (CatalogTitle $title) use ($query): CatalogTitle {
                $best = collect(explode("\n", (string) $title->suggestion_names))
                    ->map(fn (string $name): array => [
                        'name' => $this->normalizer->key($name),
                        'similarity' => $this->similarity($query->normalized, $name),
                    ])
                    ->filter(fn (array $candidate): bool => $candidate['name'] !== '')
                    ->sortByDesc('similarity')
                    ->first() ?? ['name' => '', 'similarity' => 0.0];

                $title->setAttribute('suggestion_name', $title->display_title);
                $title->setAttribute('suggestion_similarity', $best['similarity']);

                return $title;
            })
            ->filter(
                fn (CatalogTitle $title): bool => (float) $title->suggestion_similarity >= self::MINIMUM_SIMILARITY,
            )
            ->sortBy([
                [fn (CatalogTitle $title): float => (float) $title->suggestion_similarity, 'desc'],
                [fn (CatalogTitle $title): string => $this->normalizer->key($title->display_title), 'asc'],
                [fn (CatalogTitle $title): int => (int) $title->id, 'asc'],
            ])
            ->take(self::RESULT_LIMIT)
            ->values();
    }

    /** @return list<string> */
    private function grams(string $value): array
    {
        $value = $this->normalizer->key($value);
        $length = mb_strlen($value);
        $size = $length < 4 ? 2 : 3;

        if ($length < $size) {
            return $value === '' ? [] : [$value];
        }

        $grams = [];

        for ($index = 0; $index <= $length - $size; $index++) {
            $grams[] = mb_substr($value, $index, $size);
        }

        return array_values(array_unique($grams));
    }

    private function similarity(string $query, string $candidate): float
    {
        $queryGrams = $this->grams($query);
        $candidateGrams = $this->grams($candidate);

        if ($queryGrams === [] || $candidateGrams === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($queryGrams, $candidateGrams));

        return (2 * $intersection) / (count($queryGrams) + count($candidateGrams));
    }
}
