<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use App\Enums\CatalogPublicationType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final readonly class SeasonvarCatalogData
{
    /**
     * @param  list<array<string, mixed>>  $seasons
     * @param  list<array<string, mixed>>  $episodes
     * @param  list<array<string, mixed>>  $media
     * @param  list<array<string, mixed>>  $taxonomies
     * @param  list<array<string, mixed>>  $ratings
     * @param  list<array<string, mixed>>  $recommendationSignals
     * @param  list<array<string, mixed>>  $aliases
     * @param  list<array<string, mixed>>  $reviews
     * @param  array<string, mixed>  $parseMeta
     */
    public function __construct(
        public string $title,
        public ?string $originalTitle,
        public string $type,
        public ?int $year,
        public ?string $description,
        public ?string $posterUrl,
        public ?string $externalId,
        public int $currentSeasonNumber,
        public array $seasons,
        public array $episodes,
        public array $media,
        public array $taxonomies,
        public array $ratings,
        public array $recommendationSignals,
        public array $aliases,
        public array $reviews,
        public array $parseMeta,
    ) {}

    /**
     * Validate the parser boundary before any identity or database work begins.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromParsed(array $data): self
    {
        Validator::make($data, self::rules(), [
            'title.not_in' => 'Seasonvar вернул служебное название вместо названия тайтла.',
            'title.required' => 'Seasonvar не вернул название тайтла.',
            'parse_meta.required' => 'Seasonvar не вернул метаданные полноты ответа.',
        ])->validate();
        $validated = $data;

        return new self(
            title: Str::squish($validated['title']),
            originalTitle: self::nullableString($validated['original_title'] ?? null),
            type: $validated['type'],
            year: isset($validated['year']) ? (int) $validated['year'] : null,
            description: self::nullableString($validated['description'] ?? null),
            posterUrl: self::nullableString($validated['poster_url'] ?? null),
            externalId: self::nullableString($validated['external_id'] ?? null),
            currentSeasonNumber: (int) $validated['current_season_number'],
            seasons: self::uniqueList($validated['seasons'], fn (array $item): string => (string) $item['number']),
            episodes: self::uniqueList($validated['episodes'], fn (array $item): string => $item['season_number'].'|'.$item['number']),
            media: self::uniqueList($validated['media'], fn (array $item): string => hash('sha256', implode('|', [
                $item['url'],
                $item['season_number'] ?? '',
                $item['episode_number'] ?? '',
                $item['title'] ?? '',
            ]))),
            taxonomies: self::uniqueList($validated['taxonomies'], fn (array $item): string => $item['type'].'|'.Str::lower(Str::squish($item['name']))),
            ratings: self::uniqueList($validated['ratings'], fn (array $item): string => $item['provider']),
            recommendationSignals: self::uniqueList($validated['recommendation_signals'], fn (array $item): string => implode('|', [
                $item['source'],
                $item['signal_type'],
                $item['signal_key'],
            ])),
            aliases: self::uniqueList($validated['aliases'], fn (array $item): string => $item['type'].'|'.Str::lower(Str::squish($item['name']))),
            reviews: self::uniqueList($validated['reviews'], fn (array $item): string => hash('sha256', Str::lower(Str::squish($item['body'])))),
            parseMeta: $validated['parse_meta'],
        );
    }

    public function hasCompleteMetadataSnapshot(): bool
    {
        return (bool) ($this->parseMeta['has_info_list'] ?? false);
    }

    public function hasPublicationTypeEvidence(): bool
    {
        return collect($this->taxonomies)
            ->contains(fn (array $taxonomy): bool => ($taxonomy['type'] ?? null) === 'genre');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'original_title' => $this->originalTitle,
            'type' => $this->type,
            'year' => $this->year,
            'description' => $this->description,
            'poster_url' => $this->posterUrl,
            'external_id' => $this->externalId,
            'current_season_number' => $this->currentSeasonNumber,
            'seasons' => $this->seasons,
            'episodes' => $this->episodes,
            'media' => $this->media,
            'taxonomies' => $this->taxonomies,
            'ratings' => $this->ratings,
            'recommendation_signals' => $this->recommendationSignals,
            'aliases' => $this->aliases,
            'reviews' => $this->reviews,
            'parse_meta' => $this->parseMeta,
        ];
    }

    public function hasCompleteSeasonSnapshot(): bool
    {
        return (bool) ($this->parseMeta['has_season_list'] ?? false);
    }

    public function hasCompleteEpisodeSnapshot(): bool
    {
        return (bool) ($this->parseMeta['has_episode_script'] ?? false);
    }

    /**
     * @return array<string, array<int|string, mixed>>
     */
    private static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500', 'not_in:Без названия'],
            'original_title' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::enum(CatalogPublicationType::class)],
            'year' => ['nullable', 'integer', 'between:1800,2200'],
            'description' => ['nullable', 'string', 'max:200000'],
            'poster_url' => ['nullable', 'string', 'max:2048'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'current_season_number' => ['required', 'integer', 'between:0,10000'],
            'seasons' => ['present', 'array', 'max:10000'],
            'seasons.*.number' => ['required', 'integer', 'between:0,10000'],
            'episodes' => ['present', 'array', 'max:50000'],
            'episodes.*.season_number' => ['required', 'integer', 'between:0,10000'],
            'episodes.*.number' => ['required', 'integer', 'between:0,100000'],
            'media' => ['present', 'array', 'max:50000'],
            'media.*.url' => ['required', 'string', 'max:4096'],
            'taxonomies' => ['present', 'array', 'max:2000'],
            'taxonomies.*.type' => ['required', 'string', 'max:64'],
            'taxonomies.*.name' => ['required', 'string', 'max:255'],
            'ratings' => ['present', 'array', 'max:50'],
            'ratings.*.provider' => ['required', 'string', 'max:64'],
            'recommendation_signals' => ['present', 'array', 'max:2000'],
            'recommendation_signals.*.source' => ['required', 'string', 'max:64'],
            'recommendation_signals.*.signal_type' => ['required', 'string', 'max:64'],
            'recommendation_signals.*.signal_key' => ['required', 'string', 'max:128'],
            'aliases' => ['present', 'array', 'max:1000'],
            'aliases.*.name' => ['required', 'string', 'max:255'],
            'aliases.*.type' => ['required', 'string', 'max:64'],
            'reviews' => ['present', 'array', 'max:10000'],
            'reviews.*.body' => ['required', 'string', 'max:200000'],
            'parse_meta' => ['required', 'array'],
            'parse_meta.has_info_list' => ['required', 'boolean'],
            'parse_meta.has_season_list' => ['required', 'boolean'],
            'parse_meta.has_episode_script' => ['required', 'boolean'],
            'parse_meta.provider_availability_status' => ['nullable', 'string', 'in:region_blocked'],
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @template TKey of array-key
     *
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): TKey  $key
     * @return list<array<string, mixed>>
     */
    private static function uniqueList(array $items, callable $key): array
    {
        return collect($items)->unique($key)->values()->all();
    }
}
