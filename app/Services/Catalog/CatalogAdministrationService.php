<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleSlug;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Api\V1\Sync\CatalogSyncChangePublisher;
use App\Services\Catalog\Search\CatalogSearchIndexer;
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

    private const TITLE_AUDIT_FIELDS = [
        'external_id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url',
        'is_published', 'publication_status', 'audience', 'available_from', 'available_until',
    ];

    private const SEASON_AUDIT_FIELDS = [
        'number', 'kind', 'sort_order', 'title', 'publication_status', 'audience', 'available_from', 'available_until',
    ];

    private const EPISODE_AUDIT_FIELDS = [
        'number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'publication_status', 'audience',
        'available_from', 'available_until',
    ];

    private const MEDIA_AUDIT_FIELDS = [
        'title', 'quality', 'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'status', 'audience',
        'available_from', 'available_until',
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogCacheInvalidator $cacheInvalidator,
        private readonly PlaybackSourceUrlGuard $playbackUrls,
        private readonly CatalogSearchIndexer $searchIndexer,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly CatalogSyncChangePublisher $syncChanges,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function updateTitle(
        User $user,
        CatalogTitle $title,
        array $attributes,
        string $expectedVersion,
    ): CatalogTitle {
        Gate::forUser($user)->authorize('update', $title);

        if (($attributes['publication_status'] ?? null) !== $title->publication_status->value) {
            Gate::forUser($user)->authorize(AdminPermission::ContentPublish->value);
        }

        return $this->persistTitle($user, $title, $attributes, $expectedVersion, AdminAuditAction::TitleUpdated);
    }

    public function archiveTitle(User $user, CatalogTitle $title, string $expectedVersion): CatalogTitle
    {
        Gate::forUser($user)->authorize('archive', $title);

        return $this->persistTitle($user, $title, [
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
        ], $expectedVersion, AdminAuditAction::TitleArchived);
    }

    /** @param array<string, mixed> $attributes */
    private function persistTitle(
        User $user,
        CatalogTitle $title,
        array $attributes,
        string $expectedVersion,
        AdminAuditAction $action,
    ): CatalogTitle {
        $updated = $this->withUniqueConstraintMessage(function () use ($user, $title, $attributes, $expectedVersion, $action): CatalogTitle {
            return DB::transaction(function () use ($user, $title, $attributes, $expectedVersion, $action): CatalogTitle {
                $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $beforeVersion = $this->titleVersion($current);
                $beforeAttributes = $this->auditAttributes($current, self::TITLE_AUDIT_FIELDS);
                $this->assertVersion($expectedVersion, $beforeVersion);
                $publicationStatus = PublicationStatus::from((string) $attributes['publication_status']);
                $nextSlug = (string) $attributes['slug'];

                if ($current->slug !== $nextSlug) {
                    CatalogTitleSlug::query()
                        ->whereBelongsTo($current)
                        ->where('slug', $nextSlug)
                        ->delete();
                    CatalogTitleSlug::query()->firstOrCreate([
                        'slug' => $current->slug,
                    ], [
                        'catalog_title_id' => $current->id,
                    ]);
                }

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

                $saved = $current->fresh();
                $this->auditRecorder->record(
                    $user,
                    $action,
                    $saved,
                    $beforeVersion,
                    $this->titleVersion($saved),
                    $this->changedAuditFields($beforeAttributes, $saved, self::TITLE_AUDIT_FIELDS),
                );

                return $saved;
            }, attempts: 3);
        }, 'titleForm', __('administration.catalog.validation.title_unique'));

        $this->invalidate($updated, (string) $title->slug);

        return $updated;
    }

    public function attachRelation(
        User $user,
        CatalogTitle $title,
        string $type,
        int $relationId,
        string $expectedVersion,
    ): CatalogTitle {
        Gate::forUser($user)->authorize('update', $title);

        $updated = DB::transaction(function () use ($user, $title, $type, $relationId, $expectedVersion): CatalogTitle {
            $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $beforeVersion = $this->titleVersion($current);
            $this->assertVersion($expectedVersion, $beforeVersion);
            $relation = $this->relation($type);
            $modelClass = $this->taxonomies->modelClass($type);
            $record = $modelClass::query()->findOrFail($relationId);

            $current->{$relation}()->syncWithoutDetaching([$record->getKey()]);
            $current->forceFill(['indexed_at' => now()])->touch();

            $saved = $current->fresh();
            $this->auditRecorder->record(
                $user,
                AdminAuditAction::RelationAttached,
                $saved,
                $beforeVersion,
                $this->titleVersion($saved),
                ['relations.'.$type],
            );

            return $saved;
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

        $updated = DB::transaction(function () use ($user, $title, $type, $relationId, $expectedVersion): CatalogTitle {
            $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $beforeVersion = $this->titleVersion($current);
            $this->assertVersion($expectedVersion, $beforeVersion, 'relation');
            $relation = $this->relation($type);
            $current->{$relation}()->detach($relationId);
            $current->forceFill(['indexed_at' => now()])->touch();

            $saved = $current->fresh();
            $this->auditRecorder->record(
                $user,
                AdminAuditAction::RelationDetached,
                $saved,
                $beforeVersion,
                $this->titleVersion($saved),
                ['relations.'.$type],
            );

            return $saved;
        }, attempts: 3);

        $this->invalidate($updated);

        return $updated;
    }

    /** @param array<string, mixed> $attributes */
    public function createLookup(User $user, CatalogTitle $title, string $type, array $attributes, string $expectedVersion): CatalogTitle
    {
        Gate::forUser($user)->authorize(AdminPermission::ContentCreate->value);

        $updated = $this->withUniqueConstraintMessage(function () use ($user, $title, $type, $attributes, $expectedVersion): CatalogTitle {
            return DB::transaction(function () use ($user, $title, $type, $attributes, $expectedVersion): CatalogTitle {
                $current = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $beforeVersion = $this->titleVersion($current);
                $this->assertVersion($expectedVersion, $beforeVersion, 'lookupForm');
                $relation = $this->relation($type);
                $modelClass = $this->taxonomies->modelClass($type);
                $record = $modelClass::query()->create(Arr::only($attributes, ['name', 'slug']));

                $current->{$relation}()->syncWithoutDetaching([$record->getKey()]);
                $current->forceFill(['indexed_at' => now()])->touch();

                $saved = $current->fresh();
                $this->auditRecorder->record(
                    $user,
                    AdminAuditAction::LookupCreated,
                    $saved,
                    $beforeVersion,
                    $this->titleVersion($saved),
                    ['relations.'.$type],
                );

                return $saved;
            }, attempts: 3);
        }, 'lookupForm.slug', __('administration.catalog.validation.lookup_slug_unique'));

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
        Gate::forUser($user)->authorize(($season === null ? AdminPermission::ContentCreate : AdminPermission::ContentManage)->value);

        if (($attributes['publication_status'] ?? null) === PublicationStatus::Published->value
            && $season?->publication_status !== PublicationStatus::Published) {
            Gate::forUser($user)->authorize(AdminPermission::ContentPublish->value);
        }

        return $this->persistSeason(
            $user,
            $title,
            $attributes,
            $season,
            $expectedVersion,
            $season === null ? AdminAuditAction::SeasonCreated : AdminAuditAction::SeasonUpdated,
        );
    }

    public function archiveSeason(User $user, CatalogTitle $title, Season $season, string $expectedVersion): Season
    {
        Gate::forUser($user)->authorize(AdminPermission::ContentDelete->value);

        return $this->persistSeason($user, $title, [
            ...Arr::only($season->getAttributes(), ['number', 'kind', 'sort_order', 'title', 'audience', 'available_from', 'available_until']),
            'publication_status' => PublicationStatus::Hidden->value,
        ], $season, $expectedVersion, AdminAuditAction::SeasonArchived);
    }

    /** @param array<string, mixed> $attributes */
    private function persistSeason(
        User $user,
        CatalogTitle $title,
        array $attributes,
        ?Season $season,
        string $expectedVersion,
        AdminAuditAction $action,
    ): Season {
        $saved = $this->withUniqueConstraintMessage(function () use ($user, $title, $attributes, $season, $expectedVersion, $action): Season {
            return DB::transaction(function () use ($user, $title, $attributes, $season, $expectedVersion, $action): Season {
                $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $current = $season !== null
                    ? Season::query()->withTrashed()->whereBelongsTo($currentTitle)->lockForUpdate()->findOrFail($season->id)
                    : new Season(['catalog_title_id' => $currentTitle->id]);
                $beforeVersion = $current->exists
                    ? $this->seasonVersion($current)
                    : AdminAuditRecorder::ABSENT_VERSION;
                $beforeAttributes = $current->exists
                    ? $this->auditAttributes($current, self::SEASON_AUDIT_FIELDS)
                    : [];

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $beforeVersion, 'seasonForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'number', 'kind', 'sort_order', 'title', 'publication_status', 'audience', 'available_from', 'available_until',
                ]))->save();
                $this->touchTitle($currentTitle);

                $savedSeason = $current->fresh();
                $this->auditRecorder->record(
                    $user,
                    $action,
                    $savedSeason,
                    $beforeVersion,
                    $this->seasonVersion($savedSeason),
                    $beforeAttributes === []
                        ? $this->createdAuditFields($attributes, self::SEASON_AUDIT_FIELDS)
                        : $this->changedAuditFields($beforeAttributes, $savedSeason, self::SEASON_AUDIT_FIELDS),
                );

                return $savedSeason;
            }, attempts: 3);
        }, 'seasonForm.number', __('administration.catalog.validation.season_unique'));

        $this->invalidate($title);

        return $saved;
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
        Gate::forUser($user)->authorize(($episode === null ? AdminPermission::ContentCreate : AdminPermission::ContentManage)->value);

        if (($attributes['publication_status'] ?? null) === PublicationStatus::Published->value
            && $episode?->publication_status !== PublicationStatus::Published) {
            Gate::forUser($user)->authorize(AdminPermission::ContentPublish->value);
        }

        return $this->persistEpisode(
            $user,
            $title,
            $season,
            $attributes,
            $episode,
            $expectedVersion,
            $episode === null ? AdminAuditAction::EpisodeCreated : AdminAuditAction::EpisodeUpdated,
        );
    }

    public function archiveEpisode(User $user, CatalogTitle $title, Season $season, Episode $episode, string $expectedVersion): Episode
    {
        Gate::forUser($user)->authorize(AdminPermission::ContentDelete->value);

        return $this->persistEpisode($user, $title, $season, [
            ...Arr::only($episode->getAttributes(), ['number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'audience', 'available_from', 'available_until']),
            'publication_status' => PublicationStatus::Hidden->value,
        ], $episode, $expectedVersion, AdminAuditAction::EpisodeArchived);
    }

    /** @param array<string, mixed> $attributes */
    private function persistEpisode(
        User $user,
        CatalogTitle $title,
        Season $season,
        array $attributes,
        ?Episode $episode,
        string $expectedVersion,
        AdminAuditAction $action,
    ): Episode {
        $saved = $this->withUniqueConstraintMessage(function () use ($user, $title, $season, $attributes, $episode, $expectedVersion, $action): Episode {
            return DB::transaction(function () use ($user, $title, $season, $attributes, $episode, $expectedVersion, $action): Episode {
                $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
                $currentSeason = Season::query()->withTrashed()->whereBelongsTo($currentTitle)->lockForUpdate()->findOrFail($season->id);
                $current = $episode !== null
                    ? Episode::query()->withTrashed()->whereBelongsTo($currentSeason)->lockForUpdate()->findOrFail($episode->id)
                    : new Episode(['season_id' => $currentSeason->id]);
                $beforeVersion = $current->exists
                    ? $this->episodeVersion($current)
                    : AdminAuditRecorder::ABSENT_VERSION;
                $beforeAttributes = $current->exists
                    ? $this->auditAttributes($current, self::EPISODE_AUDIT_FIELDS)
                    : [];

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $beforeVersion, 'episodeForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'number', 'kind', 'sort_order', 'title', 'released_at', 'summary', 'publication_status', 'audience', 'available_from', 'available_until',
                ]))->save();
                $this->touchTitle($currentTitle);

                $savedEpisode = $current->fresh();
                $this->auditRecorder->record(
                    $user,
                    $action,
                    $savedEpisode,
                    $beforeVersion,
                    $this->episodeVersion($savedEpisode),
                    $beforeAttributes === []
                        ? $this->createdAuditFields($attributes, self::EPISODE_AUDIT_FIELDS)
                        : $this->changedAuditFields($beforeAttributes, $savedEpisode, self::EPISODE_AUDIT_FIELDS),
                );

                return $savedEpisode;
            }, attempts: 3);
        }, 'episodeForm.number', __('administration.catalog.validation.episode_unique'));

        $this->invalidate($title);

        return $saved;
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
        Gate::forUser($user)->authorize(AdminPermission::SourcesManage->value);

        if (($attributes['status'] ?? null) === 'published' && $media?->status !== 'published') {
            Gate::forUser($user)->authorize(AdminPermission::ContentPublish->value);
        }

        return $this->persistMedia(
            $user,
            $title,
            $season,
            $episode,
            $attributes,
            $media,
            $expectedVersion,
            $media === null ? AdminAuditAction::MediaCreated : AdminAuditAction::MediaUpdated,
        );
    }

    public function archiveMedia(User $user, CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media, string $expectedVersion): LicensedMedia
    {
        Gate::forUser($user)->authorize(AdminPermission::SourcesDisable->value);

        return $this->persistMedia($user, $title, $season, $episode, [
            ...Arr::only($media->getAttributes(), ['title', 'quality', 'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'audience', 'available_from', 'available_until']),
            'status' => 'draft',
        ], $media, $expectedVersion, AdminAuditAction::MediaArchived);
    }

    /** @param array<string, mixed> $attributes */
    private function persistMedia(
        User $user,
        CatalogTitle $title,
        Season $season,
        Episode $episode,
        array $attributes,
        ?LicensedMedia $media,
        string $expectedVersion,
        AdminAuditAction $action,
    ): LicensedMedia {
        $playbackUrl = $media === null
            ? $this->playbackUrls->safeExternalUrl($attributes['playback_url'] ?? null)
            : null;

        if ($media === null && $playbackUrl === null) {
            throw ValidationException::withMessages([
                'mediaForm.playback_url' => __('administration.catalog.validation.source_host'),
            ]);
        }

        $saved = $this->withUniqueConstraintMessage(function () use ($user, $title, $season, $episode, $attributes, $media, $expectedVersion, $playbackUrl, $action): LicensedMedia {
            return DB::transaction(function () use ($user, $title, $season, $episode, $attributes, $media, $expectedVersion, $playbackUrl, $action): LicensedMedia {
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
                $beforeVersion = $current->exists
                    ? $this->mediaVersion($current)
                    : AdminAuditRecorder::ABSENT_VERSION;
                $beforeAttributes = $current->exists
                    ? $this->auditAttributes($current, self::MEDIA_AUDIT_FIELDS)
                    : [];

                if ($current->exists) {
                    $this->assertVersion($expectedVersion, $beforeVersion, 'mediaForm');
                }

                $current->forceFill(Arr::only($attributes, [
                    'title', 'quality', 'translation_name', 'format', 'has_subtitles', 'duration_seconds', 'status', 'audience', 'available_from', 'available_until',
                ]));

                if (! $current->exists || $current->file_size_check_status === null) {
                    $current->resetFileSizeInspection();
                }

                $current->published_at = $current->status === 'published' ? ($current->published_at ?? now()) : null;
                $current->save();
                $this->touchTitle($currentTitle);

                $savedMedia = $current->fresh();
                $changedFields = $beforeAttributes === []
                    ? $this->createdAuditFields($attributes, self::MEDIA_AUDIT_FIELDS)
                    : $this->changedAuditFields($beforeAttributes, $savedMedia, self::MEDIA_AUDIT_FIELDS);

                if ($media === null) {
                    $changedFields[] = 'source';
                }

                $this->auditRecorder->record(
                    $user,
                    $action,
                    $savedMedia,
                    $beforeVersion,
                    $this->mediaVersion($savedMedia),
                    $changedFields,
                );

                return $savedMedia;
            }, attempts: 3);
        }, 'mediaForm.playback_url', __('administration.catalog.validation.source_unique'));

        $this->invalidate($title);

        return $saved;
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
                'relation' => __('administration.catalog.validation.relation_invalid'),
            ]);
        }

        return $this->taxonomies->relationName($type);
    }

    private function assertVersion(string $expected, string $actual, string $errorKey = 'titleForm'): void
    {
        if ($expected === '' || ! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages([
                $errorKey => __('administration.catalog.validation.stale'),
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

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function auditAttributes(Model $model, array $fields): array
    {
        return Arr::only($model->getRawOriginal(), $fields);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function changedAuditFields(array $before, Model $after, array $fields): array
    {
        $afterAttributes = $this->auditAttributes($after, $fields);

        return array_values(array_filter(
            $fields,
            fn (string $field): bool => ($before[$field] ?? null) !== ($afterAttributes[$field] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function createdAuditFields(array $attributes, array $fields): array
    {
        return array_values(array_intersect($fields, array_keys($attributes)));
    }

    private function invalidate(CatalogTitle $title, ?string $previousSlug = null): void
    {
        CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $title->id)
            ->orWhere('recommended_title_id', $title->id)
            ->delete();
        $this->searchIndexer->synchronizeTitleIds([$title->id]);
        $this->cacheInvalidator->catalogChanged([(int) $title->id]);
        $this->syncChanges->publishUpsert($title, $previousSlug);
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
