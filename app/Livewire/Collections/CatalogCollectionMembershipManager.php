<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionData;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Concerns\InteractsWithCatalogCollectionCategory;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionCreateWithTitleService;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CatalogCollectionMembershipManager extends Component
{
    use InteractsWithCatalogCollectionCategory;

    #[Locked]
    public int $catalogTitleId;

    public bool $open = false;

    /** @var list<string> */
    public array $selectedCollectionPublicIds = [];

    public string $newName = '';

    public string $newDescription = '';

    public string $newVisibility = 'private';

    public ?string $notice = null;

    #[Locked]
    public string $creationPublicId = '';

    #[Locked]
    public string $defaultVisibility = 'private';

    public function mount(int $catalogTitleId, AccountSettingsService $settings): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $user = auth()->user();
        $this->defaultVisibility = $user instanceof User
            ? $settings->resolve($user)->collectionDefaultVisibility
            : (string) config('catalog-collections.default_visibility', 'private');
        $this->newVisibility = $this->defaultVisibility;
        $this->resetCategorySelection();
        $this->creationPublicId = (string) Str::uuid();
    }

    public function openSelector(CatalogCollectionQuery $collections): void
    {
        $user = $this->user();
        $this->selectedCollectionPublicIds = $collections->manageableForTitle($user, $this->catalogTitleId)
            ->filter(fn ($collection): bool => (bool) $collection->contains_title)
            ->pluck('public_id')
            ->all();
        $this->resetValidation();
        $this->open = true;
        $this->dispatch('collection-selector-opened');
    }

    public function closeSelector(): void
    {
        $this->open = false;
        $this->selectedCollectionPublicIds = [];
        $this->reset(['newName', 'newDescription']);
        $this->newVisibility = $this->defaultVisibility;
        $this->creationPublicId = (string) Str::uuid();
        $this->resetValidation();
        $this->dispatch('collection-selector-closed');
    }

    public function apply(CatalogCollectionItemService $items, CatalogTitleQuery $titles): void
    {
        $user = $this->user();
        $items->synchronizeMembership($user, $this->title($titles, $user), $this->selectedCollectionPublicIds);
        $this->notice = __('collections.membership.applied');
        $this->closeSelector();
    }

    public function createAndAdd(
        CatalogCollectionCreateWithTitleService $creator,
        CatalogTitleQuery $titles,
    ): void {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'min:2', 'max:160'],
            'newDescription' => ['nullable', 'string', 'max:10000'],
            'newVisibility' => ['required', Rule::enum(CatalogCollectionVisibility::class)],
            'categoryRootPublicId' => ['nullable', 'uuid'],
            'categoryPublicId' => ['nullable', 'uuid'],
        ], [
            'newName.*' => __('collections.validation.name'),
            'newDescription.*' => __('collections.validation.description'),
            'newVisibility.*' => __('collections.validation.visibility'),
            'categoryRootPublicId.*' => __('collections.validation.category'),
            'categoryPublicId.*' => __('collections.validation.category'),
        ]);
        $user = $this->user();
        $creator->create($user, $this->title($titles, $user), new CatalogCollectionData(
            name: $validated['newName'],
            description: $validated['newDescription'] !== '' ? $validated['newDescription'] : null,
            visibility: CatalogCollectionVisibility::from($validated['newVisibility']),
            contentLocale: null,
            publicId: $this->creationPublicId,
            categoryPublicId: $this->selectedCategoryPublicId(),
        ));
        $this->notice = __('collections.membership.created_and_added');
        $this->closeSelector();
    }

    public function render(
        CatalogCollectionQuery $collections,
        CatalogCollectionCategoryQuery $categories,
    ): View {
        $user = auth()->user();
        $manageableCollections = $this->open && $user instanceof User
            ? $collections->manageableForTitle($user, $this->catalogTitleId)
            : collect();
        $manageableCollections->each(fn (CatalogCollection $collection) => $collection->setAttribute(
            'visibility_label',
            $collection->visibility->label(),
        ));
        $categoryViewData = $this->open && $user instanceof User
            ? $this->categorySelectionViewData($categories)
            : [
                'categoryRootOptions' => [],
                'categoryChildOptions' => [],
                'categoryAssignmentArchived' => false,
            ];

        return view('livewire.collections.catalog-collection-membership-manager', [
            'authenticated' => $user instanceof User,
            'manageableCollections' => $manageableCollections,
            'selectedCountLabel' => __('collections.membership.selected', [
                'count' => count($this->selectedCollectionPublicIds),
            ]),
            'visibilityOptions' => array_map(static fn (CatalogCollectionVisibility $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionVisibility::cases()),
            ...$categoryViewData,
        ]);
    }

    private function title(CatalogTitleQuery $titles, User $user): CatalogTitle
    {
        return $titles->visibleTo($user)->findOrFail($this->catalogTitleId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
