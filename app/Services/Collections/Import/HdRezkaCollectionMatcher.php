<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\DTOs\CatalogCollectionSourceMatch;
use App\DTOs\HdRezkaCollectionItemData;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleSearchDocument;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Support\CatalogTitleDisplayName;
use Illuminate\Database\Eloquent\Builder;

final readonly class HdRezkaCollectionMatcher
{
    private const int CANDIDATE_LIMIT = 100;

    private const int MINIMUM_SCORE = 130;

    private const int MINIMUM_LEAD = 20;

    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    /**
     * @param  array{original_title?: ?string, year?: ?int, type?: ?string, genres?: list<string>}|null  $detail
     */
    public function match(HdRezkaCollectionItemData $item, ?array $detail = null): CatalogCollectionSourceMatch
    {
        $evidence = $this->candidateEvidence($item, $detail);

        if ($evidence === []) {
            return $this->unmatched('no_exact_candidate', 0, ['candidate_count' => 0]);
        }

        if (count($evidence) > self::CANDIDATE_LIMIT) {
            return new CatalogCollectionSourceMatch(
                status: CatalogCollectionSourceMatchStatus::Ambiguous,
                catalogTitleId: null,
                method: 'candidate_limit',
                confidence: 0,
                reasons: ['candidate_count' => count($evidence)],
            );
        }

        $titles = CatalogTitle::query()
            ->select(['id', 'title', 'original_title', 'type', 'year'])
            ->with(['countries:id,name', 'genres:id,name'])
            ->whereKey(array_keys($evidence))
            ->get();
        $scored = $titles
            ->map(fn (CatalogTitle $title): ?array => $this->score($title, $evidence[$title->id], $item, $detail))
            ->filter()
            ->sortBy([
                ['score', 'desc'],
                ['catalog_title_id', 'asc'],
            ])
            ->values();

        if ($scored->isEmpty()) {
            return $this->unmatched('no_eligible_candidate', 0, [
                'candidate_count' => count($evidence),
            ]);
        }

        /** @var array{catalog_title_id: int, method: string, score: int, reasons: array<string, int|string>} $top */
        $top = $scored->first();

        if ($top['score'] < self::MINIMUM_SCORE) {
            return $this->unmatched('low_confidence', $top['score'], [
                'candidate_count' => $scored->count(),
                'score' => $top['score'],
            ]);
        }

        /** @var array{score: int}|null $second */
        $second = $scored->get(1);
        $lead = $second === null ? $top['score'] : $top['score'] - $second['score'];

        if ($second !== null && $lead < self::MINIMUM_LEAD) {
            return new CatalogCollectionSourceMatch(
                status: CatalogCollectionSourceMatchStatus::Ambiguous,
                catalogTitleId: null,
                method: 'insufficient_lead',
                confidence: $top['score'],
                reasons: [
                    'candidate_count' => $scored->count(),
                    'score' => $top['score'],
                    'lead' => $lead,
                ],
            );
        }

        return new CatalogCollectionSourceMatch(
            status: CatalogCollectionSourceMatchStatus::Matched,
            catalogTitleId: $top['catalog_title_id'],
            method: $top['method'],
            confidence: $top['score'],
            reasons: [...$top['reasons'], 'lead' => $lead],
        );
    }

    /**
     * @param  array{original_title?: ?string, year?: ?int, type?: ?string, genres?: list<string>}|null  $detail
     * @return array<int, array{primary: bool, original: bool, alias: bool, detail_original: bool}>
     */
    private function candidateEvidence(HdRezkaCollectionItemData $item, ?array $detail): array
    {
        $titleKey = $item->normalizedTitleKey;
        $detailTitle = is_string($detail['original_title'] ?? null) ? (string) $detail['original_title'] : '';
        $detailKey = $this->normalizer->key($detailTitle);
        $keys = array_values(array_unique(array_filter([$titleKey, $detailKey])));
        $documents = CatalogTitleSearchDocument::query()
            ->select(['catalog_title_id', 'normalized_title_key', 'normalized_original_title_key'])
            ->where(function (Builder $query) use ($keys): void {
                $query
                    ->whereIn('normalized_title_key', $keys)
                    ->orWhereIn('normalized_original_title_key', $keys);
            })
            ->limit(self::CANDIDATE_LIMIT + 1)
            ->get();
        $itemHash = CatalogTitleDisplayName::nameHash($item->title);
        $detailHash = $detailTitle !== '' ? CatalogTitleDisplayName::nameHash($detailTitle) : null;
        $hashes = array_values(array_unique(array_filter([$itemHash, $detailHash])));
        $aliases = CatalogTitleAlias::query()
            ->select(['catalog_title_id', 'name_hash'])
            ->whereIn('name_hash', $hashes)
            ->limit(self::CANDIDATE_LIMIT + 1)
            ->get();
        $evidence = [];

        foreach ($documents as $document) {
            $candidate = &$evidence[$document->catalog_title_id];
            $candidate ??= $this->emptyEvidence();
            $candidate['primary'] = $candidate['primary'] || $document->normalized_title_key === $titleKey;
            $candidate['original'] = $candidate['original'] || $document->normalized_original_title_key === $titleKey;
            $candidate['detail_original'] = $candidate['detail_original'] || (
                $detailKey !== ''
                && in_array($detailKey, [
                    $document->normalized_title_key,
                    $document->normalized_original_title_key,
                ], true)
            );
            unset($candidate);
        }

        foreach ($aliases as $alias) {
            $candidate = &$evidence[$alias->catalog_title_id];
            $candidate ??= $this->emptyEvidence();
            $candidate['alias'] = $candidate['alias'] || $alias->name_hash === $itemHash;
            $candidate['detail_original'] = $candidate['detail_original'] || (
                $detailHash !== null && $alias->name_hash === $detailHash
            );
            unset($candidate);
        }

        return $evidence;
    }

    /**
     * @param  array{primary: bool, original: bool, alias: bool, detail_original: bool}  $evidence
     * @param  array{original_title?: ?string, year?: ?int, type?: ?string, genres?: list<string>}|null  $detail
     * @return array{catalog_title_id: int, method: string, score: int, reasons: array<string, int|string>}|null
     */
    private function score(
        CatalogTitle $title,
        array $evidence,
        HdRezkaCollectionItemData $item,
        ?array $detail,
    ): ?array {
        [$method, $nameScore] = match (true) {
            $evidence['primary'] => ['primary', 100],
            $evidence['original'] => ['original', 95],
            $evidence['alias'] => ['alias', 90],
            default => ['detail_original', 0],
        };
        $sourceYear = $item->year ?? $this->nullableInt($detail['year'] ?? null);
        $candidateYear = $this->nullableInt($title->year);

        if ($sourceYear !== null && $candidateYear !== null && $sourceYear !== $candidateYear) {
            return null;
        }

        $sourceType = $this->canonicalType($item->type ?? ($detail['type'] ?? null));
        $candidateType = $this->canonicalType($title->type);

        if ($sourceType !== null && $candidateType !== null && $sourceType !== $candidateType) {
            return null;
        }

        $yearScore = $sourceYear !== null && $candidateYear === $sourceYear ? 40 : 0;
        $typeScore = $sourceType !== null && $candidateType === $sourceType ? 20 : 0;
        $sourceCountries = collect($item->countries)
            ->map(fn (string $country): string => $this->normalizer->key($country))
            ->filter()
            ->unique();
        $candidateCountries = $title->countries
            ->map(fn (mixed $country): string => $this->normalizer->key((string) $country->name))
            ->filter()
            ->unique();
        $countryScore = min(2, $sourceCountries->intersect($candidateCountries)->count()) * 10;
        $detailOriginalScore = $evidence['detail_original'] ? 25 : 0;
        $sourceGenres = collect($detail['genres'] ?? [])
            ->filter(fn (mixed $genre): bool => is_string($genre))
            ->map(fn (string $genre): string => $this->normalizer->key($genre))
            ->filter()
            ->unique();
        $candidateGenres = $title->genres
            ->map(fn (mixed $genre): string => $this->normalizer->key((string) $genre->name))
            ->filter()
            ->unique();
        $genreScore = min(3, $sourceGenres->intersect($candidateGenres)->count()) * 5;
        $score = $nameScore + $yearScore + $typeScore + $countryScore + $detailOriginalScore + $genreScore;

        return [
            'catalog_title_id' => (int) $title->id,
            'method' => $method,
            'score' => $score,
            'reasons' => [
                'name' => $method,
                'name_score' => $nameScore,
                'year_score' => $yearScore,
                'type_score' => $typeScore,
                'country_score' => $countryScore,
                'detail_original_score' => $detailOriginalScore,
                'genre_score' => $genreScore,
                'score' => $score,
            ],
        ];
    }

    /** @return array{primary: bool, original: bool, alias: bool, detail_original: bool} */
    private function emptyEvidence(): array
    {
        return [
            'primary' => false,
            'original' => false,
            'alias' => false,
            'detail_original' => false,
        ];
    }

    private function canonicalType(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        return match ($this->normalizer->key($type)) {
            'film', 'movie' => 'film',
            'series', 'serial', 'tv series', 'tvseries' => 'series',
            'cartoon', 'cartoons', 'animation', 'animated movie' => 'cartoon',
            'anime' => 'anime',
            'documentary' => 'documentary',
            'show', 'tv show' => 'show',
            default => null,
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /** @param array<string, int|string> $reasons */
    private function unmatched(string $method, int $confidence, array $reasons): CatalogCollectionSourceMatch
    {
        return new CatalogCollectionSourceMatch(
            status: CatalogCollectionSourceMatchStatus::Unmatched,
            catalogTitleId: null,
            method: $method,
            confidence: $confidence,
            reasons: $reasons,
        );
    }
}
