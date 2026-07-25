<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionCategorySuggestion;
use App\Enums\CatalogCollectionCategorySuggestionConfidence;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CatalogCollectionCategorySuggestionService
{
    private const PLATFORM_SLUGS = [
        'netflix',
        'hbo-and-max',
        'apple-tv-plus',
        'amazon',
        'disney-plus',
    ];

    private const COUNTRY_SLUGS = [
        'russia',
        'united-states',
        'europe',
        'south-korea',
        'china',
        'turkey',
    ];

    public function __construct(
        private readonly CatalogCollectionCategorySuggestionRules $rules,
    ) {}

    /**
     * @param  Collection<int, CatalogCollectionCategory>  $activeTree
     */
    public function suggest(
        CatalogCollection $collection,
        Collection $activeTree,
    ): CatalogCollectionCategorySuggestion {
        $categories = $this->activeCategories($activeTree);
        $titles = $this->sampleTitles($collection);
        $sampleSize = $titles->count();
        $scores = [];
        $reasons = [];
        $names = $this->collectionNames($collection);
        $descriptions = $this->collectionDescriptions($collection);
        $sourceName = $this->sourceName($collection);

        foreach ($this->rules->definitions() as $slug => $rule) {
            if (! isset($categories[$slug])) {
                continue;
            }

            $score = 0;
            $candidateReasons = [];

            if ($this->textsContain($names, $rule['title_terms'])) {
                $isExactIdentity = $this->isExactIdentityRule($slug);
                $score += $isExactIdentity ? 70 : 60;
                $candidateReasons[] = $isExactIdentity ? 'title_exact' : 'title_theme';
            }

            if ($this->textsContain($descriptions, $rule['description_terms'])) {
                $score += 30;
                $candidateReasons[] = 'description';
            }

            if ($sourceName !== null && $this->textContains($sourceName, $rule['title_terms'])) {
                $score += 20;
                $candidateReasons[] = 'source_name';
            }

            foreach ([
                ['relations' => ['genres'], 'terms' => $rule['genres'], 'reason' => 'dominant_genre'],
                ['relations' => ['countries'], 'terms' => $rule['countries'], 'reason' => 'dominant_country'],
                [
                    'relations' => ['networks', 'studios'],
                    'terms' => [...$rule['networks'], ...$rule['studios']],
                    'reason' => 'dominant_platform',
                ],
            ] as $dimension) {
                $points = $this->taxonomyDominancePoints(
                    $titles,
                    $dimension['relations'],
                    $dimension['terms'],
                );

                if ($points > 0) {
                    $score += $points;
                    $candidateReasons[] = $dimension['reason'];
                }
            }

            $typePoints = $this->typeDominancePoints($titles, $rule['types']);

            if ($typePoints > 0) {
                $score += $typePoints;
                $candidateReasons[] = 'dominant_type';
            }

            $scores[$slug] = min(100, $score);
            $reasons[$slug] = array_slice(array_values(array_unique($candidateReasons)), 0, 3);
        }

        arsort($scores, SORT_NUMERIC);
        $winnerSlug = array_key_first($scores);
        $winnerScore = $winnerSlug !== null ? (int) $scores[$winnerSlug] : 0;

        if ($winnerSlug === null || $winnerScore < 60) {
            return $this->none(
                $collection,
                $sampleSize,
                ['insufficient_evidence'],
                $winnerScore,
            );
        }

        $runnerUpScore = (int) (array_values($scores)[1] ?? 0);

        if ($winnerScore - $runnerUpScore < 15) {
            return $this->none($collection, $sampleSize, ['conflict'], $winnerScore);
        }

        $category = $categories[$winnerSlug]['category'];
        $parent = $categories[$winnerSlug]['parent'];

        return new CatalogCollectionCategorySuggestion(
            collectionPublicId: (string) $collection->public_id,
            expectedContentVersion: max(1, (int) $collection->content_version),
            categoryPublicId: (string) $category->public_id,
            categorySlug: $winnerSlug,
            categoryPath: $parent instanceof CatalogCollectionCategory
                ? $parent->display_name.' — '.$category->display_name
                : $category->display_name,
            score: $winnerScore,
            confidence: CatalogCollectionCategorySuggestionConfidence::fromScore($winnerScore),
            reasonCodes: $reasons[$winnerSlug] !== []
                ? $reasons[$winnerSlug]
                : ['insufficient_evidence'],
            sampleSize: $sampleSize,
            totalItems: max(0, (int) $collection->getAttribute('total_items_count')),
        );
    }

    /**
     * @param  Collection<int, CatalogCollectionCategory>  $tree
     * @return array<string, array{
     *     category: CatalogCollectionCategory,
     *     parent: CatalogCollectionCategory|null
     * }>
     */
    private function activeCategories(Collection $tree): array
    {
        $categories = [];

        foreach ($tree as $root) {
            if (! $root->is_active) {
                continue;
            }

            $categories[$root->slug] = [
                'category' => $root,
                'parent' => null,
            ];

            if (! $root->relationLoaded('children')) {
                continue;
            }

            foreach ($root->children as $child) {
                if (! $child->is_active) {
                    continue;
                }

                $categories[$child->slug] = [
                    'category' => $child,
                    'parent' => $root,
                ];
            }
        }

        return $categories;
    }

    /** @return Collection<int, CatalogTitle> */
    private function sampleTitles(CatalogCollection $collection): Collection
    {
        if (! $collection->relationLoaded('items')) {
            return collect();
        }

        return $collection->items
            ->map(fn (CatalogCollectionItem $item): ?CatalogTitle => $item->relationLoaded('catalogTitle')
                ? $item->catalogTitle
                : null)
            ->filter(fn (?CatalogTitle $title): bool => $title instanceof CatalogTitle)
            ->values();
    }

    /** @return list<string> */
    private function collectionNames(CatalogCollection $collection): array
    {
        $names = [(string) $collection->name];

        if ($collection->relationLoaded('translations')) {
            foreach ($collection->translations as $translation) {
                $names[] = (string) $translation->name;
            }
        }

        return array_values(array_filter($names));
    }

    /** @return list<string> */
    private function collectionDescriptions(CatalogCollection $collection): array
    {
        $descriptions = [(string) ($collection->description ?? '')];

        if ($collection->relationLoaded('translations')) {
            foreach ($collection->translations as $translation) {
                $descriptions[] = (string) ($translation->description ?? '');
            }
        }

        return array_values(array_filter($descriptions));
    }

    private function sourceName(CatalogCollection $collection): ?string
    {
        if (! $collection->relationLoaded('sourceRecord')) {
            return null;
        }

        $sourceName = trim((string) ($collection->sourceRecord?->remote_name ?? ''));

        return $sourceName !== '' ? $sourceName : null;
    }

    /**
     * @param  list<string>  $texts
     * @param  list<string>  $terms
     */
    private function textsContain(array $texts, array $terms): bool
    {
        foreach ($texts as $text) {
            if ($this->textContains($text, $terms)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $terms */
    private function textContains(string $text, array $terms): bool
    {
        $normalizedText = $this->normalize($text);

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if ($normalizedTerm !== ''
                && preg_match(
                    '/(?:^|\s)'.preg_quote($normalizedTerm, '/').'(?:$|\s)/u',
                    $normalizedText,
                ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @param  list<string>  $relations
     * @param  list<string>  $terms
     */
    private function taxonomyDominancePoints(
        Collection $titles,
        array $relations,
        array $terms,
    ): int {
        if ($titles->isEmpty() || $terms === []) {
            return 0;
        }

        $matching = $titles->filter(function (CatalogTitle $title) use ($relations, $terms): bool {
            foreach ($relations as $relation) {
                if (! $title->relationLoaded($relation)) {
                    continue;
                }

                if ($title->getRelation($relation)->contains(
                    fn ($taxonomy): bool => $this->taxonomyMatches($taxonomy, $terms),
                )) {
                    return true;
                }
            }

            return false;
        })->count();

        return $this->dominancePoints($matching, $titles->count());
    }

    /** @param list<string> $terms */
    private function taxonomyMatches(mixed $taxonomy, array $terms): bool
    {
        $values = [
            $this->normalize((string) ($taxonomy->slug ?? '')),
            $this->normalize((string) ($taxonomy->name ?? '')),
        ];
        $normalizedTerms = array_map($this->normalize(...), $terms);

        return array_intersect($values, $normalizedTerms) !== [];
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @param  list<string>  $types
     */
    private function typeDominancePoints(Collection $titles, array $types): int
    {
        if ($titles->isEmpty() || $types === []) {
            return 0;
        }

        $normalizedTypes = array_map($this->normalize(...), $types);
        $matching = $titles
            ->filter(fn (CatalogTitle $title): bool => in_array(
                $this->normalize((string) $title->type),
                $normalizedTypes,
                true,
            ))
            ->count();

        return $matching / $titles->count() >= 0.7 ? 35 : 0;
    }

    private function dominancePoints(int $matching, int $total): int
    {
        if ($total < 1 || $matching < 1) {
            return 0;
        }

        return match (true) {
            $matching / $total >= 0.7 => 55,
            $matching / $total >= 0.5 => 35,
            $matching / $total >= 0.35 => 20,
            default => 0,
        };
    }

    private function isExactIdentityRule(string $slug): bool
    {
        return in_array($slug, [...self::PLATFORM_SLUGS, ...self::COUNTRY_SLUGS], true);
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($value)) ?? '';

        return Str::squish($value);
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function none(
        CatalogCollection $collection,
        int $sampleSize,
        array $reasonCodes,
        int $score,
    ): CatalogCollectionCategorySuggestion {
        return new CatalogCollectionCategorySuggestion(
            collectionPublicId: (string) $collection->public_id,
            expectedContentVersion: max(1, (int) $collection->content_version),
            categoryPublicId: null,
            categorySlug: null,
            categoryPath: null,
            score: max(0, min(100, $score)),
            confidence: CatalogCollectionCategorySuggestionConfidence::None,
            reasonCodes: $reasonCodes,
            sampleSize: $sampleSize,
            totalItems: max(0, (int) $collection->getAttribute('total_items_count')),
        );
    }
}
