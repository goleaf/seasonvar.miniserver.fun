<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionData;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use App\Services\Collections\CatalogCollectionCoverService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionDashboard extends Component
{
    use InteractsWithCollectionLocale;
    use WithPagination;

    public bool $showCreate = false;

    public string $name = '';

    public string $description = '';

    public string $visibility = 'private';

    public string $type = 'user';

    public ?string $status = null;

    #[Locked]
    public string $creationPublicId = '';

    #[Locked]
    public string $defaultVisibility = 'private';

    public function mount(AccountSettingsService $settings): void
    {
        $this->setCollectionLocale(null);
        $this->defaultVisibility = $settings->resolve($this->user())->collectionDefaultVisibility;
        $this->visibility = $this->defaultVisibility;
        $this->creationPublicId = (string) Str::uuid();
        $status = Session::pull('catalog_collection_status');
        $this->status = is_string($status) ? $status : null;
    }

    public function create(CatalogCollectionService $service): void
    {
        $user = $this->user();
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'visibility' => ['required', Rule::enum(CatalogCollectionVisibility::class)],
            'type' => ['required', Rule::in($this->creatableTypes($user))],
        ], $this->messages());

        $collection = $service->create($user, new CatalogCollectionData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            visibility: CatalogCollectionVisibility::from($validated['visibility']),
            type: CatalogCollectionType::from($validated['type']),
            contentLocale: $validated['type'] === CatalogCollectionType::Editorial->value
                ? $this->interfaceLocale
                : null,
            publicId: $this->creationPublicId,
        ));

        $this->reset(['name', 'description', 'type', 'showCreate']);
        $this->visibility = $this->defaultVisibility;
        Session::flash('catalog_collection_status', __('collections.status.created'));
        $this->redirectRoute('collections.edit', ['collectionPublicId' => $collection->public_id], navigate: true);
    }

    public function delete(string $publicId, CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $service->delete($this->user(), $resolver->byPublicId($publicId, true));
        $this->status = __('collections.status.deleted');
        $this->resetPage(pageName: 'myCollectionsPage');
    }

    public function restore(string $publicId, CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $service->restore($this->user(), $resolver->byPublicId($publicId, true));
        $this->status = __('collections.status.restored');
        $this->resetPage(pageName: 'deletedCollectionsPage');
    }

    public function forceDelete(
        string $publicId,
        CatalogCollectionResolver $resolver,
        CatalogCollectionService $service,
        CatalogCollectionCoverService $covers,
    ): void {
        $service->forceDelete($this->user(), $resolver->byPublicId($publicId, true), $covers);
        $this->status = __('collections.status.deleted_forever');
        $this->resetPage(pageName: 'deletedCollectionsPage');
    }

    public function render(CatalogCollectionQuery $collections): View
    {
        $user = $this->user();
        $ownedCollections = $collections->ownedBy($user);
        $deletedCollections = $collections->ownedBy($user, true);
        $deletedCollections->getCollection()->each(function (CatalogCollection $collection): void {
            $collection->setAttribute('deleted_at_label', $collection->deleted_at?->format('d.m.Y H:i'));
        });
        $typeOptions = Gate::forUser($user)->allows('createEditorial', CatalogCollection::class)
            ? [CatalogCollectionType::User, CatalogCollectionType::Editorial]
            : [CatalogCollectionType::User];

        return view('livewire.collections.catalog-collection-dashboard', [
            'collections' => $ownedCollections,
            'deletedCollections' => $deletedCollections,
            'visibilityOptions' => array_map(static fn (CatalogCollectionVisibility $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'hint' => __('collections.visibility.'.$option->value.'_hint'),
            ], CatalogCollectionVisibility::cases()),
            'typeOptions' => array_map(static fn (CatalogCollectionType $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], $typeOptions),
            'showTypeSelector' => count($typeOptions) > 1,
            'canCreate' => Gate::forUser($user)->allows('create', CatalogCollection::class),
            'restorationDays' => max(1, (int) config('catalog-collections.restoration_days', 30)),
        ])->extends('layouts.app', [
            'title' => __('collections.dashboard.title'),
            'seo' => [
                'title' => __('collections.dashboard.title'),
                'description' => __('collections.dashboard.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('collections.mine'),
                'alternates' => [],
            ],
        ])->section('content');
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.required' => __('collections.validation.name'),
            'name.min' => __('collections.validation.name'),
            'name.max' => __('collections.validation.name'),
            'description.max' => __('collections.validation.description'),
            'visibility.*' => __('collections.validation.visibility'),
            'type.*' => __('collections.validation.type'),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return list<string> */
    private function creatableTypes(User $user): array
    {
        return Gate::forUser($user)->allows('createEditorial', CatalogCollection::class)
            ? [CatalogCollectionType::User->value, CatalogCollectionType::Editorial->value]
            : [CatalogCollectionType::User->value];
    }
}
