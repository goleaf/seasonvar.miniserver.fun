<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\PlaybackSourceUrlGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogAdministrationService
{
    private const TITLE_VERSION_FIELDS = [
        'source_id',
        'external_id',
        'slug',
        'title',
        'original_title',
        'type',
        'year',
        'description',
        'poster_url',
        'is_published',
        'publication_status',
        'audience',
        'available_from',
        'available_until',
        'deleted_at',
        'updated_at',
    ];

    private const SEASON_VERSION_FIELDS = [
        'catalog_title_id', 'number', 'kind', 'sort_order', 'title', 'publication_status', 'audience',
        'available_from', 'available_until', 'deleted_at', 'updated_at',
    ];

    private const EPISODE_VERSION_FIELDS = [
        'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'publication_status',
        'audience', 'available_from', 'available_until', 'deleted_at', 'updated_at',
    ];

    private const MEDIA_VERSION_FIELDS = [
        'catalog_title_id', 'season_id', 'episode_id', 'title', 'storage_disk', 'source_media_key', 'quality',
        'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'status', 'audience',
        'available_from', 'available_until', 'deleted_at', 'updated_at',
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogStatsSnapshotCache $statsSnapshots,
        private readonly PlaybackSourceUrlGuard $playbackUrls,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function updateTitle(
        User $user,
        CatalogTitle $title,
        array $attributes,
        string $expectedVersion,
    ): CatalogTitle {
        Gate::forUser($user)->authorize('update', $title);

        $updated = $this->withUniqueConstraintMessage(function () use ($title, $attributes, $expectedVersion): CatalogTitle {
            return DB::transaction(function () use ($title, $attributes, $expectedVersion): CatalogTitle {
                $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $this->assertVersion($expectedVersion, $this->titleVersion($current));
                $publicationStatus = PublicationStatus::from((string) $attributes['publication_status']);

                $current->forceFill([
                    ...Arr::only($attributes, [
                        'external_id',
                        'slug',
                        'title',
                        'original_title',
                        'type',
                        'year',
                        'description',
                        'poster_url',
                        'audience',
                        'available_from',
                        'available_until',
                    ]),
                    'publication_status' => $publicationStatus->value,
                    'is_published' => $publicationStatus === PublicationStatus::Published,
                    'indexed_at' => now(),
                ])->save();

                return $current->fresh();
            }, attempts: 3);
        }, 'titleForm', 'Slug или внешний ID уже занят другой записью.');

        $this->invalidate($updated);

        return $updated;
    }

    public function archiveTitle(User $user, CatalogTitle $title, string $expectedVersion): CatalogTitle
    {
        Gate::forUser($user)->authorize('archive', $title);

        return $this->updateTitle($user, $title, [
            'external_id' => $title->external_id,
            'slug' => $title->slug,
            'title' => $title->title,
            'original_title' => $title->original_title,
            'type' => $title->type,
            'year' => $title->year,
            'description' => $title->description,
            'poster_url' => $title->poster_url,
            'publication_status' => PublicationStatus::Hidden->value,
            'audience' => $title->audience->value,
            'available_from' => $title->available_from,
            'available_until' => $title->available_until,
        ], $expectedVersion);
    }

    public function attachRelation(
        User $user,
        CatalogTitle $title,
        string $type,
        int $relationId,
        string $expectedVersion,
    ): CatalogTitle {
        Gate::forUser($user)->authorize('update', $title);

        $updated = DB::transaction(function () use ($title, $type, $relationId, $expectedVersion): CatalogTitle {
            $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $this->assertVersion($expectedVersion, $this->titleVersion($current));
            $relation = $this->relation($type);
            $modelClass = $this->taxonomies->modelClass($type);
            $record = $modelClass::query()->findOrFail($relationId);

            $current->{$relation}()->syncWithoutDetaching([$record->getKey()]);
            $current->forceFill(['indexed_at' => now()])->touch();

            return $current->fresh();
        }, attempts: 3);

        $this->invalidate($updated);

        return $updated;
    }

    public function detachRelation(
        User $user,
        CatalogTitle $title,
        string $type,
        int $relationId,
        string $expectedVersion,
    ): CatalogTitle {
        Gate::forUser($user)->authorize('update', $title);

        $updated = DB::transaction(function () use ($title, $type, $relationId, $expectedVersion): CatalogTitle {
            $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $this->assertVersion($expectedVersion, $this->titleVersion($current), 'relation');
            $relation = $this->relation($type);
            $current->{$relation}()->detach($relationId);
            $current->forceFill(['indexed_at' => now()])->touch();

            return $current->fresh();
        }, attempts: 3);

        $this->invalidate($updated);

        return $updated;
    }

    /** @param array<string, mixed> $attributes */
    public function createLookup(User $user, CatalogTitle $title, string $type, array $attributes, string $expectedVersion): CatalogTitle
    {
        Gate::forUser($user)->authorize('update', $title);

        $updated = $this->withUniqueConstraintMessage(function () use ($title, $type, $attributes, $expectedVersion): CatalogTitle {
            return DB::transaction(function () use ($title, $type, $attributes, $expectedVersion): CatalogTitle {
                $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $this->assertVersion($expectedVersion, $this->titleVersion($current), 'lookupForm');
                $relation = $this->relation($type);
                $modelClass = $this->taxonomies->modelClass($type);
                $record = $modelClass::query()->create(Arr::only($attributes, ['name', 'slug']));

                $current->{$relation}()->syncWithoutDetaching([$record->getKey()]);
                $current->forceFill(['indexed_at' => now()])->touch();

                return $current->fresh();
            }, attempts: 3);
        }, 'lookupForm.slug', 'Такой slug справочника уже существует.');

        $this->invalidate($updated);

        return $updated;
    }

    /** @param array<string, mixed> $attributes */
    public function saveSeason(
        User $user,
        CatalogTitle $title,
        array $attributes,
        ?Season $season,
        string $expectedVersion,
    ): Season {
        Gate::forUser($user)->authorize('update', $title);

        $saved = $this->withUniqueConstraintMessage(function () use ($title, $attributes, $season, $expectedVersion): Season {
            return DB::transaction(function () use ($title, $attributes, $season, $expectedVersion): Season {
                $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $current = $season !== null
                    ? Season::query()->withTrashed()->whereBelongsTo($currentTitle)->lockForUpdate()->findOrFail($season->id)
                    : new Season(['catalog_title_id' => $currentTitle->id]);

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $this->seasonVersion($current), 'seasonForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'number', 'kind', 'sort_order', 'title', 'publication_status', 'audience', 'available_from', 'available_until',
                ]))->save();
                $this->touchTitle($currentTitle);

                return $current->fresh();
            }, attempts: 3);
        }, 'seasonForm.number', 'Сезон с таким номером и типом уже существует.');

        $this->invalidate($title);

        return $saved;
    }

    public function archiveSeason(User $user, CatalogTitle $title, Season $season, string $expectedVersion): Season
    {
        return $this->saveSeason($user, $title, [
            ...Arr::only($season->getAttributes(), ['number', 'kind', 'sort_order', 'title', 'audience', 'available_from', 'available_until']),
            'publication_status' => PublicationStatus::Hidden->value,
        ], $season, $expectedVersion);
    }

    /** @param array<string, mixed> $attributes */
    public function saveEpisode(
        User $user,
        CatalogTitle $title,
        Season $season,
        array $attributes,
        ?Episode $episode,
        string $expectedVersion,
    ): Episode {
        Gate::forUser($user)->authorize('update', $title);

        $saved = $this->withUniqueConstraintMessage(function () use ($title, $season, $attributes, $episode, $expectedVersion): Episode {
            return DB::transaction(function () use ($title, $season, $attributes, $episode, $expectedVersion): Episode {
                $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $currentSeason = Season::query()->withTrashed()->whereBelongsTo($currentTitle)->lockForUpdate()->findOrFail($season->id);
                $current = $episode !== null
                    ? Episode::query()->withTrashed()->whereBelongsTo($currentSeason)->lockForUpdate()->findOrFail($episode->id)
                    : new Episode(['season_id' => $currentSeason->id]);

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $this->episodeVersion($current), 'episodeForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'publication_status', 'audience', 'available_from', 'available_until',
                ]))->save();
                $this->touchTitle($currentTitle);

                return $current->fresh();
            }, attempts: 3);
        }, 'episodeForm.number', 'Серия с таким номером и типом уже существует.');

        $this->invalidate($title);

        return $saved;
    }

    public function archiveEpisode(User $user, CatalogTitle $title, Season $season, Episode $episode, string $expectedVersion): Episode
    {
        return $this->saveEpisode($user, $title, $season, [
            ...Arr::only($episode->getAttributes(), ['number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'audience', 'available_from', 'available_until']),
            'publication_status' => PublicationStatus::Hidden->value,
        ], $episode, $expectedVersion);
    }

    /** @param array<string, mixed> $attributes */
    public function saveMedia(
        User $user,
        CatalogTitle $title,
        Season $season,
        Episode $episode,
        array $attributes,
        ?LicensedMedia $media,
        string $expectedVersion,
    ): LicensedMedia {
        Gate::forUser($user)->authorize('update', $title);
        $playbackUrl = $media === null
            ? $this->playbackUrls->safeExternalUrl($attributes['playback_url'] ?? null)
            : null;

        if ($media === null && $playbackUrl === null) {
            throw ValidationException::withMessages([
                'mediaForm.playback_url' => 'Источник должен использовать разрешённый HTTPS-хост.',
            ]);
        }

        $saved = $this->withUniqueConstraintMessage(function () use ($title, $season, $episode, $attributes, $media, $expectedVersion, $playbackUrl): LicensedMedia {
            return DB::transaction(function () use ($title, $season, $episode, $attributes, $media, $expectedVersion, $playbackUrl): LicensedMedia {
                $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $currentSeason = Season::query()->withTrashed()->whereBelongsTo($currentTitle)->lockForUpdate()->findOrFail($season->id);
                $currentEpisode = Episode::query()->withTrashed()->whereBelongsTo($currentSeason)->lockForUpdate()->findOrFail($episode->id);
                $current = $media !== null
                    ? LicensedMedia::query()->withTrashed()->whereBelongsTo($currentTitle, 'catalogTitle')->whereBelongsTo($currentEpisode)->lockForUpdate()->findOrFail($media->id)
                    : new LicensedMedia([
                        'catalog_title_id' => $currentTitle->id,
                        'season_id' => $currentSeason->id,
                        'episode_id' => $currentEpisode->id,
                        'storage_disk' => 'external_playlist',
                        'path' => $playbackUrl,
                        'playback_url' => $playbackUrl,
                        'source_media_key' => hash('sha256', 'admin|'.$currentTitle->id.'|'.$currentEpisode->id.'|'.$playbackUrl),
                        'check_status' => 'not_checked',
                    ]);

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $this->mediaVersion($current), 'mediaForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'title', 'quality', 'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'status', 'audience', 'available_from', 'available_until',
                ]));
                $current->published_at = $current->status === 'published' ? ($current->published_at ?? now()) : null;
                $current->save();
                $this->touchTitle($currentTitle);

                return $current->fresh();
            }, attempts: 3);
        }, 'mediaForm.playback_url', 'Такой видеоисточник уже существует у сериала.');

        $this->invalidate($title);

        return $saved;
    }

    public function archiveMedia(User $user, CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media, string $expectedVersion): LicensedMedia
    {
        return $this->saveMedia($user, $title, $season, $episode, [
            ...Arr::only($media->getAttributes(), ['title', 'quality', 'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'audience', 'available_from', 'available_until']),
            'status' => 'draft',
        ], $media, $expectedVersion);
    }

    public function titleVersion(CatalogTitle $title): string
    {
        $relations = [];

        foreach ($this->editableRelations() as $type) {
            $relation = $this->taxonomies->relationName($type);
            $relations[$type] = $title->{$relation}()->orderBy('id')->pluck('id')->all();
        }

        return $this->version($title, $relations, self::TITLE_VERSION_FIELDS);
    }

    public function seasonVersion(Season $season): string
    {
        return $this->version($season, [], self::SEASON_VERSION_FIELDS);
    }

    public function episodeVersion(Episode $episode): string
    {
        return $this->version($episode, [], self::EPISODE_VERSION_FIELDS);
    }

    public function mediaVersion(LicensedMedia $media): string
    {
        return $this->version($media, [], self::MEDIA_VERSION_FIELDS);
    }

    /** @return list<string> */
    public function editableRelations(): array
    {
        return ['actor', 'director', 'genre', 'country', 'translation'];
    }

    private function relation(string $type): string
    {
        if (! in_array($type, $this->editableRelations(), true)) {
            throw ValidationException::withMessages([
                'relation' => 'Выбран неподдерживаемый тип связи.',
            ]);
        }

        return $this->taxonomies->relationName($type);
    }

    private function assertVersion(string $expected, string $actual, string $errorKey = 'titleForm'): void
    {
        if ($expected === '' || ! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages([
                $errorKey => 'Запись уже изменена другим процессом. Обновите форму и повторите правку.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $fields
     */
    private function version(Model $model, array $extra, array $fields): string
    {
        return hash('sha256', json_encode([
            'attributes' => Arr::only($model->getRawOriginal(), $fields),
            'extra' => $extra,
        ], JSON_THROW_ON_ERROR));
    }

    private function invalidate(CatalogTitle $title): void
    {
        CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $title->id)
            ->orWhere('recommended_title_id', $title->id)
            ->delete();
        $this->statsSnapshots->forget();
    }

    private function touchTitle(CatalogTitle $title): void
    {
        $title->forceFill(['indexed_at' => now()])->touch();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withUniqueConstraintMessage(Closure $callback, string $errorKey, string $message): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$errorKey => $message]);
        }
    }
}
