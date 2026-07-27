<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleSlug;
use App\Models\Episode;
use App\Models\Season;
use App\Models\SourcePage;
use App\Support\CatalogTitleDisplayName;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class SeasonvarCatalogTitleWriter
{
    public function __construct(
        private SeasonvarUrl $seasonvarUrl,
        private SeasonvarCatalogIdentityResolver $identityResolver,
        private SeasonvarEditorialFieldResolver $editorialFields,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function upsertTitle(
        SourcePage $page,
        SeasonvarCatalogData $data,
        string $contentHash,
        ?callable $progress = null,
        ?CatalogTitle $preferredCatalogTitle = null,
    ): CatalogTitle {
        $sourceUrlHash = $this->seasonvarUrl->hash($page->url);
        $catalogTitle = $this->identityResolver->resolve(
            $page,
            $data,
            $sourceUrlHash,
            $preferredCatalogTitle,
        ) ?? new CatalogTitle([
            'source_id' => $page->source_id,
            'source_url_hash' => $sourceUrlHash,
        ]);
        $wasExisting = $catalogTitle->exists;

        $this->report($progress, 'catalog-title-upsert-started', [
            'source_page_id' => $page->id,
            'source_id' => $page->source_id,
            'existing' => $wasExisting,
            'source_url_hash' => $sourceUrlHash,
            'title' => $data->title,
        ]);

        if (! $catalogTitle->exists) {
            $catalogTitle->slug = $this->uniqueSlug(
                $data->title,
                $data->externalId,
                $sourceUrlHash,
                $catalogTitle->id,
            );
            $this->report($progress, 'catalog-title-slug-prepared', [
                'source_page_id' => $page->id,
                'catalog_title_id' => $catalogTitle->id,
                'slug' => $catalogTitle->slug,
            ]);
        }

        $isCanonicalSourcePage = ! $catalogTitle->exists
            || $catalogTitle->source_page_id === null
            || (int) $catalogTitle->source_page_id === (int) $page->id;
        $editorial = $this->editorialFields->resolve($catalogTitle, $data);

        $catalogTitle->fill([
            'source_page_id' => $catalogTitle->source_page_id ?? $page->id,
            'external_id' => $catalogTitle->external_id ?? $data->externalId,
            ...$editorial['values'],
            'original_title' => $editorial['values']['original_title'],
            'year' => $this->earliestYear($catalogTitle->year, $data->year),
            'source_url' => $catalogTitle->source_url ?: $page->url,
            'source_url_hash' => $catalogTitle->source_url_hash ?: $sourceUrlHash,
            'content_hash' => $isCanonicalSourcePage ? $contentHash : $catalogTitle->content_hash,
            'provider_field_values' => $editorial['provider_field_values'],
            'indexed_at' => now(),
        ]);

        if (! $catalogTitle->exists) {
            $catalogTitle->fill([
                'is_published' => true,
                'publication_status' => PublicationStatus::Published,
                'audience' => ContentAudience::Public,
            ]);
        }

        $catalogTitle->save();
        $this->report($progress, $wasExisting ? 'catalog-title-updated' : 'catalog-title-created', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'slug' => $catalogTitle->slug,
            'title' => $catalogTitle->title,
            'year' => $catalogTitle->year,
            'external_id' => $catalogTitle->external_id,
            'content_hash' => $catalogTitle->content_hash,
        ]);

        return $catalogTitle;
    }

    /**
     * @param  list<array{name: string, type: string, source: string}>  $aliases
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncAliases(
        CatalogTitle $catalogTitle,
        array $aliases,
        ?callable $progress = null,
    ): void {
        $now = now();
        $displayName = CatalogTitleDisplayName::from(
            $catalogTitle->title,
            $catalogTitle->original_title,
        );
        $rows = collect($aliases)
            ->filter(fn (array $alias): bool => $this->isValidAliasName($alias['name']))
            ->map(function (array $alias) use ($catalogTitle, $displayName, $now): ?array {
                $name = Str::squish($alias['name']);

                if ($displayName->contains($name)) {
                    return null;
                }

                $type = Str::substr(Str::slug($alias['type']) ?: 'alternative', 0, 32);

                return [
                    'catalog_title_id' => $catalogTitle->id,
                    'name' => $name,
                    'name_hash' => CatalogTitleDisplayName::nameHash($name),
                    'type' => $type,
                    'source' => Str::substr($alias['source'], 0, 64),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->reduce(function (Collection $rows, array $row): Collection {
                $existing = $rows->get($row['name_hash']);
                $priority = ['original' => 3, 'alternative' => 2, 'source-title' => 1];

                if (! is_array($existing)
                    || ($priority[$row['type']] ?? 0) > ($priority[$existing['type']] ?? 0)
                ) {
                    $rows->put($row['name_hash'], $row);
                }

                return $rows;
            }, collect());

        if ($rows->isNotEmpty()) {
            CatalogTitleAlias::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'name_hash'],
                ['name', 'type', 'source', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-aliases-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'aliases' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{provider: string, rating: float|null, votes: int|null, raw_value: string}>  $ratings
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncRatings(
        CatalogTitle $catalogTitle,
        array $ratings,
        ?callable $progress = null,
    ): void {
        $now = now();
        $rows = collect($ratings)
            ->filter(
                fn (array $rating): bool => in_array(
                    $rating['provider'],
                    ['imdb', 'kinopoisk'],
                    true,
                ),
            )
            ->mapWithKeys(fn (array $rating): array => [$rating['provider'] => [
                'catalog_title_id' => $catalogTitle->id,
                'provider' => $rating['provider'],
                'rating' => $rating['rating'],
                'votes' => $rating['votes'],
                'raw_value' => Str::substr($rating['raw_value'], 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

        if ($rows->isNotEmpty()) {
            CatalogTitleRating::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'provider'],
                ['rating', 'votes', 'raw_value', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-ratings-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'ratings' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{author: string|null, body: string, published_at: string|null}>  $reviews
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncReviews(
        CatalogTitle $catalogTitle,
        SourcePage $page,
        array $reviews,
        ?callable $progress = null,
    ): void {
        $now = now();
        $rows = collect($reviews)
            ->filter(fn (array $review): bool => trim($review['body']) !== '')
            ->mapWithKeys(function (array $review) use ($catalogTitle, $page, $now): array {
                $body = Str::squish($review['body']);
                $bodyHash = hash('sha256', Str::lower($body));

                return [$bodyHash => [
                    'catalog_title_id' => $catalogTitle->id,
                    'source_page_id' => $page->id,
                    'author' => $review['author']
                        ? Str::substr(Str::squish($review['author']), 0, 120)
                        : null,
                    'body' => $body,
                    'body_hash' => $bodyHash,
                    'published_at' => $review['published_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });

        if ($rows->isNotEmpty()) {
            CatalogTitleReview::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'body_hash'],
                ['source_page_id', 'author', 'body', 'published_at', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-reviews-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'reviews' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>  $seasons
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<int, Season>
     */
    public function syncSeasons(
        CatalogTitle $catalogTitle,
        SourcePage $page,
        array $seasons,
        ?callable $progress = null,
    ): array {
        $this->report($progress, 'season-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'total' => count($seasons),
        ]);
        $now = now();
        $rowsByNumber = collect($seasons)
            ->filter(fn (array $season): bool => (int) $season['number'] > 0)
            ->mapWithKeys(function (array $season) use ($catalogTitle, $page, $now): array {
                $number = (int) $season['number'];

                return [$number => [
                    'catalog_title_id' => $catalogTitle->id,
                    'number' => $number,
                    'kind' => ReleaseKind::Regular->value,
                    'sort_order' => $number,
                    'source_page_id' => $page->id,
                    'title' => $season['title'],
                    'source_url' => $season['source_url'],
                    'source_url_hash' => $season['source_url']
                        ? $this->seasonvarUrl->hash($season['source_url'])
                        : null,
                    'latest_episode_released_at' => $season['latest_episode_released_at'] ?? null,
                    'episodes_released' => $season['episodes_released'] ?? null,
                    'episodes_total' => $season['episodes_total'] ?? null,
                    'translation_name' => $season['translation_name'] ?? null,
                    'release_status_text' => $season['release_status_text'] ?? null,
                    'publication_status' => PublicationStatus::Published->value,
                    'audience' => ContentAudience::Public->value,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });
        $existingSeasons = $rowsByNumber->isNotEmpty()
            ? Season::withTrashed()
                ->where('catalog_title_id', $catalogTitle->id)
                ->where('kind', ReleaseKind::Regular->value)
                ->whereIn('number', $rowsByNumber->keys())
                ->get()
                ->keyBy(fn (Season $season): int => (int) $season->number)
            : collect();
        $rowsByNumber = $rowsByNumber->map(
            function (array $row, int $number) use ($existingSeasons): array {
                $existing = $existingSeasons->get($number);

                if ($existing === null) {
                    return $row;
                }

                foreach (
                    ['title', 'source_url', 'source_url_hash', 'translation_name', 'release_status_text'] as $field
                ) {
                    if ($row[$field] === null) {
                        $row[$field] = $existing->{$field};
                    }
                }

                return $row;
            },
        );
        $rowsForUpsert = $rowsByNumber->filter(
            fn (array $row, int $number): bool => $this->seasonRowChanged(
                $existingSeasons->get($number),
                $row,
            ),
        );

        if ($rowsForUpsert->isNotEmpty()) {
            Season::query()->upsert(
                $rowsForUpsert->values()->all(),
                ['catalog_title_id', 'kind', 'number'],
                [
                    'source_page_id',
                    'title',
                    'source_url',
                    'source_url_hash',
                    'latest_episode_released_at',
                    'episodes_released',
                    'episodes_total',
                    'translation_name',
                    'release_status_text',
                    'sort_order',
                    'updated_at',
                ],
            );
        }

        $syncedSeasons = Season::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('kind', ReleaseKind::Regular->value)
            ->whereIn('number', $rowsByNumber->keys())
            ->get()
            ->keyBy(fn (Season $season): int => (int) $season->number)
            ->all();
        $this->report($progress, 'season-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'synced' => count($syncedSeasons),
            'changed' => $rowsForUpsert->count(),
        ]);

        return $syncedSeasons;
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array{season_number: int, number: int, title: string|null, source_url: string|null}>  $episodes
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncEpisodes(
        array $seasons,
        SourcePage $page,
        array $episodes,
        ?callable $progress = null,
    ): void {
        $this->report($progress, 'episode-sync-started', [
            'source_page_id' => $page->id,
            'total' => count($episodes),
        ]);
        $now = now();
        $skipped = 0;
        $rowsByKey = collect($episodes)->mapWithKeys(
            function (array $episode) use ($seasons, $page, $now, &$skipped): array {
                $season = $seasons[$episode['season_number']] ?? null;

                if ($season === null || (int) $episode['number'] <= 0) {
                    $skipped++;

                    return [];
                }

                $number = (int) $episode['number'];

                return [$season->id.'|'.$number => [
                    'season_id' => $season->id,
                    'number' => $number,
                    'kind' => ReleaseKind::Regular->value,
                    'sort_order' => $number,
                    'source_page_id' => $page->id,
                    'title' => $episode['title'],
                    'source_url' => $episode['source_url'],
                    'source_url_hash' => $episode['source_url']
                        ? $this->seasonvarUrl->hash($episode['source_url'])
                        : null,
                    'publication_status' => PublicationStatus::Published->value,
                    'audience' => ContentAudience::Public->value,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            },
        );
        $seasonIds = $rowsByKey->pluck('season_id')->unique()->values();
        $existingEpisodes = $rowsByKey->isNotEmpty()
            ? Episode::withTrashed()
                ->whereIn('season_id', $seasonIds)
                ->where('kind', ReleaseKind::Regular->value)
                ->get()
                ->keyBy(fn (Episode $episode): string => $episode->season_id.'|'.$episode->number)
            : collect();
        $rowsByKey = $rowsByKey->map(
            function (array $row, string $key) use ($existingEpisodes): array {
                $existing = $existingEpisodes->get($key);

                if ($existing === null) {
                    return $row;
                }

                foreach (['title', 'source_url', 'source_url_hash'] as $field) {
                    if ($row[$field] === null) {
                        $row[$field] = $existing->{$field};
                    }
                }

                return $row;
            },
        );
        $rowsForUpsert = $rowsByKey->filter(
            fn (array $row, string $key): bool => $this->episodeRowChanged(
                $existingEpisodes->get($key),
                $row,
            ),
        );

        foreach ($rowsForUpsert->values()->chunk(50) as $rows) {
            Episode::query()->upsert(
                $rows->all(),
                ['season_id', 'kind', 'number'],
                ['source_page_id', 'title', 'source_url', 'source_url_hash', 'sort_order', 'updated_at'],
            );
        }

        $this->report($progress, 'episode-sync-complete', [
            'source_page_id' => $page->id,
            'synced' => $rowsByKey->count(),
            'changed' => $rowsForUpsert->count(),
            'skipped' => $skipped,
        ]);
    }

    private function earliestYear(?int $currentYear, ?int $incomingYear): ?int
    {
        return collect([$currentYear, $incomingYear])->filter()->min();
    }

    private function uniqueSlug(
        string $title,
        ?string $externalId,
        string $sourceUrlHash,
        ?int $ignoreId = null,
    ): string {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'title-'.($externalId ?: Str::substr($sourceUrlHash, 0, 12));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            CatalogTitle::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
            || CatalogTitleSlug::query()->where('slug', $slug)->exists()
        ) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function isValidAliasName(string $name): bool
    {
        $name = Str::squish($name);

        return $name !== ''
            && Str::length($name) <= 160
            && preg_match('/(?:главн|добро пожаловать|смотреть онлайн|seasonvar)/iu', $name) !== 1;
    }

    /** @param array<string, mixed> $row */
    private function seasonRowChanged(?Season $season, array $row): bool
    {
        if ($season === null) {
            return true;
        }

        foreach ([
            'source_page_id',
            'title',
            'source_url',
            'source_url_hash',
            'latest_episode_released_at',
            'episodes_released',
            'episodes_total',
            'translation_name',
            'release_status_text',
            'sort_order',
        ] as $field) {
            if ($this->comparableImportValue($field, $season->{$field})
                !== $this->comparableImportValue($field, $row[$field] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function episodeRowChanged(?Episode $episode, array $row): bool
    {
        if ($episode === null) {
            return true;
        }

        foreach (['source_page_id', 'title', 'source_url', 'source_url_hash', 'sort_order'] as $field) {
            if ($this->comparableImportValue($field, $episode->{$field})
                !== $this->comparableImportValue($field, $row[$field] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    private function comparableImportValue(string $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if (in_array($field, ['source_page_id', 'episodes_released', 'episodes_total'], true)) {
            return $value === null ? null : (int) $value;
        }

        return $value;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
