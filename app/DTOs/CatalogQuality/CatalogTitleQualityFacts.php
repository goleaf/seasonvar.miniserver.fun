<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class CatalogTitleQualityFacts
{
    /**
     * @param  list<string>  $countries
     * @param  list<string>  $genres
     * @param  list<array{name: string, type: string, has_current_provenance: bool}>  $tags
     * @param  array<string, mixed>  $providerFieldValues
     * @param  list<array{
     *     number: int,
     *     regular_episode_count: int,
     *     minimum_episode_number: int|null,
     *     maximum_episode_number: int|null,
     *     distinct_episode_number_count: int,
     *     released_episode_count: int|null,
     *     total_episode_count: int|null
     * }>  $seasons
     * @param  array{
     *     published_playable_count: int,
     *     never_checked_count: int,
     *     latest_checked_at: CarbonInterface|null
     * }  $media
     * @param  list<array{provider: string, rating: float|null, votes: int|null}>  $ratings
     */
    public function __construct(
        public int $catalogTitleId,
        public string $title,
        public ?string $originalTitle,
        public ?int $year,
        public ?string $description,
        public ?string $posterUrl,
        public array $countries,
        public array $genres,
        public array $tags,
        public array $providerFieldValues,
        public array $seasons,
        public array $media,
        public array $ratings,
        public ?CarbonInterface $lastSourceCheckedAt,
        public CarbonInterface $evaluatedAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $media = is_array($data['media'] ?? null) ? $data['media'] : [];

        $media['latest_checked_at'] = self::date($media['latest_checked_at'] ?? null);

        return new self(
            catalogTitleId: (int) ($data['catalog_title_id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            originalTitle: self::nullableString($data['original_title'] ?? null),
            year: isset($data['year']) ? (int) $data['year'] : null,
            description: self::nullableString($data['description'] ?? null),
            posterUrl: self::nullableString($data['poster_url'] ?? null),
            countries: self::strings($data['countries'] ?? []),
            genres: self::strings($data['genres'] ?? []),
            tags: self::tags($data['tags'] ?? []),
            providerFieldValues: is_array($data['provider_field_values'] ?? null)
                ? $data['provider_field_values']
                : [],
            seasons: self::seasons($data['seasons'] ?? []),
            media: [
                'published_playable_count' => max(0, (int) ($media['published_playable_count'] ?? 0)),
                'never_checked_count' => max(0, (int) ($media['never_checked_count'] ?? 0)),
                'latest_checked_at' => $media['latest_checked_at'],
            ],
            ratings: self::ratings($data['ratings'] ?? []),
            lastSourceCheckedAt: self::date($data['last_source_checked_at'] ?? null),
            evaluatedAt: self::date($data['evaluated_at'] ?? null) ?? CarbonImmutable::now(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'catalog_title_id' => $this->catalogTitleId,
            'title' => $this->title,
            'original_title' => $this->originalTitle,
            'year' => $this->year,
            'description' => $this->description,
            'poster_url' => $this->posterUrl,
            'countries' => $this->countries,
            'genres' => $this->genres,
            'tags' => $this->tags,
            'provider_field_values' => $this->providerFieldValues,
            'seasons' => $this->seasons,
            'media' => $this->media,
            'ratings' => $this->ratings,
            'last_source_checked_at' => $this->lastSourceCheckedAt,
            'evaluated_at' => $this->evaluatedAt,
        ];
    }

    private static function date(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $values,
            ),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /** @return list<array{name: string, type: string, has_current_provenance: bool}> */
    private static function tags(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $tags = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $name = trim((string) ($value['name'] ?? ''));
            $type = trim((string) ($value['type'] ?? ''));

            if ($name === '' || $type === '') {
                continue;
            }

            $tags[] = [
                'name' => $name,
                'type' => $type,
                'has_current_provenance' => (bool) ($value['has_current_provenance'] ?? false),
            ];
        }

        return $tags;
    }

    /**
     * @return list<array{
     *     number: int,
     *     regular_episode_count: int,
     *     minimum_episode_number: int|null,
     *     maximum_episode_number: int|null,
     *     distinct_episode_number_count: int,
     *     released_episode_count: int|null,
     *     total_episode_count: int|null
     * }>
     */
    private static function seasons(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $seasons = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $seasons[] = [
                'number' => (int) ($value['number'] ?? 0),
                'regular_episode_count' => max(0, (int) ($value['regular_episode_count'] ?? 0)),
                'minimum_episode_number' => isset($value['minimum_episode_number'])
                    ? (int) $value['minimum_episode_number']
                    : null,
                'maximum_episode_number' => isset($value['maximum_episode_number'])
                    ? (int) $value['maximum_episode_number']
                    : null,
                'distinct_episode_number_count' => max(
                    0,
                    (int) ($value['distinct_episode_number_count'] ?? 0),
                ),
                'released_episode_count' => isset($value['released_episode_count'])
                    ? (int) $value['released_episode_count']
                    : null,
                'total_episode_count' => isset($value['total_episode_count'])
                    ? (int) $value['total_episode_count']
                    : null,
            ];
        }

        return $seasons;
    }

    /** @return list<array{provider: string, rating: float|null, votes: int|null}> */
    private static function ratings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ratings = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $provider = trim((string) ($value['provider'] ?? ''));

            if ($provider === '') {
                continue;
            }

            $ratings[] = [
                'provider' => $provider,
                'rating' => isset($value['rating']) ? (float) $value['rating'] : null,
                'votes' => isset($value['votes']) ? (int) $value['votes'] : null,
            ];
        }

        return $ratings;
    }
}
