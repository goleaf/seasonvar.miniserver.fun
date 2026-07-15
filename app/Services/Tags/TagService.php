<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\TagData;
use App\Enums\AdminAuditAction;
use App\Enums\TagAliasSource;
use App\Enums\TagModerationStatus;
use App\Enums\TagProviderMappingStatus;
use App\Enums\TagSynonymRelationship;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleTagSource;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagMergeEvent;
use App\Models\TagProviderMapping;
use App\Models\TagSlug;
use App\Models\TagSynonym;
use App\Models\TagTranslation;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Catalog\CatalogRelationSourceIdentityRegistry;
use App\Support\PlainText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TagService
{
    private const VERSION_FIELDS = [
        'public_id', 'name', 'slug', 'code', 'type', 'visibility', 'moderation_status', 'source',
        'normalized_name_hash', 'content_version', 'merged_into_id', 'archived_at',
        'archived_from_visibility', 'archived_from_moderation_status', 'updated_at',
    ];

    public function __construct(
        private TagNormalizationService $normalizer,
        private TagSlugService $slugs,
        private TagCacheInvalidator $cache,
        private AdminAuditRecorder $audit,
        private CatalogRelationSourceIdentityRegistry $sourceIdentities,
    ) {}

    public function create(User $actor, TagData $data): Tag
    {
        Gate::forUser($actor)->authorize('create', Tag::class);
        $this->assertCreatableState($data);
        $prepared = $this->prepared($data);
        $publicId = (string) Str::uuid();
        $slug = $this->slugs->validated($data->slug, $prepared['name'], $publicId);

        try {
            $tag = DB::transaction(function () use ($actor, $data, $prepared, $publicId, $slug): Tag {
                $tag = Tag::query()->create([
                    'public_id' => $publicId,
                    ...$prepared,
                    'slug' => $slug,
                    'type' => $data->type,
                    'visibility' => $data->visibility,
                    'moderation_status' => $data->moderationStatus,
                    'source' => $data->source,
                ]);
                $this->audit->record(
                    $actor,
                    AdminAuditAction::TagCreated,
                    $tag,
                    AdminAuditRecorder::ABSENT_VERSION,
                    $this->version($tag),
                    ['code', 'slug', 'type', 'visibility', 'moderation_status'],
                );

                return $tag;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
        }

        $this->cache->publicChanged();

        return $tag->refresh();
    }

    public function update(User $actor, Tag $tag, TagData $data, string $expectedVersion): Tag
    {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertManagedState($data, $tag);
        $prepared = $this->prepared($data, $tag);
        try {
            $updated = DB::transaction(function () use ($actor, $tag, $data, $prepared, $expectedVersion): Tag {
                $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);
                $beforeVersion = $this->version($locked);
                $this->assertVersion($expectedVersion, $beforeVersion);

                if ($locked->merged_into_id !== null) {
                    throw ValidationException::withMessages(['tag' => [__('tags.errors.merged_read_only')]]);
                }

                if ($locked->archived_at !== null) {
                    throw ValidationException::withMessages(['tag' => [__('tags.errors.archived_read_only')]]);
                }

                if ($locked->code !== null && $prepared['code'] !== $locked->code) {
                    throw ValidationException::withMessages(['code' => [__('tags.validation.code_immutable')]]);
                }

                $nextSlug = $this->slugs->validated($data->slug, $prepared['name'], (string) $locked->public_id, $locked->id);
                $beforeName = $locked->canonicalName();
                $beforeNameHash = $this->normalizer->hash($beforeName);
                $before = Arr::only($locked->getRawOriginal(), self::VERSION_FIELDS);

                if ($beforeName !== $prepared['name']) {
                    TagAlias::query()
                        ->whereBelongsTo($locked)
                        ->where('normalized_name_hash', $prepared['normalized_name_hash'])
                        ->delete();
                }

                $this->slugs->change($locked, $nextSlug);
                $locked->forceFill([
                    ...$prepared,
                    'type' => $data->type,
                    'visibility' => $data->visibility,
                    'moderation_status' => $data->moderationStatus,
                    'source' => $data->source,
                    'content_version' => $locked->content_version + 1,
                ])->save();

                if ($beforeName !== $prepared['name'] && $beforeNameHash !== $prepared['normalized_name_hash']) {
                    $this->storeAlias($locked, $beforeName, (string) config('tags.default_locale', 'ru'), TagAliasSource::FormerLabel);
                }

                $changed = collect(self::VERSION_FIELDS)
                    ->filter(fn (string $field): bool => ($before[$field] ?? null) !== $locked->getRawOriginal($field))
                    ->intersect(['name', 'slug', 'code', 'type', 'visibility', 'moderation_status', 'source', 'archived_at'])
                    ->values()
                    ->all();
                $this->audit->record(
                    $actor,
                    AdminAuditAction::TagUpdated,
                    $locked,
                    $beforeVersion,
                    $this->version($locked),
                    $changed === [] ? ['name'] : $changed,
                );

                return $locked;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
        }

        $affectedTitleIds = $this->invalidateRecommendationsFor($updated);
        $this->cache->publicChanged($affectedTitleIds);

        return $updated->refresh();
    }

    /** @param array{label: string, short_description?: string|null, description?: string|null, seo_title?: string|null, seo_description?: string|null} $data */
    public function saveTranslation(User $actor, Tag $tag, string $locale, array $data): TagTranslation
    {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertContentEditable($tag);

        if (! in_array($locale, config('tags.supported_locales', []), true)) {
            throw ValidationException::withMessages(['locale' => [__('tags.validation.locale')]]);
        }

        $label = $this->validLabel($data['label']);
        $translation = DB::transaction(function () use ($actor, $tag, $locale, $data, $label): TagTranslation {
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);
            $this->assertContentEditable($locked);
            $beforeVersion = $this->version($locked);
            $translation = TagTranslation::query()->updateOrCreate([
                'tag_id' => $locked->id,
                'locale' => $locale,
            ], [
                'label' => $label,
                'short_description' => $this->plain($data['short_description'] ?? null, 500),
                'description' => $this->plain($data['description'] ?? null, 10_000),
                'seo_title' => $this->plain($data['seo_title'] ?? null, 180),
                'seo_description' => $this->plain($data['seo_description'] ?? null, 320),
            ]);
            $locked->increment('content_version');
            $locked->refresh();
            $this->audit->record(
                $actor,
                AdminAuditAction::TagTranslationUpdated,
                $locked,
                $beforeVersion,
                $this->version($locked),
                ['translations'],
            );

            return $translation;
        }, attempts: 3);
        $this->cache->publicChanged($tag->catalogTitles()->pluck('catalog_titles.id'));

        return $translation->refresh();
    }

    public function addAlias(
        User $actor,
        Tag $tag,
        string $name,
        string $locale = 'und',
        TagAliasSource $source = TagAliasSource::Editorial,
    ): TagAlias {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertContentEditable($tag);
        $beforeVersion = $this->version($tag);
        $result = DB::transaction(function () use ($tag, $name, $locale, $source): array {
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);
            $alias = $this->storeAlias($locked, $name, $locale, $source);

            if (! $alias->wasRecentlyCreated) {
                return ['alias' => $alias, 'changed' => false];
            }

            $locked->increment('content_version');

            return ['alias' => $alias, 'changed' => true];
        }, attempts: 3);

        /** @var TagAlias $alias */
        $alias = $result['alias'];

        if (! $result['changed']) {
            return $alias;
        }

        $tag->refresh();
        $this->audit->record($actor, AdminAuditAction::TagAliasUpdated, $tag, $beforeVersion, $this->version($tag), ['aliases']);
        $this->cache->publicChanged($tag->catalogTitles()->pluck('catalog_titles.id'));

        return $alias;
    }

    public function moderateAlias(
        User $actor,
        Tag $tag,
        TagAlias $alias,
        TagModerationStatus $status,
    ): TagAlias {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertContentEditable($tag);
        abort_unless((int) $alias->tag_id === (int) $tag->id, 404);

        if (! in_array($status, [TagModerationStatus::Approved, TagModerationStatus::Rejected], true)) {
            throw ValidationException::withMessages(['alias' => [__('tags.errors.invalid_alias_status')]]);
        }

        if ($alias->moderation_status === $status) {
            return $alias;
        }

        $beforeVersion = $this->version($tag);
        $result = DB::transaction(function () use ($tag, $alias, $status): array {
            $locked = TagAlias::query()->lockForUpdate()->findOrFail($alias->id);
            abort_unless((int) $locked->tag_id === (int) $tag->id, 404);

            if ($locked->moderation_status === $status) {
                return ['alias' => $locked, 'changed' => false];
            }

            $locked->forceFill(['moderation_status' => $status])->save();
            Tag::query()->whereKey($tag->id)->increment('content_version');

            return ['alias' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var TagAlias $updated */
        $updated = $result['alias'];

        if (! $result['changed']) {
            return $updated;
        }

        $tag->refresh();
        $this->audit->record($actor, AdminAuditAction::TagAliasUpdated, $tag, $beforeVersion, $this->version($tag), ['aliases']);
        $this->cache->publicChanged($tag->catalogTitles()->pluck('catalog_titles.id'));

        return $updated->refresh();
    }

    public function removeAlias(User $actor, Tag $tag, TagAlias $alias): void
    {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertContentEditable($tag);
        abort_unless((int) $alias->tag_id === (int) $tag->id, 404);
        $beforeVersion = $this->version($tag);
        DB::transaction(function () use ($tag, $alias): void {
            if ($alias->moderation_status === TagModerationStatus::Approved && is_string($alias->slug) && $alias->slug !== '') {
                $history = TagSlug::query()->firstOrCreate([
                    'slug' => $alias->slug,
                ], [
                    'tag_id' => $tag->id,
                ]);

                if ((int) $history->tag_id !== (int) $tag->id) {
                    throw ValidationException::withMessages(['alias' => [__('tags.validation.slug_unique')]]);
                }
            }

            $alias->delete();
            $tag->increment('content_version');
        }, attempts: 3);
        $tag->refresh();
        $this->audit->record($actor, AdminAuditAction::TagAliasUpdated, $tag, $beforeVersion, $this->version($tag), ['aliases']);
        $this->cache->publicChanged($tag->catalogTitles()->pluck('catalog_titles.id'));
    }

    public function addSynonym(
        User $actor,
        Tag $tag,
        Tag $related,
        TagSynonymRelationship $relationship = TagSynonymRelationship::RelatedSearch,
        bool $bidirectional = true,
    ): TagSynonym {
        Gate::forUser($actor)->authorize('update', $tag);
        Gate::forUser($actor)->authorize('update', $related);
        $this->assertContentEditable($tag);
        $this->assertContentEditable($related);

        if ($tag->is($related) || $tag->merged_into_id !== null || $related->merged_into_id !== null) {
            throw ValidationException::withMessages(['synonym' => [__('tags.errors.invalid_synonym')]]);
        }

        $beforeVersion = $this->version($tag);
        $result = DB::transaction(function () use ($tag, $related, $relationship, $bidirectional): array {
            Tag::query()
                ->whereIn('id', [$tag->id, $related->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $existing = TagSynonym::query()
                ->where('relationship', $relationship->value)
                ->where('tag_id', $tag->id)
                ->where('related_tag_id', $related->id)
                ->first();

            if ($existing === null && $bidirectional) {
                $existing = TagSynonym::query()
                    ->where('relationship', $relationship->value)
                    ->where('tag_id', $related->id)
                    ->where('related_tag_id', $tag->id)
                    ->first();
            }

            if ($existing !== null) {
                if ($bidirectional && ! $existing->is_bidirectional) {
                    $existing->forceFill(['is_bidirectional' => true])->save();
                    $tag->increment('content_version');
                    $related->increment('content_version');

                    return ['synonym' => $existing, 'changed' => true];
                }

                return ['synonym' => $existing, 'changed' => false];
            }

            $synonym = TagSynonym::query()->create([
                'tag_id' => $tag->id,
                'related_tag_id' => $related->id,
                'relationship' => $relationship,
                'is_bidirectional' => $bidirectional,
                'priority' => 100,
            ]);
            $tag->increment('content_version');
            $related->increment('content_version');

            return ['synonym' => $synonym, 'changed' => true];
        }, attempts: 3);

        /** @var TagSynonym $synonym */
        $synonym = $result['synonym'];

        if (! $result['changed']) {
            return $synonym;
        }

        $tag->refresh();
        $this->audit->record($actor, AdminAuditAction::TagSynonymUpdated, $tag, $beforeVersion, $this->version($tag), ['synonyms']);
        $this->cache->publicChanged();

        return $synonym;
    }

    public function removeSynonym(User $actor, Tag $tag, TagSynonym $synonym): void
    {
        Gate::forUser($actor)->authorize('update', $tag);
        $this->assertContentEditable($tag);
        if ((int) $tag->id !== (int) $synonym->tag_id && (int) $tag->id !== (int) $synonym->related_tag_id) {
            abort(404);
        }
        $beforeVersion = $this->version($tag);
        DB::transaction(function () use ($tag, $synonym): void {
            $relatedId = (int) $synonym->tag_id === (int) $tag->id
                ? (int) $synonym->related_tag_id
                : (int) $synonym->tag_id;
            $synonym->delete();
            $tag->increment('content_version');
            Tag::query()->whereKey($relatedId)->increment('content_version');
        }, attempts: 3);
        $tag->refresh();
        $this->audit->record($actor, AdminAuditAction::TagSynonymUpdated, $tag, $beforeVersion, $this->version($tag), ['synonyms']);
        $this->cache->publicChanged();
    }

    public function moderateProviderMapping(
        User $actor,
        Tag $tag,
        TagProviderMapping $mapping,
        TagProviderMappingStatus $status,
    ): TagProviderMapping {
        Gate::forUser($actor)->authorize('update', $tag);
        abort_unless((int) $mapping->tag_id === (int) $tag->id, 404);

        if ($mapping->status === $status) {
            return $mapping;
        }

        $affectedTitleIds = collect();
        $beforeVersion = $this->mappingVersion($mapping);
        $updated = DB::transaction(function () use ($actor, $tag, $mapping, $status, $affectedTitleIds, $beforeVersion): TagProviderMapping {
            $locked = TagProviderMapping::query()->lockForUpdate()->findOrFail($mapping->id);

            if ((int) $locked->tag_id !== (int) $tag->id || $locked->status === $status) {
                return $locked;
            }

            $locked->forceFill(['status' => $status])->save();

            if ($status === TagProviderMappingStatus::Rejected) {
                $observations = CatalogTitleTagSource::query()
                    ->whereBelongsTo($tag)
                    ->where('provider', $locked->provider)
                    ->where('source_key', $locked->provider_key)
                    ->where('is_current', true)
                    ->get(['id', 'catalog_title_id']);
                $affectedTitleIds->push(...$observations->pluck('catalog_title_id'));

                if ($observations->isNotEmpty()) {
                    CatalogTitleTagSource::query()
                        ->whereKey($observations->modelKeys())
                        ->update(['is_current' => false, 'updated_at' => now()]);
                    $currentTitleIds = CatalogTitleTagSource::query()
                        ->whereBelongsTo($tag)
                        ->whereIn('catalog_title_id', $affectedTitleIds)
                        ->where('is_current', true)
                        ->pluck('catalog_title_id');
                    $detachable = $affectedTitleIds
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()
                        ->diff($currentTitleIds->map(fn (mixed $id): int => (int) $id))
                        ->values();

                    if ($detachable->isNotEmpty()) {
                        DB::table('catalog_title_tag')
                            ->where('tag_id', $tag->id)
                            ->whereIn('catalog_title_id', $detachable)
                            ->delete();
                        CatalogTitleRecommendation::query()
                            ->whereIn('catalog_title_id', $detachable)
                            ->orWhereIn('recommended_title_id', $detachable)
                            ->delete();
                        DB::table('catalog_titles')->whereIn('id', $detachable)->update([
                            'indexed_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $tag->increment('content_version');
            $this->audit->record(
                $actor,
                AdminAuditAction::TagProviderMappingUpdated,
                $locked,
                $beforeVersion,
                $this->mappingVersion($locked),
                ['provider_mapping_status'],
            );

            return $locked;
        }, attempts: 3);
        $this->cache->publicChanged($affectedTitleIds);

        return $updated->refresh();
    }

    public function archive(User $actor, Tag $tag): Tag
    {
        Gate::forUser($actor)->authorize('archive', $tag);
        $result = DB::transaction(function () use ($actor, $tag): array {
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);

            if ($locked->merged_into_id !== null || $locked->archived_at !== null) {
                return ['tag' => $locked, 'changed' => false];
            }

            $beforeVersion = $this->version($locked);
            $locked->forceFill([
                'archived_from_visibility' => $locked->visibility->value,
                'archived_from_moderation_status' => $locked->moderation_status->value,
                'visibility' => TagVisibility::Internal,
                'moderation_status' => TagModerationStatus::Archived,
                'archived_at' => now(),
                'content_version' => $locked->content_version + 1,
            ])->save();
            $this->audit->record($actor, AdminAuditAction::TagArchived, $locked, $beforeVersion, $this->version($locked), [
                'visibility', 'moderation_status', 'archived_at', 'archived_from_visibility', 'archived_from_moderation_status',
            ]);

            return ['tag' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var Tag $archived */
        $archived = $result['tag'];

        if ($result['changed']) {
            $this->cache->publicChanged($this->invalidateRecommendationsFor($archived));
        }

        return $archived->refresh();
    }

    public function restore(User $actor, Tag $tag): Tag
    {
        Gate::forUser($actor)->authorize('restore', $tag);
        $result = DB::transaction(function () use ($actor, $tag): array {
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);

            if ($locked->merged_into_id !== null) {
                throw ValidationException::withMessages(['tag' => [__('tags.errors.merged_read_only')]]);
            }

            if ($locked->archived_at === null) {
                return ['tag' => $locked, 'changed' => false];
            }

            $beforeVersion = $this->version($locked);
            $visibility = TagVisibility::tryFrom((string) $locked->archived_from_visibility)
                ?? ($locked->type === TagType::HiddenInternal ? TagVisibility::Internal : TagVisibility::Public);
            $moderationStatus = TagModerationStatus::tryFrom((string) $locked->archived_from_moderation_status);

            if ($locked->type === TagType::HiddenInternal) {
                $visibility = TagVisibility::Internal;
            }

            if ($moderationStatus === null || in_array($moderationStatus, [TagModerationStatus::Merged, TagModerationStatus::Archived], true)) {
                $moderationStatus = $locked->type === TagType::Imported
                    ? TagModerationStatus::Pending
                    : TagModerationStatus::Approved;
            }

            $locked->forceFill([
                'visibility' => $visibility,
                'moderation_status' => $moderationStatus,
                'archived_at' => null,
                'archived_from_visibility' => null,
                'archived_from_moderation_status' => null,
                'content_version' => $locked->content_version + 1,
            ])->save();
            $this->audit->record($actor, AdminAuditAction::TagRestored, $locked, $beforeVersion, $this->version($locked), [
                'visibility', 'moderation_status', 'archived_at', 'archived_from_visibility', 'archived_from_moderation_status',
            ]);

            return ['tag' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var Tag $restored */
        $restored = $result['tag'];

        if ($result['changed']) {
            $this->cache->publicChanged($this->invalidateRecommendationsFor($restored));
        }

        return $restored->refresh();
    }

    public function merge(User $actor, Tag $source, Tag $target): Tag
    {
        Gate::forUser($actor)->authorize('merge', $source);
        Gate::forUser($actor)->authorize('merge', $target);

        if ($source->is($target)) {
            throw ValidationException::withMessages(['target' => [__('tags.errors.merge_same')]]);
        }

        if ((int) $source->merged_into_id === (int) $target->id) {
            return $target;
        }

        $affectedTitleIds = collect();
        $merged = DB::transaction(function () use ($actor, $source, $target, $affectedTitleIds): Tag {
            $locked = Tag::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($source->id);
            $target = $locked->get($target->id);

            if (! $source instanceof Tag
                || ! $target instanceof Tag
                || $target->merged_into_id !== null
                || $target->archived_at !== null) {
                throw ValidationException::withMessages(['target' => [__('tags.errors.invalid_merge_target')]]);
            }

            if ((int) $source->merged_into_id === (int) $target->id) {
                return $target;
            }

            if ($source->merged_into_id !== null) {
                throw ValidationException::withMessages(['source' => [__('tags.errors.already_merged')]]);
            }

            $sourceVersion = $this->version($source);
            $targetVersion = $this->version($target);
            $affectedTitleIds->push(...DB::table('catalog_title_tag')->where('tag_id', $source->id)->pluck('catalog_title_id'));
            $this->moveAssignments($source, $target);
            $this->moveTranslations($source, $target);
            $this->moveAliases($source, $target);
            $this->moveSlugs($source, $target);
            $this->moveSynonyms($source, $target);
            TagProviderMapping::query()->whereBelongsTo($source)->update(['tag_id' => $target->id, 'updated_at' => now()]);
            $this->sourceIdentities->rebind('tag', [(string) $source->slug], (string) $target->slug);
            $snapshot = [
                'source' => $source->only(self::VERSION_FIELDS),
                'target' => $target->only(self::VERSION_FIELDS),
                'affected_title_ids' => $affectedTitleIds->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            ];
            $source->forceFill([
                'visibility' => TagVisibility::Internal,
                'moderation_status' => TagModerationStatus::Merged,
                'merged_into_id' => $target->id,
                'archived_at' => now(),
                'content_version' => $source->content_version + 1,
            ])->save();
            $target->increment('content_version');
            $target->refresh();
            TagMergeEvent::query()->create([
                'source_tag_id' => $source->id,
                'target_tag_id' => $target->id,
                'actor_id' => $actor->id,
                'snapshot' => $snapshot,
                'occurred_at' => now(),
            ]);
            $this->audit->record($actor, AdminAuditAction::TagMerged, $source, $sourceVersion, $this->version($source), ['visibility', 'moderation_status', 'merged_into_id', 'archived_at']);
            $this->audit->record($actor, AdminAuditAction::TagUpdated, $target, $targetVersion, $this->version($target), ['aliases', 'synonyms', 'translations']);
            $ids = $affectedTitleIds->map(fn (mixed $id): int => (int) $id)->unique()->values();
            CatalogTitleRecommendation::query()
                ->whereIn('catalog_title_id', $ids)
                ->orWhereIn('recommended_title_id', $ids)
                ->delete();

            return $target;
        }, attempts: 3);
        $this->cache->publicChanged($affectedTitleIds);

        return $merged->refresh();
    }

    public function version(Tag $tag): string
    {
        return hash('sha256', json_encode(Arr::only($tag->getRawOriginal(), self::VERSION_FIELDS), JSON_THROW_ON_ERROR));
    }

    private function mappingVersion(TagProviderMapping $mapping): string
    {
        return hash('sha256', json_encode(Arr::only($mapping->getRawOriginal(), [
            'provider', 'provider_key', 'tag_id', 'status', 'updated_at',
        ]), JSON_THROW_ON_ERROR));
    }

    /** @return array{name: string, code: string|null, normalized_name: string, normalized_name_hash: string} */
    private function prepared(TagData $data, ?Tag $except = null): array
    {
        if ($data->type === TagType::HiddenInternal && $data->visibility !== TagVisibility::Internal) {
            throw ValidationException::withMessages(['visibility' => [__('tags.validation.internal_visibility')]]);
        }

        $name = $this->validLabel($data->name);
        $normalized = $this->normalizer->comparison($name);
        $hash = hash('sha256', $normalized);
        $duplicate = Tag::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where('normalized_name_hash', $hash)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
        }

        $aliasConflict = TagAlias::query()
            ->where('normalized_name_hash', $hash)
            ->when($except !== null, fn ($query) => $query->where('tag_id', '!=', $except->id))
            ->exists();

        if ($aliasConflict) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.alias_conflict')]]);
        }

        $code = $data->code === null || trim($data->code) === '' ? null : Str::lower(trim($data->code));

        if ($code !== null && preg_match('/^[a-z][a-z0-9._-]{1,119}$/D', $code) !== 1) {
            throw ValidationException::withMessages(['code' => [__('tags.validation.code')]]);
        }

        if ($data->type === TagType::Imported && $code !== null) {
            throw ValidationException::withMessages(['code' => [__('tags.validation.imported_code')]]);
        }

        return [
            'name' => $name,
            'code' => $code,
            'normalized_name' => $normalized,
            'normalized_name_hash' => $hash,
        ];
    }

    private function assertCreatableState(TagData $data): void
    {
        if ($data->type === TagType::Imported) {
            throw ValidationException::withMessages(['type' => [__('tags.validation.imported_creation')]]);
        }

        if (in_array($data->moderationStatus, [TagModerationStatus::Merged, TagModerationStatus::Archived], true)) {
            throw ValidationException::withMessages(['moderation_status' => [__('tags.validation.lifecycle_status')]]);
        }
    }

    private function assertManagedState(TagData $data, Tag $tag): void
    {
        if ($tag->archived_at !== null) {
            throw ValidationException::withMessages(['tag' => [__('tags.errors.archived_read_only')]]);
        }

        if (in_array($data->moderationStatus, [TagModerationStatus::Merged, TagModerationStatus::Archived], true)) {
            throw ValidationException::withMessages(['moderation_status' => [__('tags.validation.lifecycle_status')]]);
        }
    }

    private function assertContentEditable(Tag $tag): void
    {
        $current = Tag::query()->find($tag->id);
        $editable = $current instanceof Tag
            && $current->merged_into_id === null
            && $current->archived_at === null;

        if (! $editable) {
            throw ValidationException::withMessages(['tag' => [
                $current?->merged_into_id !== null
                    ? __('tags.errors.merged_read_only')
                    : __('tags.errors.archived_read_only'),
            ]]);
        }
    }

    private function validLabel(mixed $value): string
    {
        if ($this->normalizer->containsUnsafeInput($value)) {
            throw ValidationException::withMessages(['name' => [__('tags.validation.name', [
                'min' => config('tags.label_min_length', 2),
                'max' => config('tags.label_max_length', 80),
            ])]]);
        }

        $name = $this->normalizer->display($value);
        $minimum = max(1, (int) config('tags.label_min_length', 2));
        $maximum = max($minimum, (int) config('tags.label_max_length', 80));

        if (mb_strlen($name) < $minimum || mb_strlen($name) > $maximum || ! $this->normalizer->hasMeaningfulContent($name)) {
            throw ValidationException::withMessages(['name' => [__('tags.validation.name', ['min' => $minimum, 'max' => $maximum])]]);
        }

        return $name;
    }

    private function plain(mixed $value, int $maximum): ?string
    {
        $value = PlainText::clean($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximum) {
            throw ValidationException::withMessages(['description' => [__('tags.validation.description', ['max' => $maximum])]]);
        }

        return $value;
    }

    private function assertVersion(string $expected, string $actual): void
    {
        if ($expected === '' || ! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages(['form' => [__('tags.errors.stale_edit')]]);
        }
    }

    private function storeAlias(Tag $tag, string $name, string $locale, TagAliasSource $source): TagAlias
    {
        $name = $this->validLabel($name);
        $locale = in_array($locale, config('tags.supported_locales', []), true) ? $locale : 'und';
        $normalized = $this->normalizer->comparison($name);
        $hash = hash('sha256', $normalized);

        if ($hash === $tag->normalized_name_hash) {
            throw ValidationException::withMessages(['alias' => [__('tags.errors.alias_is_canonical')]]);
        }

        $conflictingTag = Tag::query()->whereKeyNot($tag->id)->where('normalized_name_hash', $hash)->exists();
        $conflictingAlias = TagAlias::query()
            ->where('locale', $locale)
            ->where('normalized_name_hash', $hash)
            ->where('tag_id', '!=', $tag->id)
            ->exists();

        if ($conflictingTag || $conflictingAlias) {
            throw ValidationException::withMessages(['alias' => [__('tags.errors.alias_conflict')]]);
        }

        $slug = Str::slug($name);
        $slug = $slug !== '' && $slug !== $tag->slug
            && ! Tag::query()->where('slug', $slug)->exists()
            && ! TagSlug::query()->where('slug', $slug)->exists()
            && ! TagAlias::query()->where('slug', $slug)->exists()
                ? $slug
                : null;

        return TagAlias::query()->firstOrCreate([
            'locale' => $locale,
            'normalized_name_hash' => $hash,
        ], [
            'tag_id' => $tag->id,
            'name' => $name,
            'normalized_name' => $normalized,
            'slug' => $slug,
            'source' => $source,
            'moderation_status' => TagModerationStatus::Approved,
        ]);
    }

    private function moveAssignments(Tag $source, Tag $target): void
    {
        DB::table('catalog_title_tag')
            ->where('tag_id', $source->id)
            ->orderBy('catalog_title_id')
            ->chunk(500, function ($rows) use ($target): void {
                DB::table('catalog_title_tag')->insertOrIgnore($rows->map(fn (object $row): array => [
                    'catalog_title_id' => $row->catalog_title_id,
                    'tag_id' => $target->id,
                ])->all());
            });
        DB::table('catalog_title_tag')->where('tag_id', $source->id)->delete();

        CatalogTitleTagSource::query()->whereBelongsTo($source)->orderBy('id')->chunkById(250, function ($rows) use ($target): void {
            foreach ($rows as $row) {
                $existing = CatalogTitleTagSource::query()->firstOrNew([
                    'catalog_title_id' => $row->catalog_title_id,
                    'tag_id' => $target->id,
                    'source' => $row->source->value,
                    'source_key' => $row->source_key,
                ]);
                $existing->fill([
                    'provider' => $row->provider,
                    'source_id' => $row->source_id,
                    'is_current' => $existing->exists ? ($existing->is_current || $row->is_current) : $row->is_current,
                    'first_seen_at' => $existing->exists && $existing->first_seen_at->lt($row->first_seen_at) ? $existing->first_seen_at : $row->first_seen_at,
                    'last_seen_at' => $existing->exists && $existing->last_seen_at->gt($row->last_seen_at) ? $existing->last_seen_at : $row->last_seen_at,
                ])->save();
                $row->delete();
            }
        });
    }

    private function moveTranslations(Tag $source, Tag $target): void
    {
        TagTranslation::query()->whereBelongsTo($source)->orderBy('id')->get()->each(function (TagTranslation $translation) use ($source, $target): void {
            $existing = TagTranslation::query()->whereBelongsTo($target)->where('locale', $translation->locale)->first();

            if ($existing === null) {
                $translation->tag_id = $target->id;
                $translation->save();

                return;
            }

            if ($this->normalizer->hash($existing->label) !== $this->normalizer->hash($translation->label)) {
                $this->preserveMergeAlias($source, $target, $translation->label, $translation->locale);
            }

            $updates = collect(['short_description', 'description', 'seo_title', 'seo_description'])
                ->filter(fn (string $field): bool => blank($existing->{$field}) && filled($translation->{$field}))
                ->mapWithKeys(fn (string $field): array => [$field => $translation->{$field}])
                ->all();

            if ($updates !== []) {
                $existing->forceFill($updates)->save();
            }
        });
    }

    private function moveAliases(Tag $source, Tag $target): void
    {
        if ($this->normalizer->hash($source->canonicalName()) !== $target->normalized_name_hash) {
            $this->preserveMergeAlias(
                $source,
                $target,
                $source->canonicalName(),
                (string) config('tags.default_locale', 'ru'),
            );
        }

        TagAlias::query()->whereBelongsTo($source)->orderBy('id')->get()->each(function (TagAlias $alias) use ($target): void {
            $existing = TagAlias::query()
                ->where('locale', $alias->locale)
                ->where('normalized_name_hash', $alias->normalized_name_hash)
                ->whereKeyNot($alias->id)
                ->first();

            if ($existing === null) {
                $alias->tag_id = $target->id;
                $alias->save();
            } elseif ((int) $existing->tag_id === (int) $target->id) {
                $alias->delete();
            }
        });
    }

    private function moveSlugs(Tag $source, Tag $target): void
    {
        TagSlug::query()->whereBelongsTo($source)->orderBy('id')->get()->each(function (TagSlug $slug) use ($target): void {
            $existing = TagSlug::query()->where('slug', $slug->slug)->whereKeyNot($slug->id)->first();

            if ($existing === null) {
                $slug->tag_id = $target->id;
                $slug->save();
            } elseif ((int) $existing->tag_id === (int) $target->id) {
                $slug->delete();
            }
        });
    }

    private function preserveMergeAlias(Tag $source, Tag $target, string $name, string $locale): void
    {
        $name = $this->validLabel($name);
        $locale = in_array($locale, config('tags.supported_locales', []), true) ? $locale : 'und';
        $normalized = $this->normalizer->comparison($name);
        $hash = hash('sha256', $normalized);

        if ($hash === $target->normalized_name_hash) {
            return;
        }

        $conflictingTag = Tag::query()
            ->whereNotIn('id', [$source->id, $target->id])
            ->where('normalized_name_hash', $hash)
            ->exists();

        if ($conflictingTag) {
            return;
        }

        $alias = TagAlias::query()
            ->where('locale', $locale)
            ->where('normalized_name_hash', $hash)
            ->first();

        if ($alias !== null) {
            if (in_array((int) $alias->tag_id, [(int) $source->id, (int) $target->id], true)) {
                $alias->forceFill([
                    'tag_id' => $target->id,
                    'moderation_status' => TagModerationStatus::Approved,
                ])->save();
            }

            return;
        }

        TagAlias::query()->create([
            'tag_id' => $target->id,
            'locale' => $locale,
            'name' => $name,
            'normalized_name' => $normalized,
            'normalized_name_hash' => $hash,
            'slug' => null,
            'source' => TagAliasSource::FormerLabel,
            'moderation_status' => TagModerationStatus::Approved,
        ]);
    }

    private function moveSynonyms(Tag $source, Tag $target): void
    {
        TagSynonym::query()
            ->where('tag_id', $source->id)
            ->orWhere('related_tag_id', $source->id)
            ->orderBy('id')
            ->get()
            ->each(function (TagSynonym $synonym) use ($source, $target): void {
                $tagId = (int) $synonym->tag_id === (int) $source->id ? (int) $target->id : (int) $synonym->tag_id;
                $relatedId = (int) $synonym->related_tag_id === (int) $source->id ? (int) $target->id : (int) $synonym->related_tag_id;

                if ($tagId !== $relatedId) {
                    $existing = TagSynonym::query()
                        ->where('relationship', $synonym->relationship->value)
                        ->where(function ($query) use ($tagId, $relatedId, $synonym): void {
                            $query->where(fn ($query) => $query
                                ->where('tag_id', $tagId)
                                ->where('related_tag_id', $relatedId));

                            if ($synonym->is_bidirectional) {
                                $query->orWhere(fn ($query) => $query
                                    ->where('tag_id', $relatedId)
                                    ->where('related_tag_id', $tagId));
                            }
                        })
                        ->whereKeyNot($synonym->id)
                        ->first();

                    if ($existing === null) {
                        TagSynonym::query()->create([
                            'tag_id' => $tagId,
                            'related_tag_id' => $relatedId,
                            'relationship' => $synonym->relationship->value,
                            'is_bidirectional' => $synonym->is_bidirectional,
                            'priority' => $synonym->priority,
                        ]);
                    } else {
                        $existing->forceFill([
                            'is_bidirectional' => $existing->is_bidirectional || $synonym->is_bidirectional,
                            'priority' => min($existing->priority, $synonym->priority),
                        ])->save();
                    }
                }

                $synonym->delete();
            });
    }

    /** @return Collection<int, int> */
    private function invalidateRecommendationsFor(Tag $tag): Collection
    {
        $titleIds = $tag->catalogTitles()
            ->pluck('catalog_titles.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($titleIds->isNotEmpty()) {
            CatalogTitleRecommendation::query()
                ->whereIn('catalog_title_id', $titleIds)
                ->orWhereIn('recommended_title_id', $titleIds)
                ->delete();
        }

        return $titleIds;
    }
}
