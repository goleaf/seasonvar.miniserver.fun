<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionCategorySuggestion;
use App\DTOs\CatalogCollectionClassificationSummary;
use App\Enums\AdminPermission;
use App\Enums\CatalogCollectionCategorySuggestionConfidence;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionCategoryService;
use App\Services\Collections\CatalogCollectionClassificationQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionCategoryManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Locked]
    public bool $canManage = false;

    public string $parentPublicId = '';

    public string $slug = '';

    public string $nameRu = '';

    public string $nameEn = '';

    #[Locked]
    public string $selectedPublicId = '';

    public string $selectedNameRu = '';

    public string $selectedNameEn = '';

    public bool $selectedIsActive = true;

    #[Url(as: 'collection_classification_q', history: true, except: '')]
    public string $classificationSearch = '';

    #[Url(as: 'collection_classification_visibility', history: true, except: 'public')]
    public string $classificationVisibility = 'public';

    #[Url(as: 'collection_classification_moderation', history: true, except: 'approved')]
    public string $classificationModerationStatus = 'approved';

    #[Url(as: 'collection_classification_type', history: true, except: '')]
    public string $classificationType = '';

    #[Url(as: 'collection_classification_per_page', history: true, except: 20)]
    public int $classificationPerPage = 20;

    /** @var list<string> */
    public array $selectedClassificationPublicIds = [];

    /** @var array<string, string> */
    public array $classificationCategoryByCollection = [];

    public string $classificationBatchCategoryPublicId = '';

    /** @var array<string, int> */
    #[Locked]
    public array $classificationVersionByCollection = [];

    #[Locked]
    public bool $classificationPreviewOpen = false;

    /**
     * @var list<array{
     *     collectionPublicId: string,
     *     expectedContentVersion: int,
     *     categoryPublicId: string
     * }>
     */
    #[Locked]
    public array $classificationPreviewAssignments = [];

    public ?string $notice = null;

    protected CatalogCollectionCategoryQuery $categoryQuery;

    protected CatalogCollectionClassificationQuery $classificationQuery;

    public function boot(
        CatalogCollectionCategoryQuery $categoryQuery,
        CatalogCollectionClassificationQuery $classificationQuery,
    ): void {
        Gate::authorize(AdminPermission::ContentView->value);
        $this->canManage = Gate::allows(AdminPermission::ContentManage->value);
        $this->categoryQuery = $categoryQuery;
        $this->classificationQuery = $classificationQuery;
    }

    public function updatedClassificationSearch(): void
    {
        $this->classificationSearch = Str::limit(
            Str::squish($this->classificationSearch),
            100,
            '',
        );
        $this->resetClassificationContext();
    }

    public function updatedClassificationVisibility(): void
    {
        $allowed = [
            '',
            ...array_map(
                fn (CatalogCollectionVisibility $visibility): string => $visibility->value,
                CatalogCollectionVisibility::cases(),
            ),
        ];
        $this->classificationVisibility = in_array(
            $this->classificationVisibility,
            $allowed,
            true,
        ) ? $this->classificationVisibility : '';
        $this->resetClassificationContext();
    }

    public function updatedClassificationType(): void
    {
        $allowed = [
            '',
            ...array_map(
                fn (CatalogCollectionType $type): string => $type->value,
                CatalogCollectionType::cases(),
            ),
        ];
        $this->classificationType = in_array($this->classificationType, $allowed, true)
            ? $this->classificationType
            : '';
        $this->resetClassificationContext();
    }

    public function updatedClassificationModerationStatus(): void
    {
        $allowed = [
            '',
            ...array_map(
                fn (CatalogCollectionModerationStatus $status): string => $status->value,
                CatalogCollectionModerationStatus::cases(),
            ),
        ];
        $this->classificationModerationStatus = in_array(
            $this->classificationModerationStatus,
            $allowed,
            true,
        ) ? $this->classificationModerationStatus : CatalogCollectionModerationStatus::Approved->value;
        $this->resetClassificationContext();
    }

    public function updatedClassificationPerPage(): void
    {
        $this->classificationPerPage = in_array(
            $this->classificationPerPage,
            [10, 20, 30, 50],
            true,
        ) ? $this->classificationPerPage : 20;
        $this->resetClassificationContext();
    }

    public function updatedSelectedClassificationPublicIds(): void
    {
        $this->selectedClassificationPublicIds = collect(
            $this->selectedClassificationPublicIds,
        )
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->unique()
            ->take(50)
            ->values()
            ->all();
        $this->closeClassificationPreview();
    }

    public function updatedClassificationCategoryByCollection(
        mixed $value,
        mixed $key,
    ): void {
        if (is_string($key) && Str::isUuid($key)) {
            $publicId = Str::lower($key);
            $selected = collect($this->selectedClassificationPublicIds)
                ->filter(fn (mixed $candidate): bool => is_string($candidate) && Str::isUuid($candidate))
                ->map(fn (string $candidate): string => Str::lower($candidate));

            if (is_scalar($value) && Str::isUuid((string) $value)) {
                $selected->push($publicId);
            } else {
                $selected = $selected->reject(
                    fn (string $candidate): bool => $candidate === $publicId,
                );
            }

            $this->selectedClassificationPublicIds = $selected
                ->unique()
                ->take(50)
                ->values()
                ->all();
        }

        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
        ]);
    }

    public function createCategory(CatalogCollectionCategoryService $categories): void
    {
        $this->authorizeManage();
        $validated = $this->validate([
            'parentPublicId' => ['nullable', 'uuid'],
            'slug' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/'],
            'nameRu' => ['required', 'string', 'min:2', 'max:120'],
            'nameEn' => ['required', 'string', 'min:2', 'max:120'],
        ], $this->messages());

        $categories->createCategory(
            $this->user(),
            $validated['parentPublicId'] !== '' ? $validated['parentPublicId'] : null,
            $validated['slug'],
            ['ru' => $validated['nameRu'], 'en' => $validated['nameEn']],
        );
        $this->reset(['parentPublicId', 'slug', 'nameRu', 'nameEn']);
        $this->notice = __('collections.categories.created');
    }

    public function selectCategory(string $publicId): void
    {
        $this->authorizeManage();
        $category = $this->category($publicId);
        $category->load('translations:id,catalog_collection_category_id,locale,name');
        $this->selectedPublicId = $category->public_id;
        $this->selectedNameRu = (string) ($category->translations->firstWhere('locale', 'ru')?->name ?? '');
        $this->selectedNameEn = (string) ($category->translations->firstWhere('locale', 'en')?->name ?? '');
        $this->selectedIsActive = $category->is_active;
        $this->resetValidation();
    }

    public function saveCategory(CatalogCollectionCategoryService $categories): void
    {
        $this->authorizeManage();
        $validated = $this->validate([
            'selectedPublicId' => ['required', 'uuid'],
            'selectedNameRu' => ['required', 'string', 'min:2', 'max:120'],
            'selectedNameEn' => ['required', 'string', 'min:2', 'max:120'],
            'selectedIsActive' => ['boolean'],
        ], $this->messages());

        $categories->updateCategory(
            $this->user(),
            $this->category($validated['selectedPublicId']),
            ['ru' => $validated['selectedNameRu'], 'en' => $validated['selectedNameEn']],
            (bool) $validated['selectedIsActive'],
        );
        $this->reset(['selectedPublicId', 'selectedNameRu', 'selectedNameEn']);
        $this->selectedIsActive = true;
        $this->notice = __('collections.categories.updated');
    }

    public function moveCategory(
        string $publicId,
        int $direction,
        CatalogCollectionCategoryService $categories,
    ): void {
        $this->authorizeManage();
        $categories->moveCategory($this->user(), $this->category($publicId), $direction);
        $this->notice = __('collections.categories.order_updated');
    }

    public function selectHighConfidence(): void
    {
        $this->authorizeManage();
        $suggestions = $this->classificationSuggestions;
        $selected = [];
        $categoryByCollection = [];

        foreach ($suggestions as $publicId => $suggestion) {
            if ($suggestion->confidence !== CatalogCollectionCategorySuggestionConfidence::High
                || ! $suggestion->isSuggested()) {
                continue;
            }

            $selected[] = $publicId;
            $categoryByCollection[$publicId] = (string) $suggestion->categoryPublicId;
        }

        $this->selectedClassificationPublicIds = $selected;
        $this->classificationCategoryByCollection = $categoryByCollection;
        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
        ]);
    }

    public function selectCurrentClassificationPage(): void
    {
        $this->authorizeManage();
        $page = $this->sortClassificationPageByConfidence(
            $this->classificationPage,
            $this->classificationSuggestions,
        );
        $selected = $page
            ->pluck('public_id')
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->unique()
            ->take(50)
            ->values()
            ->all();
        $allowed = array_flip($selected);

        $this->selectedClassificationPublicIds = $selected;
        $this->classificationCategoryByCollection = array_intersect_key(
            $this->classificationCategoryByCollection,
            $allowed,
        );
        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
        ]);
    }

    public function clearClassificationSelection(): void
    {
        $this->authorizeManage();
        $this->selectedClassificationPublicIds = [];
        $this->classificationCategoryByCollection = [];
        $this->classificationBatchCategoryPublicId = '';
        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
            'classificationBatchCategoryPublicId',
            'classificationSuggestion',
        ]);
    }

    public function stageClassificationSuggestion(
        string $publicId,
    ): void {
        $this->authorizeManage();
        $publicId = Str::lower(trim($publicId));
        $page = $this->classificationPage;
        $pagePublicIds = $page->pluck('public_id')
            ->map(fn (string $candidate): string => Str::lower($candidate));

        if (! Str::isUuid($publicId) || ! $pagePublicIds->contains($publicId)) {
            $this->classificationValidation(
                'classificationSuggestion',
                __('collections.classification.validation_suggestion'),
            );
        }

        $suggestion = $this->classificationSuggestions[$publicId] ?? null;

        if (! $suggestion instanceof CatalogCollectionCategorySuggestion
            || ! $suggestion->isSuggested()) {
            $this->classificationValidation(
                'classificationSuggestion',
                __('collections.classification.validation_suggestion'),
            );
        }

        $this->selectedClassificationPublicIds = collect(
            $this->selectedClassificationPublicIds,
        )
            ->push($publicId)
            ->filter(fn (mixed $candidate): bool => is_string($candidate) && Str::isUuid($candidate))
            ->map(fn (string $candidate): string => Str::lower($candidate))
            ->unique()
            ->take(50)
            ->values()
            ->all();
        $this->classificationCategoryByCollection[$publicId] = (string) $suggestion->categoryPublicId;
        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection.'.$publicId,
            'classificationSuggestion',
        ]);
    }

    public function applyClassificationBatchCategory(): void
    {
        $this->authorizeManage();
        $pagePublicIds = $this->classificationPage
            ->pluck('public_id')
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->take(50)
            ->values();
        $pageLookup = $pagePublicIds->flip();
        $selected = collect($this->selectedClassificationPublicIds)
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->unique()
            ->filter(fn (string $publicId): bool => $pageLookup->has($publicId))
            ->take(50)
            ->values();

        if ($selected->isEmpty()) {
            $this->classificationValidation(
                'selectedClassificationPublicIds',
                __('collections.classification.validation_selection'),
            );
        }

        $categoryPublicId = Str::lower(trim($this->classificationBatchCategoryPublicId));
        $activeCategoryPublicIds = $this->activeCategoryPublicIds($this->categoryTree);

        if (! Str::isUuid($categoryPublicId)
            || ! in_array($categoryPublicId, $activeCategoryPublicIds, true)) {
            $this->classificationValidation(
                'classificationBatchCategoryPublicId',
                __('collections.classification.validation_target'),
            );
        }

        $this->selectedClassificationPublicIds = $selected->all();

        foreach ($selected as $publicId) {
            $this->classificationCategoryByCollection[$publicId] = $categoryPublicId;
        }

        $this->classificationCategoryByCollection = array_intersect_key(
            $this->classificationCategoryByCollection,
            $pageLookup->all(),
        );
        $this->closeClassificationPreview();
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
            'classificationBatchCategoryPublicId',
        ]);
    }

    public function prepareClassificationPreview(): void
    {
        $this->authorizeManage();
        $page = $this->classificationPage;
        $pageCollections = $page->getCollection()->keyBy('public_id');
        $activeCategoryPublicIds = $this->activeCategoryPublicIds(
            $this->categoryTree,
        );
        $selected = collect($this->selectedClassificationPublicIds)
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->unique()
            ->values();

        if ($selected->isEmpty()
            || $selected->count() > 100
            || $selected->count() !== count($this->selectedClassificationPublicIds)
            || $selected->contains(fn (string $publicId): bool => ! $pageCollections->has($publicId))) {
            $this->classificationValidation(
                'selectedClassificationPublicIds',
                __('collections.classification.validation_selection'),
            );
        }

        $assignments = [];
        $versions = [];

        foreach ($selected as $publicId) {
            $categoryPublicId = $this->classificationCategoryByCollection[$publicId] ?? null;

            if (! is_string($categoryPublicId)
                || ! Str::isUuid($categoryPublicId)
                || ! in_array(Str::lower($categoryPublicId), $activeCategoryPublicIds, true)) {
                $this->classificationValidation(
                    'classificationCategoryByCollection.'.$publicId,
                    __('collections.classification.validation_target'),
                );
            }

            /** @var CatalogCollection $collection */
            $collection = $pageCollections[$publicId];
            $categoryPublicId = Str::lower($categoryPublicId);
            $versions[$publicId] = $collection->content_version;
            $assignments[] = [
                'collectionPublicId' => $publicId,
                'expectedContentVersion' => $collection->content_version,
                'categoryPublicId' => $categoryPublicId,
            ];
        }

        $this->selectedClassificationPublicIds = $selected->all();
        $this->classificationVersionByCollection = $versions;
        $this->classificationPreviewAssignments = $assignments;
        $this->classificationPreviewOpen = true;
        $this->notice = null;
        $this->resetValidation([
            'selectedClassificationPublicIds',
            'classificationCategoryByCollection',
        ]);
    }

    public function cancelClassificationPreview(): void
    {
        $this->closeClassificationPreview();
    }

    public function confirmClassificationAssignments(
        CatalogCollectionCategoryService $categories,
    ): void {
        $this->authorizeManage();

        if (! $this->classificationPreviewOpen
            || $this->classificationPreviewAssignments === []) {
            $this->classificationValidation(
                'selectedClassificationPublicIds',
                __('collections.classification.validation_selection'),
            );
        }

        $result = $categories->confirmAssignments(
            $this->user(),
            $this->classificationPreviewAssignments,
        );
        $this->selectedClassificationPublicIds = [];
        $this->classificationCategoryByCollection = [];
        $this->classificationBatchCategoryPublicId = '';
        $this->closeClassificationPreview();
        $this->notice = __('collections.classification.confirmed', [
            'changed' => $result->changed,
            'skipped' => $result->skipped,
        ]);
        $this->resetPage(pageName: 'collectionCategoryClassificationPage');
    }

    public function render(): View
    {
        $tree = $this->categoryTree;
        $tree->each(function (CatalogCollectionCategory $root): void {
            $root->setAttribute(
                'branch_collections_count',
                (int) $root->collections_count
                    + (int) $root->children->sum('collections_count'),
            );
        });
        $rootOptions = $tree
            ->filter(fn (CatalogCollectionCategory $category): bool => $category->is_active)
            ->map(fn (CatalogCollectionCategory $category): array => [
                'value' => $category->public_id,
                'label' => $category->display_name,
            ])
            ->values()
            ->all();
        $assignmentOptions = $tree
            ->filter(fn (CatalogCollectionCategory $root): bool => $root->is_active)
            ->flatMap(function (CatalogCollectionCategory $root): array {
                $options = [[
                    'value' => $root->public_id,
                    'label' => $root->display_name,
                ]];

                foreach ($root->children as $child) {
                    if ($child->is_active) {
                        $options[] = [
                            'value' => $child->public_id,
                            'label' => $root->display_name.' — '.$child->display_name,
                        ];
                    }
                }

                return $options;
            })
            ->values()
            ->all();
        $classificationSummary = null;
        $classificationPage = null;

        if ($this->canManage) {
            $classificationSummary = $this->classificationSummary;
            $classificationPage = $this->classificationPage;
            $classificationSuggestions = $this->classificationSuggestions;
            $this->pruneClassificationState(
                $classificationPage->pluck('public_id')->all(),
            );
            $classificationPage->each(function (CatalogCollection $collection): void {
                $collection->setAttribute('visibility_label', $collection->visibility->label());
                $collection->setAttribute('type_label', $collection->type->label());
                $collection->setAttribute('moderation_status_label', $collection->moderation_status->label());
                $collection->setAttribute(
                    'owner_label',
                    $collection->owner?->name ?: __('collections.admin.system_owner'),
                );
                $collection->setAttribute('items_label', trans_choice(
                    'collections.page.items',
                    (int) $collection->total_items_count,
                    ['count' => (int) $collection->total_items_count],
                ));
            });
            $classificationSuggestionPresentations = collect($classificationSuggestions)
                ->map(fn (CatalogCollectionCategorySuggestion $suggestion): array => [
                    'categoryPath' => $suggestion->categoryPath,
                    'score' => $suggestion->score,
                    'confidenceLabel' => $suggestion->confidence->label(),
                    'confidenceVariant' => $suggestion->confidence->variant(),
                    'canStage' => $suggestion->isSuggested(),
                    'reasonLabels' => array_map(
                        fn (string $reason): string => __(
                            'collections.classification.reasons.'.$reason,
                        ),
                        $suggestion->reasonCodes,
                    ),
                    'sampleSize' => $suggestion->sampleSize,
                    'totalItems' => $suggestion->totalItems,
                ])
                ->all();
            $classificationPage->each(function (CatalogCollection $collection) use ($classificationSuggestionPresentations): void {
                $collection->setAttribute(
                    'classification_presentation',
                    $classificationSuggestionPresentations[$collection->public_id],
                );
            });
            $classificationPage = $this->sortClassificationPageByConfidence(
                $classificationPage,
                $classificationSuggestions,
            );
        }

        $classificationOptionLabels = collect($assignmentOptions)
            ->pluck('label', 'value')
            ->all();
        $classificationVisibilityOptions = collect(CatalogCollectionVisibility::cases())
            ->map(fn (CatalogCollectionVisibility $visibility): array => [
                'value' => $visibility->value,
                'label' => $visibility->label(),
            ])
            ->all();
        $classificationTypeOptions = collect(CatalogCollectionType::cases())
            ->map(fn (CatalogCollectionType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ])
            ->all();
        $classificationModerationOptions = collect(CatalogCollectionModerationStatus::cases())
            ->map(fn (CatalogCollectionModerationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->all();
        $classificationPreviewRows = collect($this->classificationPreviewAssignments)
            ->map(function (array $assignment) use ($classificationPage, $classificationOptionLabels): array {
                $collection = $classificationPage?->getCollection()
                    ->firstWhere('public_id', $assignment['collectionPublicId']);

                return [
                    ...$assignment,
                    'collectionName' => $collection instanceof CatalogCollection
                        ? $collection->display_name
                        : $assignment['collectionPublicId'],
                    'categoryLabel' => $classificationOptionLabels[$assignment['categoryPublicId']]
                        ?? $assignment['categoryPublicId'],
                ];
            })
            ->all();

        return view('livewire.collections.catalog-collection-category-manager', [
            'categoryTree' => $tree,
            'rootOptions' => $rootOptions,
            'assignmentOptions' => $assignmentOptions,
            'classificationSummary' => $classificationSummary,
            'classificationPage' => $classificationPage,
            'classificationOptionLabels' => $classificationOptionLabels,
            'classificationVisibilityOptions' => $classificationVisibilityOptions,
            'classificationModerationOptions' => $classificationModerationOptions,
            'classificationTypeOptions' => $classificationTypeOptions,
            'classificationPreviewRows' => $classificationPreviewRows,
        ]);
    }

    /** @return Collection<int, CatalogCollectionCategory> */
    #[Computed]
    public function categoryTree(): Collection
    {
        return $this->categoryQuery->administrationTree();
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    #[Computed]
    public function classificationPage(): LengthAwarePaginator
    {
        return $this->classificationQuery->paginateUncategorized(
            search: $this->classificationSearch,
            visibility: $this->classificationVisibility,
            type: $this->classificationType,
            perPage: $this->classificationPerPage,
            moderationStatus: $this->classificationModerationStatus,
        );
    }

    /** @return array<string, CatalogCollectionCategorySuggestion> */
    #[Computed]
    public function classificationSuggestions(): array
    {
        return $this->classificationQuery->suggestionsFor(
            $this->classificationPage,
            $this->categoryTree,
        );
    }

    #[Computed]
    public function classificationSummary(): CatalogCollectionClassificationSummary
    {
        return $this->classificationQuery->summary();
    }

    /**
     * @param  LengthAwarePaginator<int, CatalogCollection>  $page
     * @param  array<string, CatalogCollectionCategorySuggestion>  $suggestions
     * @return LengthAwarePaginator<int, CatalogCollection>
     */
    private function sortClassificationPageByConfidence(
        LengthAwarePaginator $page,
        array $suggestions,
    ): LengthAwarePaginator {
        $originalPositions = $page->getCollection()
            ->pluck('public_id')
            ->flip();
        $sorted = $page->getCollection()
            ->sortBy(function (CatalogCollection $collection) use ($suggestions, $originalPositions): array {
                $suggestion = $suggestions[$collection->public_id];

                return [
                    $this->confidenceRank($suggestion->confidence),
                    -$suggestion->score,
                    (int) $originalPositions->get($collection->public_id, PHP_INT_MAX),
                ];
            })
            ->values();

        $page->setCollection($sorted);

        return $page;
    }

    private function confidenceRank(
        CatalogCollectionCategorySuggestionConfidence $confidence,
    ): int {
        return match ($confidence) {
            CatalogCollectionCategorySuggestionConfidence::High => 0,
            CatalogCollectionCategorySuggestionConfidence::Medium => 1,
            CatalogCollectionCategorySuggestionConfidence::Low => 2,
            CatalogCollectionCategorySuggestionConfidence::None => 3,
        };
    }

    /**
     * @param  Collection<int, CatalogCollectionCategory>  $tree
     * @return list<string>
     */
    private function activeCategoryPublicIds(
        Collection $tree,
    ): array {
        return $tree
            ->filter(fn (CatalogCollectionCategory $root): bool => $root->is_active)
            ->flatMap(function (CatalogCollectionCategory $root): array {
                return [
                    $root->public_id,
                    ...$root->children
                        ->filter(fn (CatalogCollectionCategory $child): bool => $child->is_active)
                        ->pluck('public_id')
                        ->all(),
                ];
            })
            ->map(fn (string $publicId): string => Str::lower($publicId))
            ->values()
            ->all();
    }

    /** @param list<string> $currentPagePublicIds */
    private function pruneClassificationState(array $currentPagePublicIds): void
    {
        $allowed = array_flip($currentPagePublicIds);
        $selected = array_values(array_filter(
            $this->selectedClassificationPublicIds,
            fn (mixed $publicId): bool => is_string($publicId) && isset($allowed[$publicId]),
        ));

        if ($selected !== $this->selectedClassificationPublicIds) {
            $this->selectedClassificationPublicIds = $selected;
            $this->closeClassificationPreview();
        }

        $this->classificationCategoryByCollection = array_intersect_key(
            $this->classificationCategoryByCollection,
            $allowed,
        );
        $this->classificationVersionByCollection = array_intersect_key(
            $this->classificationVersionByCollection,
            $allowed,
        );
    }

    private function resetClassificationContext(): void
    {
        $this->selectedClassificationPublicIds = [];
        $this->classificationCategoryByCollection = [];
        $this->classificationBatchCategoryPublicId = '';
        $this->closeClassificationPreview();
        $this->resetPage(pageName: 'collectionCategoryClassificationPage');
    }

    private function closeClassificationPreview(): void
    {
        $this->classificationPreviewOpen = false;
        $this->classificationPreviewAssignments = [];
        $this->classificationVersionByCollection = [];
    }

    private function classificationValidation(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function category(string $publicId): CatalogCollectionCategory
    {
        abort_unless(Str::isUuid($publicId), 404);

        return CatalogCollectionCategory::query()
            ->where('public_id', Str::lower($publicId))
            ->firstOrFail();
    }

    private function authorizeManage(): void
    {
        Gate::authorize(AdminPermission::ContentManage->value);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'parentPublicId.*' => __('collections.categories.validation_parent'),
            'slug.*' => __('collections.categories.validation_slug'),
            'nameRu.*' => __('collections.categories.validation_name'),
            'nameEn.*' => __('collections.categories.validation_name'),
            'selectedPublicId.*' => __('collections.categories.validation_parent'),
            'selectedNameRu.*' => __('collections.categories.validation_name'),
            'selectedNameEn.*' => __('collections.categories.validation_name'),
            'selectedIsActive.*' => __('collections.categories.validation_active_parent'),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
