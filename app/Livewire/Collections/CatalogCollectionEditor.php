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
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class CatalogCollectionEditor extends Component
{
    private const AUTHORING_LOCALE = 'ru';

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

    #[Locked]
    public string $contentLocale = self::AUTHORING_LOCALE;

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
        $this->contentLocale = self::AUTHORING_LOCALE;
        $this->fillCollection($collection);
        $status = Session::pull('catalog_collection_status');
        $this->status = is_string($status) ? $status : null;
    }

    public function save(CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'visibility' => ['required', Rule::enum(CatalogCollectionVisibility::class)],
            'sortMode' => ['required', Rule::enum(CatalogCollectionSort::class)],
            'seoTitle' => ['nullable', 'string', 'max:180'],
            'seoDescription' => ['nullable', 'string', 'max:500'],
        ], [
            'name.*' => __('collections.validation.name'),
            'description.*' => __('collections.validation.description'),
            'visibility.*' => __('collections.validation.visibility'),
            'sortMode.*' => __('collections.validation.sort'),
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
                ? self::AUTHORING_LOCALE
                : $collection->content_locale,
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
        $changed = $items->move($this->user(), $collection, $itemId, $direction);

        if (! $changed) {
            $this->status = __('collections.status.order_unchanged');

            return;
        }

        $this->contentVersion = $collection->refresh()->content_version;
        $this->status = __('collections.status.order_updated');
    }

    public function delete(CatalogCollectionResolver $resolver, CatalogCollectionService $service): void
    {
        $service->delete($this->user(), $resolver->byPublicId($this->collectionPublicId, true));
        Session::flash('catalog_collection_status', __('collections.status.deleted'));
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
        $totalItems = (int) ($collection->total_items_count ?? 0);

        foreach ($items->getCollection() as $item) {
            $position = (int) $item->getAttribute('collection_position');
            $item->setAttribute('collection_position_label', __('collections.page.position', ['position' => $position]));
            $item->setAttribute('collection_can_move_up', $position > 1);
            $item->setAttribute('collection_can_move_down', $position < $totalItems);
            $item->setAttribute('collection_move_up_label', __('collections.accessibility.reorder_item', [
                'title' => $item->display_title,
            ]).' — '.__('collections.actions.move_up'));
            $item->setAttribute('collection_move_down_label', __('collections.accessibility.reorder_item', [
                'title' => $item->display_title,
            ]).' — '.__('collections.actions.move_down'));
        }
        $isEditorial = $collection->type->value === 'editorial';

        return view('livewire.collections.catalog-collection-editor', [
            'collection' => $collection,
            'items' => $items,
            'unavailableItems' => $query->unavailableItems($collection, $user),
            'collectionTypeLabel' => $collection->type->label(),
            'collectionVisibilityLabel' => $collection->visibility->label(),
            'collectionModerationLabel' => $collection->moderation_status->label(),
            'isEditorial' => $isEditorial,
            'isPendingModeration' => $collection->moderation_status->value === 'pending',
            'canOpenPublicPage' => $collection->isPubliclyViewable(),
            'hasCover' => is_string($collection->cover_path) && $collection->cover_path !== '',
            'itemsTitle' => trans_choice('collections.page.items', $totalItems, ['count' => $totalItems]),
            'visibilityOptions' => array_map(static fn (CatalogCollectionVisibility $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionVisibility::cases()),
            'sortOptions' => array_map(static fn (CatalogCollectionSort $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionSort::cases()),
            'coverUrl' => $covers->url($collection),
            'maximumCoverMegabytes' => round((int) config('uploads.max_image_kilobytes', 2048) / 1024, 1),
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
        $collection->loadMissing('translations:id,catalog_collection_id,locale,name,description,seo_title,seo_description');
        $translation = $collection->type->value === 'editorial'
            ? $collection->translations->firstWhere('locale', self::AUTHORING_LOCALE)
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
