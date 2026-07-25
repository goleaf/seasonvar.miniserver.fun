<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionClassificationResult;
use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionCategoryTranslation;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Support\UserPlainText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionCategoryService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function resolveAssignment(
        ?string $publicId,
        CatalogCollectionVisibility $visibility,
        bool $lockForUpdate = false,
    ): ?CatalogCollectionCategory {
        $publicId = is_string($publicId) ? Str::lower(trim($publicId)) : null;

        if ($publicId === null || $publicId === '') {
            if ($visibility !== CatalogCollectionVisibility::Private) {
                $this->invalid();
            }

            return null;
        }

        if (! Str::isUuid($publicId)) {
            $this->invalid();
        }

        $query = CatalogCollectionCategory::query()
            ->with('parent:id,is_active')
            ->where('public_id', $publicId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $category = $query->first();

        if (! $category instanceof CatalogCollectionCategory
            || ! $category->is_active
            || ($category->parent_id !== null && ! $category->parent?->is_active)) {
            $this->invalid();
        }

        return $category;
    }

    /**
     * @param  array{ru?: string, en?: string}  $translations
     */
    public function createCategory(
        User $actor,
        ?string $parentPublicId,
        string $slug,
        array $translations,
    ): CatalogCollectionCategory {
        $this->authorizeManage($actor);
        $slug = Str::slug(Str::lower(trim($slug)));
        $translations = $this->validTranslations($translations);

        if (mb_strlen($slug) < 2 || mb_strlen($slug) > 120
            || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/D', $slug) !== 1) {
            $this->validation('slug', __('collections.categories.validation_slug'));
        }

        $category = DB::transaction(function () use ($actor, $parentPublicId, $slug, $translations): CatalogCollectionCategory {
            $parent = $this->resolveParent($parentPublicId, lockForUpdate: true);
            $positionQuery = CatalogCollectionCategory::query();
            $parent instanceof CatalogCollectionCategory
                ? $positionQuery->where('parent_id', $parent->id)
                : $positionQuery->whereNull('parent_id');
            $position = ((int) $positionQuery->max('position')) + 1;

            try {
                $category = CatalogCollectionCategory::query()->create([
                    'parent_id' => $parent?->id,
                    'slug' => $slug,
                    'position' => $position,
                    'is_active' => true,
                ]);
            } catch (QueryException) {
                $this->validation('slug', __('collections.categories.validation_slug'));
            }

            $category->translations()->createMany([
                ['locale' => 'ru', 'name' => $translations['ru']],
                ['locale' => 'en', 'name' => $translations['en']],
            ]);
            $category->load('translations:id,catalog_collection_category_id,locale,name');
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionCategoryCreated,
                $category,
                AdminAuditRecorder::ABSENT_VERSION,
                $this->fingerprint($category),
                ['parent', 'slug', 'position', 'is_active', 'translations'],
            );

            return $category;
        }, attempts: 3);

        $this->cache->changed();

        return $category->refresh()->load([
            'parent:id,public_id,parent_id,slug,position,is_active',
            'translations:id,catalog_collection_category_id,locale,name',
        ]);
    }

    /**
     * @param  array{ru?: string, en?: string}  $translations
     */
    public function updateCategory(
        User $actor,
        CatalogCollectionCategory $category,
        array $translations,
        bool $active,
    ): CatalogCollectionCategory {
        $this->authorizeManage($actor);
        $translations = $this->validTranslations($translations);

        $result = DB::transaction(function () use ($actor, $category, $translations, $active): array {
            $locked = CatalogCollectionCategory::query()
                ->with([
                    'parent:id,public_id,parent_id,slug,position,is_active',
                    'children:id,public_id,parent_id,slug,position,is_active',
                    'translations:id,catalog_collection_category_id,locale,name',
                ])
                ->lockForUpdate()
                ->findOrFail($category->id);

            if ($active && $locked->parent instanceof CatalogCollectionCategory && ! $locked->parent->is_active) {
                $this->validation('isActive', __('collections.categories.validation_active_parent'));
            }

            if (! $active && $locked->parent_id === null && $locked->children->contains('is_active', true)) {
                $this->validation('isActive', __('collections.categories.validation_active_children'));
            }

            $before = $this->fingerprint($locked);
            $changed = $locked->is_active !== $active;

            foreach ($translations as $locale => $name) {
                $translation = $locked->translations->firstWhere('locale', $locale);
                $changed = $changed
                    || ! $translation instanceof CatalogCollectionCategoryTranslation
                    || $translation->name !== $name;
            }

            if (! $changed) {
                return ['category' => $locked, 'changed' => false];
            }

            $locked->forceFill(['is_active' => $active])->save();

            foreach ($translations as $locale => $name) {
                $locked->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['name' => $name],
                );
            }

            $locked->load('translations:id,catalog_collection_category_id,locale,name');
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionCategoryUpdated,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['is_active', 'translations'],
            );

            return ['category' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollectionCategory $updated */
        $updated = $result['category'];

        if ($result['changed']) {
            $this->cache->changed();
        }

        return $updated->refresh()->load([
            'parent:id,public_id,parent_id,slug,position,is_active',
            'translations:id,catalog_collection_category_id,locale,name',
        ]);
    }

    public function moveCategory(
        User $actor,
        CatalogCollectionCategory $category,
        int $direction,
    ): CatalogCollectionCategory {
        $this->authorizeManage($actor);

        if (! in_array($direction, [-1, 1], true)) {
            $this->validation('position', __('collections.categories.validation_position'));
        }

        $result = DB::transaction(function () use ($actor, $category, $direction): array {
            $locked = CatalogCollectionCategory::query()->lockForUpdate()->findOrFail($category->id);
            $siblingQuery = CatalogCollectionCategory::query()->lockForUpdate();
            $locked->parent_id === null
                ? $siblingQuery->whereNull('parent_id')
                : $siblingQuery->where('parent_id', $locked->parent_id);

            $sibling = $direction < 0
                ? $siblingQuery->where('position', '<', $locked->position)->orderByDesc('position')->orderByDesc('id')->first()
                : $siblingQuery->where('position', '>', $locked->position)->orderBy('position')->orderBy('id')->first();

            if (! $sibling instanceof CatalogCollectionCategory) {
                return ['category' => $locked, 'changed' => false];
            }

            $before = $this->fingerprint($locked);
            $position = $locked->position;
            $locked->forceFill(['position' => $sibling->position])->save();
            $sibling->forceFill(['position' => $position])->save();
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionCategoryMoved,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['position'],
            );

            return ['category' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollectionCategory $moved */
        $moved = $result['category'];

        if ($result['changed']) {
            $this->cache->changed();
        }

        return $moved->refresh()->load([
            'parent:id,public_id,parent_id,slug,position,is_active',
            'translations:id,catalog_collection_category_id,locale,name',
        ]);
    }

    /**
     * @param  list<string>  $collectionPublicIds
     */
    public function bulkAssign(
        User $actor,
        array $collectionPublicIds,
        string $categoryPublicId,
    ): int {
        $this->authorizeManage($actor);
        $normalizedIds = array_map(
            static fn (string $publicId): string => Str::lower(trim($publicId)),
            $collectionPublicIds,
        );

        if ($normalizedIds === [] || count($normalizedIds) > 100
            || count(array_unique($normalizedIds)) !== count($normalizedIds)
            || collect($normalizedIds)->contains(fn (string $publicId): bool => ! Str::isUuid($publicId))) {
            $this->validation('selectedCollectionPublicIds', __('collections.categories.validation_bulk'));
        }

        $changed = DB::transaction(function () use ($actor, $normalizedIds, $categoryPublicId) {
            $category = $this->resolveAssignment(
                $categoryPublicId,
                CatalogCollectionVisibility::Public,
                lockForUpdate: true,
            );
            $collections = CatalogCollection::query()
                ->whereIn('public_id', $normalizedIds)
                ->lockForUpdate()
                ->get();

            if ($collections->count() !== count($normalizedIds)) {
                $this->validation('selectedCollectionPublicIds', __('collections.categories.validation_bulk'));
            }

            $changed = $collections
                ->filter(fn (CatalogCollection $collection): bool => $collection->catalog_collection_category_id === null);

            foreach ($changed as $collection) {
                $collection->forceFill([
                    'catalog_collection_category_id' => $category?->id,
                    'content_version' => $collection->content_version + 1,
                ])->save();
            }

            if ($changed->isNotEmpty() && $category instanceof CatalogCollectionCategory) {
                $category->load('translations:id,catalog_collection_category_id,locale,name');
                $before = $this->fingerprint($category);
                $this->audit->record(
                    $actor,
                    AdminAuditAction::CollectionCategoryCollectionsAssigned,
                    $category,
                    $before,
                    hash('sha256', $before.'|'.$changed->count().'|'.now()->toAtomString()),
                    ['collections'],
                );
            }

            return $changed;
        }, attempts: 3);

        $this->cache->changedMany($changed);

        return $changed->count();
    }

    /**
     * @param  array<int, mixed>  $assignments
     */
    public function confirmAssignments(
        User $actor,
        array $assignments,
    ): CatalogCollectionClassificationResult {
        $this->authorizeManage($actor);
        $normalized = $this->normalizedAssignments($assignments);

        $transactionResult = DB::transaction(function () use ($actor, $normalized): array {
            $categoryPublicIds = collect($normalized)
                ->pluck('categoryPublicId')
                ->unique()
                ->values();
            $categories = CatalogCollectionCategory::query()
                ->select(['id', 'public_id', 'parent_id', 'slug', 'position', 'is_active'])
                ->with('translations:id,catalog_collection_category_id,locale,name')
                ->whereIn('public_id', $categoryPublicIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('public_id');

            if ($categories->count() !== $categoryPublicIds->count()) {
                $this->invalidAssignments();
            }

            $parentIds = $categories
                ->pluck('parent_id')
                ->filter()
                ->unique()
                ->values();
            $parents = CatalogCollectionCategory::query()
                ->select(['id', 'is_active'])
                ->whereKey($parentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($categories as $category) {
                if (! $category->is_active
                    || ($category->parent_id !== null
                        && ! (bool) $parents->get($category->parent_id)?->is_active)) {
                    $this->invalidAssignments();
                }
            }

            $collectionPublicIds = collect($normalized)
                ->pluck('collectionPublicId')
                ->values();
            $collections = CatalogCollection::query()
                ->select([
                    'id',
                    'public_id',
                    'owner_id',
                    'catalog_collection_category_id',
                    'type',
                    'visibility',
                    'moderation_status',
                    'is_featured',
                    'content_version',
                    'published_at',
                    'deleted_at',
                ])
                ->whereIn('public_id', $collectionPublicIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('public_id');

            if ($collections->count() !== $collectionPublicIds->count()) {
                $this->invalidAssignments();
            }

            $changed = [];
            $changedByCategory = [];

            foreach ($normalized as $assignment) {
                /** @var CatalogCollection $collection */
                $collection = $collections[$assignment['collectionPublicId']];

                if ($collection->catalog_collection_category_id !== null
                    || $collection->content_version !== $assignment['expectedContentVersion']) {
                    continue;
                }

                /** @var CatalogCollectionCategory $category */
                $category = $categories[$assignment['categoryPublicId']];
                $collection->forceFill([
                    'catalog_collection_category_id' => $category->id,
                    'content_version' => $collection->content_version + 1,
                ])->save();
                $changed[] = $collection;
                $changedByCategory[$category->public_id][] = $collection->public_id;
            }

            foreach ($changedByCategory as $categoryPublicId => $changedPublicIds) {
                /** @var CatalogCollectionCategory $category */
                $category = $categories[$categoryPublicId];
                $before = $this->fingerprint($category);
                sort($changedPublicIds);
                $after = hash('sha256', json_encode([
                    'category' => $before,
                    'confirmed_collection_public_ids' => $changedPublicIds,
                ], JSON_THROW_ON_ERROR));
                $this->audit->record(
                    $actor,
                    AdminAuditAction::CollectionCategoryAssignmentsConfirmed,
                    $category,
                    $before,
                    $after,
                    ['collections'],
                );
            }

            return [
                'changed' => new EloquentCollection($changed),
                'skipped' => count($normalized) - count($changed),
            ];
        }, attempts: 3);

        /** @var EloquentCollection<int, CatalogCollection> $changed */
        $changed = $transactionResult['changed'];
        $this->cache->changedMany($changed);

        return new CatalogCollectionClassificationResult(
            changed: $changed->count(),
            skipped: (int) $transactionResult['skipped'],
            changedCollectionIds: $changed
                ->map(fn (CatalogCollection $collection): int => (int) $collection->id)
                ->values()
                ->all(),
        );
    }

    private function resolveParent(?string $publicId, bool $lockForUpdate): ?CatalogCollectionCategory
    {
        if ($publicId === null || trim($publicId) === '') {
            return null;
        }

        if (! Str::isUuid($publicId)) {
            $this->validation('parentPublicId', __('collections.categories.validation_parent'));
        }

        $query = CatalogCollectionCategory::query()
            ->where('public_id', Str::lower(trim($publicId)));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $parent = $query->first();

        if (! $parent instanceof CatalogCollectionCategory
            || $parent->parent_id !== null
            || ! $parent->is_active) {
            $this->validation('parentPublicId', __('collections.categories.validation_parent'));
        }

        return $parent;
    }

    /**
     * @param  array{ru?: string, en?: string}  $translations
     * @return array{ru: string, en: string}
     */
    private function validTranslations(array $translations): array
    {
        $normalized = [
            'ru' => UserPlainText::name($translations['ru'] ?? ''),
            'en' => UserPlainText::name($translations['en'] ?? ''),
        ];

        foreach ($normalized as $locale => $name) {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                $this->validation(
                    'translations.'.$locale,
                    __('collections.categories.validation_name'),
                );
            }
        }

        return $normalized;
    }

    private function authorizeManage(User $actor): void
    {
        Gate::forUser($actor)->authorize(AdminPermission::ContentManage->value);
    }

    /**
     * @param  array<int, mixed>  $assignments
     * @return list<array{
     *     collectionPublicId: string,
     *     expectedContentVersion: int,
     *     categoryPublicId: string
     * }>
     */
    private function normalizedAssignments(array $assignments): array
    {
        if ($assignments === [] || count($assignments) > 100 || ! array_is_list($assignments)) {
            $this->invalidAssignments();
        }

        $normalized = [];
        $assignmentKeys = [
            'collectionPublicId',
            'expectedContentVersion',
            'categoryPublicId',
        ];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)
                || count($assignment) !== count($assignmentKeys)
                || array_diff($assignmentKeys, array_keys($assignment)) !== []
                || ! is_string($assignment['collectionPublicId'])
                || ! is_int($assignment['expectedContentVersion'])
                || $assignment['expectedContentVersion'] < 1
                || ! is_string($assignment['categoryPublicId'])) {
                $this->invalidAssignments();
            }

            $collectionPublicId = Str::lower(trim($assignment['collectionPublicId']));
            $categoryPublicId = Str::lower(trim($assignment['categoryPublicId']));

            if (! Str::isUuid($collectionPublicId) || ! Str::isUuid($categoryPublicId)) {
                $this->invalidAssignments();
            }

            $normalized[] = [
                'collectionPublicId' => $collectionPublicId,
                'expectedContentVersion' => $assignment['expectedContentVersion'],
                'categoryPublicId' => $categoryPublicId,
            ];
        }

        if (collect($normalized)->pluck('collectionPublicId')->duplicates()->isNotEmpty()) {
            $this->invalidAssignments();
        }

        return $normalized;
    }

    private function fingerprint(CatalogCollectionCategory $category): string
    {
        return hash('sha256', json_encode([
            'id' => $category->id,
            'public_id' => $category->public_id,
            'parent_id' => $category->parent_id,
            'slug' => $category->slug,
            'position' => $category->position,
            'is_active' => $category->is_active,
            'translations' => $category->relationLoaded('translations')
                ? $category->translations->sortBy('locale')->mapWithKeys(
                    fn (CatalogCollectionCategoryTranslation $translation): array => [
                        $translation->locale => $translation->name,
                    ],
                )->all()
                : [],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return never */
    private function validation(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** @return never */
    private function invalid(): void
    {
        throw ValidationException::withMessages([
            'categoryPublicId' => [__('collections.validation.category')],
        ]);
    }

    /** @return never */
    private function invalidAssignments(): void
    {
        throw ValidationException::withMessages([
            'assignments' => [__('collections.classification.validation_batch')],
        ]);
    }
}
