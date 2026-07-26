<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogSmartCollectionCompletion;
use App\Enums\CatalogWatchStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CatalogSmartCollectionRules
{
    public const VERSION = 1;

    private const KEYS = [
        'country_slug',
        'genre_slug',
        'actor_slug',
        'imdb_min',
        'year_from',
        'year_to',
        'completion',
        'episodes_max',
        'max_episode_minutes',
        'in_library',
        'unwatched',
        'has_subtitles',
        'has_new_episodes',
        'watch_status',
        'watch_status_older_days',
        'video_available',
    ];

    public function __construct(
        public ?string $countrySlug,
        public ?string $genreSlug,
        public ?string $actorSlug,
        public ?float $imdbMin,
        public ?int $yearFrom,
        public ?int $yearTo,
        public ?CatalogSmartCollectionCompletion $completion,
        public ?int $episodesMax,
        public ?int $maxEpisodeMinutes,
        public bool $inLibrary,
        public bool $unwatched,
        public bool $hasSubtitles,
        public bool $hasNewEpisodes,
        public ?CatalogWatchStatus $watchStatus,
        public ?int $watchStatusOlderDays,
        public bool $videoAvailable,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        if (array_diff(array_keys($input), self::KEYS) !== []) {
            throw self::error('rules');
        }

        $rules = new self(
            countrySlug: self::slug($input['country_slug'] ?? null, 'country_slug'),
            genreSlug: self::slug($input['genre_slug'] ?? null, 'genre_slug'),
            actorSlug: self::slug($input['actor_slug'] ?? null, 'actor_slug'),
            imdbMin: self::decimal($input['imdb_min'] ?? null, 'imdb_min', 0, 10),
            yearFrom: self::integer($input['year_from'] ?? null, 'year_from', 1900, now()->year + 5),
            yearTo: self::integer($input['year_to'] ?? null, 'year_to', 1900, now()->year + 5),
            completion: self::completion($input['completion'] ?? null),
            episodesMax: self::integer($input['episodes_max'] ?? null, 'episodes_max', 1, 10_000),
            maxEpisodeMinutes: self::integer($input['max_episode_minutes'] ?? null, 'max_episode_minutes', 1, 1_440),
            inLibrary: self::boolean($input['in_library'] ?? false, 'in_library'),
            unwatched: self::boolean($input['unwatched'] ?? false, 'unwatched'),
            hasSubtitles: self::boolean($input['has_subtitles'] ?? false, 'has_subtitles'),
            hasNewEpisodes: self::boolean($input['has_new_episodes'] ?? false, 'has_new_episodes'),
            watchStatus: self::watchStatus($input['watch_status'] ?? null),
            watchStatusOlderDays: self::integer(
                $input['watch_status_older_days'] ?? null,
                'watch_status_older_days',
                1,
                3_650,
            ),
            videoAvailable: self::boolean($input['video_available'] ?? false, 'video_available'),
        );

        if ($rules->yearFrom !== null && $rules->yearTo !== null && $rules->yearFrom > $rules->yearTo) {
            throw self::error('year_to');
        }

        if ($rules->watchStatusOlderDays !== null && $rules->watchStatus !== CatalogWatchStatus::Dropped) {
            throw self::error('watch_status_older_days');
        }

        if (! $rules->hasActiveRules()) {
            throw self::error('rules');
        }

        return $rules;
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored, int $version): ?self
    {
        if ($version !== self::VERSION) {
            return null;
        }

        try {
            return self::fromInput($stored);
        } catch (ValidationException) {
            return null;
        }
    }

    public function hasActiveRules(): bool
    {
        return $this->countrySlug !== null
            || $this->genreSlug !== null
            || $this->actorSlug !== null
            || $this->imdbMin !== null
            || $this->yearFrom !== null
            || $this->yearTo !== null
            || $this->completion !== null
            || $this->episodesMax !== null
            || $this->maxEpisodeMinutes !== null
            || $this->inLibrary
            || $this->unwatched
            || $this->hasSubtitles
            || $this->hasNewEpisodes
            || $this->watchStatus !== null
            || $this->watchStatusOlderDays !== null
            || $this->videoAvailable;
    }

    /** @return array<string, float|int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'country_slug' => $this->countrySlug,
            'genre_slug' => $this->genreSlug,
            'actor_slug' => $this->actorSlug,
            'imdb_min' => $this->imdbMin,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'completion' => $this->completion?->value,
            'episodes_max' => $this->episodesMax,
            'max_episode_minutes' => $this->maxEpisodeMinutes,
            'in_library' => $this->inLibrary,
            'unwatched' => $this->unwatched,
            'has_subtitles' => $this->hasSubtitles,
            'has_new_episodes' => $this->hasNewEpisodes,
            'watch_status' => $this->watchStatus?->value,
            'watch_status_older_days' => $this->watchStatusOlderDays,
            'video_available' => $this->videoAvailable,
        ];
    }

    private static function slug(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw self::error($field);
        }

        $value = Str::lower(trim($value));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $value) !== 1) {
            throw self::error($field);
        }

        return $value;
    }

    private static function decimal(mixed $value, string $field, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (! is_numeric($value)) {
            throw self::error($field);
        }

        $normalized = round((float) $value, 2);

        if ($normalized < $minimum || $normalized > $maximum) {
            throw self::error($field);
        }

        return $normalized;
    }

    private static function integer(mixed $value, string $field, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw self::error($field);
        }

        $normalized = (int) $value;

        if ($normalized < $minimum || $normalized > $maximum) {
            throw self::error($field);
        }

        return $normalized;
    }

    private static function boolean(mixed $value, string $field): bool
    {
        return match ($value) {
            true, 1, '1', 'true', 'on' => true,
            false, 0, '0', 'false', 'off', '', null => false,
            default => throw self::error($field),
        };
    }

    private static function completion(mixed $value): ?CatalogSmartCollectionCompletion
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || CatalogSmartCollectionCompletion::tryFrom($value) === null) {
            throw self::error('completion');
        }

        return CatalogSmartCollectionCompletion::from($value);
    }

    private static function watchStatus(mixed $value): ?CatalogWatchStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || CatalogWatchStatus::tryFrom($value) === null) {
            throw self::error('watch_status');
        }

        return CatalogWatchStatus::from($value);
    }

    private static function error(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => [__("collections.smart.validation.{$field}")],
        ]);
    }
}
