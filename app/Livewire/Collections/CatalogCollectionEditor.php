<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogCollectionItemCriteria;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionTranslation;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCoverService;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionService;
use App\Support\Uploads\PrivateImageUploadRules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class CatalogCollectionEditor extends Component
{
    use InteractsWithCollectionLocale;
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public string $collectionPublicId = '';

    #[Locked]
    public int $contentVersion = 1;

    public string $name = '';

    public string $description = '';

    public string $visibility = 'private';

    public string $sortMode = 'manual';

    public string $contentLocale = 'ru';

    public string $seoTitle = '';

    public string $seoDescription = '';

    public mixed $cover = null;

    public ?string $status = null;

    public function mount(string $collectionPublicId, CatalogCollectionResolver $resolver): void
    {
        $this->setCollectionLocale(null);
        $this->collectionPublicId = $collectionPublicId;
        $collection = $resolver->byPublicId($collectionPublicId);
        Gate::authorize('update', $collection);
        $this->contentLocale = $this->interfaceLocale;
        $this->fillCollection($collection);
    }

    public function selectEditorialLocale(string $locale, CatalogCollectionResolver $resolver): void
    {
        abort_unless(in_array($locale, $this->collectionLocales(), true), 404);
        $collection = $resolver->byPublicId($this->collectionPublicId);
        Gate::authorize('update', $collection);
        abort_unless($collection->type->value === 'editorial', 404);
        $this->contentLocale = $locale;
        $this->fillCollection($collection);
        $this->resetValidation();
        $this->status = null;
    }

    public function save(CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'visibility' => ['required', Rule::enum(CatalogCollectionVisibility::class)],
            'sortMode' => ['required', Rule::enum(CatalogCollectionSort::class)],
            'contentLocale' => ['required', Rule::in($this->collectionLocales())],
            'seoTitle' => ['nullable', 'string', 'max:180'],
            'seoDescription' => ['nullable', 'string', 'max:500'],
        ], [
            'name.*' => __('collections.validation.name'),
            'description.*' => __('collections.validation.description'),
            'visibility.*' => __('collections.validation.visibility'),
            'sortMode.*' => __('collections.validation.sort'),
            'contentLocale.*' => __('collections.validation.locale'),
            'seoTitle.*' => __('collections.validation.seo_title'),
            'seoDescription.*' => __('collections.validation.seo_description'),
        ]);
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $updated = $service->update($this->user(), $collection, new CatalogCollectionData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            visibility: CatalogCollectionVisibility::from($validated['visibility']),
            sortMode: CatalogCollectionSort::from($validated['sortMode']),
            type: $collection->type,
            contentLocale: $collection->type->value === 'editorial'
                ? $validated['contentLocale']
                : ($collection->content_locale ?? $this->interfaceLocale),
            seoTitle: $collection->type->value === 'editorial' && $validated['seoTitle'] !== ''
                ? $validated['seoTitle']
                : null,
            seoDescription: $collection->type->value === 'editorial' && $validated['seoDescription'] !== ''
                ? $validated['seoDescription']
                : null,
        ), $this->contentVersion);
        $this->fillCollection($updated);
        $this->status = __('collections.status.updated');
    }

    public function uploadCover(CatalogCollectionResolver $resolver, CatalogCollectionCoverService $covers): void
    {
        $this->validate(['cover' => PrivateImageUploadRules::required()], [
            'cover.*' => __('collections.validation.cover'),
        ]);
        abort_unless($this->cover instanceof UploadedFile, 422);
        $collection = $covers->replace($this->user(), $resolver->byPublicId($this->collectionPublicId), $this->cover);
        $this->cover = null;
        $this->contentVersion = $collection->content_version;
        $this->status = __('collections.status.cover_updated');
    }

    public function removeCover(CatalogCollectionResolver $resolver, CatalogCollectionCoverService $covers): void
    {
        $collection = $covers->remove($this->user(), $resolver->byPublicId($this->collectionPublicId));
        $this->contentVersion = $collection->content_version;
        $this->status = __('collections.status.cover_removed');
    }

    public function removeItem(int $catalogTitleId, CatalogCollectionResolver $resolver, CatalogCollectionItemService $items): void
    {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $items->remove($this->user(), $collection, $catalogTitleId);
        $this->contentVersion = $collection->refresh()->content_version;
        $this->status = __('collections.membership.removed');
        $this->resetPage(pageName: 'collectionPage');
    }

    public function moveItem(int $itemId, int $direction, CatalogCollectionResolver $resolver, CatalogCollectionItemService $items): void
    {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $items->move($this->user(), $collection, $itemId, $direction);
        $this->contentVersion = $collection->refresh()->content_version;
        $this->status = __('collections.status.order_updated');
    }

    public function delete(CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $service->delete($this->user(), $resolver->byPublicId($this->collectionPublicId));
        $this->redirectRoute('collections.mine', navigate: true);
    }

    public function render(
        CatalogCollectionResolver $resolver,
        CatalogCollectionQuery $query,
        CatalogCollectionCoverService $covers,
    ): View {
        $collection = $query->summary($resolver->byPublicId($this->collectionPublicId));
        Gate::authorize('update', $collection);
        $user = $this->user();
        $items = $query->items($collection, $user, new CatalogCollectionItemCriteria(
            sort: CatalogCollectionSort::Manual,
            perPage: 24,
        ));

        return view('livewire.collections.catalog-collection-editor', [
            'collection' => $collection,
            'items' => $items,
            'unavailableItems' => $query->unavailableItems($collection, $user),
            'visibilityOptions' => CatalogCollectionVisibility::cases(),
            'sortOptions' => CatalogCollectionSort::cases(),
            'coverUrl' => $covers->url($collection),
            'maximumCoverMegabytes' => round((int) config('uploads.max_image_kilobytes', 2048) / 1024, 1),
            'supportedLocales' => $this->collectionLocales(),
        ])->extends('layouts.app', [
            'title' => __('collections.actions.edit').' — '.$collection->display_name,
            'seo' => [
                'title' => __('collections.actions.edit').' — '.$collection->display_name,
                'description' => __('collections.dashboard.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('collections.edit', ['collectionPublicId' => $collection->public_id]),
                'alternates' => [],
            ],
        ])->section('content');
    }

    private function fillCollection(CatalogCollection $collection): void
    {
        $collection->loadMissing('translations');
        $translation = $collection->type->value === 'editorial'
            ? $collection->translations->firstWhere('locale', $this->contentLocale)
            : null;
        $this->name = $translation instanceof CatalogCollectionTranslation ? $translation->name : $collection->name;
        $this->description = $translation instanceof CatalogCollectionTranslation
            ? ($translation->description ?? '')
            : ($collection->description ?? '');
        $this->seoTitle = $translation instanceof CatalogCollectionTranslation ? ($translation->seo_title ?? '') : '';
        $this->seoDescription = $translation instanceof CatalogCollectionTranslation ? ($translation->seo_description ?? '') : '';
        $this->visibility = $collection->visibility->value;
        $this->sortMode = $collection->sort_mode->value;
        $this->contentVersion = $collection->content_version;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
