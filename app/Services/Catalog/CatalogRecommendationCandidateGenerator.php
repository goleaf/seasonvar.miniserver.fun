<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final class CatalogRecommendationCandidateGenerator
{
    private const EDITORIAL_COLLECTION_SIGNAL_PREFIX = 'editorial_collection:';

    /** @var list<string> */
    private const INDEXED_RELATIONS = [
        'genre',
        'tag',
        'director',
        'actor',
        'network',
        'studio',
    ];

    /** @var array<string, string> */
    private array $buckets = [];

    /** @var array<string, int> */
    private array $bucketSizes = [];

    /** @var array<int, true> */
    private array $profileIds = [];

    /**
     * @param  array{id: int, relations?: array<string, list<int>>, themes?: list<string>, signals?: array<string, int>}  $profile
     */
    public function add(array $profile): void
    {
        $profileId = max(0, (int) ($profile['id'] ?? 0));

        if ($profileId === 0 || isset($this->profileIds[$profileId])) {
            return;
        }

        $this->profileIds[$profileId] = true;

        foreach ($this->keys($profile) as $key) {
            $this->buckets[$key] = ($this->buckets[$key] ?? '').pack('V', $profileId);
            $this->bucketSizes[$key] = ($this->bucketSizes[$key] ?? 0) + 1;
        }
    }

    /**
     * @param  array{id: int, relations?: array<string, list<int>>, themes?: list<string>, signals?: array<string, int>, provider_targets?: array<int, int>}  $source
     * @return list<int>
     */
    public function idsFor(array $source, int $limit): array
    {
        $limit = max(0, $limit);

        if ($limit === 0) {
            return [];
        }

        $sourceId = max(0, (int) ($source['id'] ?? 0));
        $scores = [];

        foreach ($this->providerTargetIds($source) as $candidateId) {
            if ($candidateId !== $sourceId && isset($this->profileIds[$candidateId])) {
                $scores[$candidateId] = 10_000.0;
            }
        }

        foreach ($this->keys($source) as $key) {
            $packedIds = $this->buckets[$key] ?? null;
            $bucketSize = $this->bucketSizes[$key] ?? 0;

            if ($packedIds === null || $bucketSize === 0) {
                continue;
            }

            $seedScore = 1_000 / $bucketSize;

            foreach ($this->sampledIds($packedIds, $sourceId, $key, $limit) as $candidateId) {
                if ($candidateId !== $sourceId) {
                    $scores[$candidateId] = ($scores[$candidateId] ?? 0.0) + $seedScore;
                }
            }
        }

        uksort($scores, static function (int|string $left, int|string $right) use ($scores): int {
            $scoreComparison = $scores[(int) $right] <=> $scores[(int) $left];

            return $scoreComparison !== 0 ? $scoreComparison : (int) $left <=> (int) $right;
        });

        return array_map(
            static fn (int|string $id): int => (int) $id,
            array_keys(array_slice($scores, 0, $limit, true)),
        );
    }

    public function reset(): void
    {
        $this->buckets = [];
        $this->bucketSizes = [];
        $this->profileIds = [];
    }

    /**
     * @param  array{relations?: array<string, list<int>>, themes?: list<string>, signals?: array<string, int>}  $profile
     * @return list<string>
     */
    private function keys(array $profile): array
    {
        $relations = is_array($profile['relations'] ?? null) ? $profile['relations'] : [];
        $keys = [];

        foreach (self::INDEXED_RELATIONS as $feature) {
            foreach ($this->relationIds($relations, $feature) as $id) {
                $keys[] = $feature.':'.$id;
            }
        }

        $themes = $this->themes($profile);
        $countryIds = $this->relationIds($relations, 'country');
        $genreIds = $this->relationIds($relations, 'genre');

        foreach ($themes as $theme) {
            $keys[] = 'theme:'.$theme;

            foreach ($countryIds as $countryId) {
                $keys[] = 'theme:'.$theme.'|country:'.$countryId;
            }

            foreach ($genreIds as $genreId) {
                $keys[] = 'theme:'.$theme.'|genre:'.$genreId;
            }
        }

        foreach ($this->editorialCollectionSignalKeys($profile) as $signalKey) {
            $keys[] = 'signal:'.$signalKey;
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @param  array<string, list<int>>  $relations
     * @return list<int>
     */
    private function relationIds(array $relations, string $feature): array
    {
        $ids = is_array($relations[$feature] ?? null) ? $relations[$feature] : [];
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  array{themes?: list<string>}  $profile
     * @return list<string>
     */
    private function themes(array $profile): array
    {
        $themes = is_array($profile['themes'] ?? null) ? $profile['themes'] : [];
        $themes = array_values(array_unique(array_map(
            static fn (string $theme): string => trim($theme),
            array_filter($themes, static fn (mixed $theme): bool => is_string($theme) && trim($theme) !== ''),
        )));
        sort($themes, SORT_STRING);

        return $themes;
    }

    /**
     * @param  array{signals?: array<string, int>}  $profile
     * @return list<string>
     */
    private function editorialCollectionSignalKeys(array $profile): array
    {
        $signals = is_array($profile['signals'] ?? null) ? $profile['signals'] : [];
        $signals = array_filter(
            $signals,
            static fn (mixed $weight, mixed $key): bool => is_string($key)
                && str_starts_with($key, self::EDITORIAL_COLLECTION_SIGNAL_PREFIX)
                && is_numeric($weight)
                && (int) $weight > 0,
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($signals, static function (string $left, string $right) use ($signals): int {
            $weight = (int) $signals[$right] <=> (int) $signals[$left];

            return $weight !== 0 ? $weight : $left <=> $right;
        });

        return array_slice(
            array_keys($signals),
            0,
            max(1, (int) config('recommendations.similarity_v6.collection_signal_max_keys', 32)),
        );
    }

    /**
     * @param  array{provider_targets?: array<int, int>}  $source
     * @return list<int>
     */
    private function providerTargetIds(array $source): array
    {
        $targets = is_array($source['provider_targets'] ?? null) ? $source['provider_targets'] : [];
        $ids = [];

        foreach ($targets as $targetId => $weight) {
            if (is_numeric($targetId) && (int) $targetId > 0 && is_numeric($weight) && (int) $weight > 0) {
                $ids[] = (int) $targetId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return iterable<int> */
    private function sampledIds(string $packedIds, int $sourceId, string $key, int $limit): iterable
    {
        $bucketSize = intdiv(strlen($packedIds), 4);
        $scanLimit = max(32, min(480, $limit * 8));
        $selected = min($bucketSize, $scanLimit);
        $start = $bucketSize > $selected
            ? (int) (sprintf('%u', crc32($sourceId.':'.$key)) % $bucketSize)
            : 0;

        for ($index = 0; $index < $selected; $index++) {
            $offset = (($start + $index) % $bucketSize) * 4;
            $unpacked = unpack('Vtitle_id', $packedIds, $offset);

            if (is_array($unpacked)) {
                yield (int) $unpacked['title_id'];
            }
        }
    }
}
