<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionTranslation;
use App\Models\User;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionService
{
    public function __construct(
        private readonly CatalogCollectionSlugService $slugs,
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly CatalogCollectionRateLimiter $rateLimiter,
        private readonly CatalogCollectionSchema $schema,
    ) {}

    public function create(User $owner, CatalogCollectionData $data): CatalogCollection
    {
        abort_unless($this->schema->available(), 503, __('collections.errors.generic'));
        Gate::forUser($owner)->authorize('create', CatalogCollection::class);

        abort_if($data->type === CatalogCollectionType::System, 403);

        if ($data->type === CatalogCollectionType::Editorial) {
            Gate::forUser($owner)->authorize('createEditorial', CatalogCollection::class);
        }

        $name = $this->validName($data->name);
        $description = $this->validDescription($data->description);
        $seoTitle = $this->validSeoTitle($data->seoTitle);
        $seoDescription = $this->validSeoDescription($data->seoDescription);
        $contentLocale = $data->type === CatalogCollectionType::Editorial
            ? ($this->locale($data->contentLocale) ?? (string) config('catalog-collections.default_locale', 'ru'))
            : $this->locale($data->contentLocale);
        $publicId = $data->publicId !== null && Str::isUuid($data->publicId)
            ? Str::lower($data->publicId)
            : (string) Str::uuid();
        $existing = CatalogCollection::query()->where('public_id', $publicId)->first();

        if ($existing !== null) {
            abort_unless($existing->isOwnedBy($owner), 404);

            return $existing;
        }

        $this->rateLimiter->ensure($owner, 'create', 'name');

        $collection = DB::transaction(function () use ($owner, $data, $name, $description, $seoTitle, $seoDescription, $contentLocale, $publicId): CatalogCollection {
            User::query()->lockForUpdate()->findOrFail($owner->id);
            $existing = CatalogCollection::query()->where('public_id', $publicId)->first();

            if ($existing !== null) {
                abort_unless($existing->isOwnedBy($owner), 404);

                return $existing;
            }

            if (CatalogCollection::query()->where('owner_id', $owner->id)->count()
                >= max(1, (int) config('catalog-collections.maximum_collections_per_user', 100))) {
                throw ValidationException::withMessages(['collection' => [__('collections.errors.collection_limit')]]);
            }

            $moderation = $this->initialModeration($data, $owner);

            $collection = CatalogCollection::query()->firstOrCreate([
                'public_id' => $publicId,
            ], [
                'owner_id' => $owner->id,
                'name' => $name,
                'description' => $description,
                'slug' => $this->slugs->generate($name, $publicId),
                'type' => $data->type,
                'visibility' => $data->visibility,
                'moderation_status' => $moderation,
                'sort_mode' => $data->sortMode,
                'content_locale' => $contentLocale,
                'is_featured' => false,
                'published_at' => $moderation === CatalogCollectionModerationStatus::Approved
                    && $data->visibility === CatalogCollectionVisibility::Public ? now() : null,
            ]);

            abort_unless($collection->isOwnedBy($owner), 404);

            if ($collection->wasRecentlyCreated && $data->type === CatalogCollectionType::Editorial) {
                $collection->translations()->create([
                    'locale' => $contentLocale,
                    'name' => $name,
                    'description' => $description,
                    'seo_title' => $seoTitle,
                    'seo_description' => $seoDescription,
                ]);
            }

            return $collection;
        }, attempts: 3);

        $this->cache->changed($collection);

        return $collection;
    }

    public function update(User $actor, CatalogCollection $collection, CatalogCollectionData $data, ?int $expectedVersion = null): CatalogCollection
    {
        Gate::forUser($actor)->authorize('update', $collection);
        $this->rateLimiter->ensure($actor, 'mutate', 'name');

        $name = $this->validName($data->name);
        $description = $this->validDescription($data->description);
        $seoTitle = $this->validSeoTitle($data->seoTitle);
        $seoDescription = $this->validSeoDescription($data->seoDescription);

        $result = DB::transaction(function () use ($actor, $collection, $data, $expectedVersion, $name, $description, $seoTitle, $seoDescription): array {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('update', $locked);

            $locale = $this->locale($data->contentLocale)
                ?? (string) config('catalog-collections.default_locale', 'ru');
            $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
            $isEditorialTranslation = $locked->type === CatalogCollectionType::Editorial;
            $translation = $isEditorialTranslation
                ? CatalogCollectionTranslation::query()
                    ->whereBelongsTo($locked, 'collection')
                    ->where('locale', $locale)
                    ->lockForUpdate()
                    ->first()
                : null;
            $translationChanged = $isEditorialTranslation && (
                ! $translation instanceof CatalogCollectionTranslation
                || $translation->name !== $name
                || $translation->description !== $description
                || $translation->seo_title !== $seoTitle
                || $translation->seo_description !== $seoDescription
            );
            $updatesBaseContent = ! $isEditorialTranslation || $locale === $defaultLocale;
            $baseContentChanged = $updatesBaseContent
                && ($locked->name !== $name || $locked->description !== $description);
            $visibilityChanged = $locked->visibility !== $data->visibility;
            $sortChanged = $locked->sort_mode !== $data->sortMode;
            $nextContentLocale = $isEditorialTranslation ? $locked->content_locale : $this->locale($data->contentLocale);
            $contentLocaleChanged = $locked->content_locale !== $nextContentLocale;
            $publicContentChanged = $baseContentChanged
                || $translationChanged
                || $visibilityChanged;
            $nextModeration = $locked->moderation_status;
            $nextFeatured = $locked->is_featured;

            if ($locked->type === CatalogCollectionType::User && $publicContentChanged) {
                $nextModeration = $data->visibility === CatalogCollectionVisibility::Private
                    ? CatalogCollectionModerationStatus::Approved
                    : CatalogCollectionModerationStatus::Pending;
                $nextFeatured = false;
            }

            if ($data->visibility !== CatalogCollectionVisibility::Public) {
                $nextFeatured = false;
            }

            $shouldBePublished = $nextModeration === CatalogCollectionModerationStatus::Approved
                && $data->visibility === CatalogCollectionVisibility::Public;
            $nextPublishedAt = $shouldBePublished ? ($locked->published_at ?? now()) : null;
            $lifecycleChanged = $locked->moderation_status !== $nextModeration
                || $locked->is_featured !== $nextFeatured
                || (($locked->published_at === null) !== ($nextPublishedAt === null));
            $contentChanged = $baseContentChanged
                || $translationChanged
                || $visibilityChanged
                || $sortChanged
                || $contentLocaleChanged;

            if (! $contentChanged && ! $lifecycleChanged) {
                return ['collection' => $locked, 'changed' => false];
            }

            if ($expectedVersion !== null && $locked->content_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'form' => [__('collections.errors.stale_edit')],
                ]);
            }

            if ($updatesBaseContent && $baseContentChanged && $locked->name !== $name) {
                $this->slugs->change($locked, $name);
            }

            $locked->fill([
                'name' => $updatesBaseContent ? $name : $locked->name,
                'description' => $updatesBaseContent ? $description : $locked->description,
                'visibility' => $data->visibility,
                'sort_mode' => $data->sortMode,
                'content_locale' => $nextContentLocale,
                'moderation_status' => $nextModeration,
                'is_featured' => $nextFeatured,
                'published_at' => $nextPublishedAt,
                'content_version' => $locked->content_version + 1,
            ]);

            if ($translationChanged) {
                CatalogCollectionTranslation::query()->updateOrCreate([
                    'catalog_collection_id' => $locked->id,
                    'locale' => $locale,
                ], [
                    'name' => $name,
                    'description' => $description,
                    'seo_title' => $seoTitle,
                    'seo_description' => $seoDescription,
                ]);
            }

            $locked->save();

            return ['collection' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollection $updated */
        $updated = $result['collection'];

        if ($result['changed']) {
            $this->cache->changed($updated);
        }

        return $updated->refresh();
    }

    public function delete(User $actor, CatalogCollection $collection): void
    {
        Gate::forUser($actor)->authorize('delete', $collection);

        DB::transaction(function () use ($collection): void {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            $locked->is_featured = false;
            $locked->content_version++;
            $locked->save();
            $locked->delete();
        }, attempts: 3);

        $this->cache->changed($collection);
    }

    public function restore(User $actor, CatalogCollection $collection): CatalogCollection
    {
        Gate::forUser($actor)->authorize('restore', $collection);
        $days = max(1, (int) config('catalog-collections.restoration_days', 30));

        $result = DB::transaction(function () use ($actor, $collection, $days): array {
            $locked = CatalogCollection::query()->withTrashed()->lockForUpdate()->findOrFail($collection->id);

            if (! $locked->trashed()) {
                return ['collection' => $locked, 'changed' => false];
            }

            Gate::forUser($actor)->authorize('restore', $locked);

            if ($locked->deleted_at === null || $locked->deleted_at->lte(now()->subDays($days))) {
                throw ValidationException::withMessages([
                    'collection' => [__('collections.errors.restore_expired')],
                ]);
            }

            $locked->restore();
            $locked->forceFill([
                'visibility' => CatalogCollectionVisibility::Private,
                'moderation_status' => CatalogCollectionModerationStatus::Approved,
                'is_featured' => false,
                'published_at' => null,
                'content_version' => $locked->content_version + 1,
            ])->save();

            return ['collection' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollection $collection */
        $collection = $result['collection'];

        if ($result['changed']) {
            $this->cache->changed($collection);
        }

        return $collection->refresh();
    }

    public function forceDelete(User $actor, CatalogCollection $collection, CatalogCollectionCoverService $covers): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $collection);
        abort_unless($collection->trashed(), 404);
        $covers->deleteWithCollection($collection);
    }

    private function initialModeration(CatalogCollectionData $data, User $owner): CatalogCollectionModerationStatus
    {
        if ($data->type !== CatalogCollectionType::User || Gate::forUser($owner)->allows('manage-catalog')) {
            return CatalogCollectionModerationStatus::Approved;
        }

        return $data->visibility === CatalogCollectionVisibility::Private
            ? CatalogCollectionModerationStatus::Approved
            : CatalogCollectionModerationStatus::Pending;
    }

    private function validName(string $value): string
    {
        $value = UserPlainText::name($value);

        if (mb_strlen($value) < 2 || mb_strlen($value) > (int) config('catalog-collections.name_max_length', 160)) {
            throw ValidationException::withMessages(['name' => [__('collections.validation.name')]]);
        }

        return $value;
    }

    private function validDescription(?string $value): ?string
    {
        $value = UserPlainText::description($value);

        if ($value !== null && mb_strlen($value) > (int) config('catalog-collections.description_max_length', 10_000)) {
            throw ValidationException::withMessages(['description' => [__('collections.validation.description')]]);
        }

        return $value;
    }

    private function locale(?string $locale): ?string
    {
        return in_array($locale, config('catalog-collections.supported_locales', []), true) ? $locale : null;
    }

    private function validSeoTitle(?string $value): ?string
    {
        $value = UserPlainText::name($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > (int) config('catalog-collections.seo_title_max_length', 180)) {
            throw ValidationException::withMessages(['seoTitle' => [__('collections.validation.seo_title')]]);
        }

        return $value;
    }

    private function validSeoDescription(?string $value): ?string
    {
        $value = UserPlainText::description($value);

        if ($value !== null && mb_strlen($value) > (int) config('catalog-collections.seo_description_max_length', 500)) {
            throw ValidationException::withMessages(['seoDescription' => [__('collections.validation.seo_description')]]);
        }

        return $value;
    }
}
