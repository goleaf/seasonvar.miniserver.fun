<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogCollectionItemCriteria;
use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogSmartCollectionCompletion;
use App\Enums\CatalogSmartCollectionPreset;
use App\Enums\CatalogWatchStatus;
use App\Livewire\Concerns\InteractsWithCatalogCollectionCategory;
use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionTranslation;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionService;
use App\Services\Collections\CatalogSmartCollectionOptionsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionEditor extends Component
{
    private const AUTHORING_LOCALE = 'ru';

    private const ITEMS_PER_PAGE = 24;

    use InteractsWithCatalogCollectionCategory;
    use InteractsWithCollectionLocale;
    use InteractsWithPaginationIslands;
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
    public string $mode = 'manual';

    #[Locked]
    public string $contentLocale = self::AUTHORING_LOCALE;

    public string $seoTitle = '';

    public string $seoDescription = '';

    public ?string $status = null;

    public string $countrySlug = '';

    public string $genreSlug = '';

    public string $actorSlug = '';

    public string $actorSearch = '';

    public string $imdbMin = '';

    public string $yearFrom = '';

    public string $yearTo = '';

    public string $completion = '';

    public string $episodesMax = '';

    public string $maxEpisodeMinutes = '';

    public bool $inLibrary = false;

    public bool $unwatched = false;

    public bool $hasSubtitles = false;

    public bool $hasNewEpisodes = false;

    public string $watchStatus = '';

    public string $watchStatusOlderDays = '';

    public bool $videoAvailable = false;

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
            'categoryRootPublicId' => ['nullable', 'uuid'],
            'categoryPublicId' => ['nullable', 'uuid'],
            'seoTitle' => ['nullable', 'string', 'max:180'],
            'seoDescription' => ['nullable', 'string', 'max:500'],
            'countrySlug' => ['nullable', 'string', 'max:120'],
            'genreSlug' => ['nullable', 'string', 'max:120'],
            'actorSlug' => ['nullable', 'string', 'max:120'],
            'imdbMin' => ['nullable', 'string', 'max:8'],
            'yearFrom' => ['nullable', 'string', 'max:4'],
            'yearTo' => ['nullable', 'string', 'max:4'],
            'completion' => ['nullable', Rule::in([
                '',
                ...array_column(CatalogSmartCollectionCompletion::cases(), 'value'),
            ])],
            'episodesMax' => ['nullable', 'string', 'max:5'],
            'maxEpisodeMinutes' => ['nullable', 'string', 'max:4'],
            'inLibrary' => ['boolean'],
            'unwatched' => ['boolean'],
            'hasSubtitles' => ['boolean'],
            'hasNewEpisodes' => ['boolean'],
            'watchStatus' => ['nullable', Rule::in([
                '',
                ...array_column(CatalogWatchStatus::cases(), 'value'),
            ])],
            'watchStatusOlderDays' => ['nullable', 'string', 'max:4'],
            'videoAvailable' => ['boolean'],
        ], [
            'name.*' => __('collections.validation.name'),
            'description.*' => __('collections.validation.description'),
            'visibility.*' => __('collections.validation.visibility'),
            'sortMode.*' => __('collections.validation.sort'),
            'categoryRootPublicId.*' => __('collections.validation.category'),
            'categoryPublicId.*' => __('collections.validation.category'),
            'seoTitle.*' => __('collections.validation.seo_title'),
            'seoDescription.*' => __('collections.validation.seo_description'),
        ]);
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $smartRules = $collection->isSmart() ? $this->smartRules() : null;
        $updated = $service->update($this->user(), $collection, new CatalogCollectionData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            visibility: $collection->isSmart()
                ? CatalogCollectionVisibility::Private
                : CatalogCollectionVisibility::from($validated['visibility']),
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
            categoryPublicId: $collection->isSmart() ? null : $this->selectedCategoryPublicId(),
            mode: $collection->mode,
            smartRules: $smartRules,
        ), $this->contentVersion);
        $this->fillCollection($updated);
        $this->status = __('collections.status.updated');
    }

    public function applySmartPreset(string $preset): void
    {
        $preset = CatalogSmartCollectionPreset::tryFrom($preset);

        if (! $preset instanceof CatalogSmartCollectionPreset) {
            $this->addError('smartPreset', __('collections.smart.validation.preset'));

            return;
        }

        $this->fillSmartRules($preset->rules());
        $this->resetValidation();
    }

    public function resetSmartRules(CatalogCollectionResolver $resolver): void
    {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        Gate::authorize('update', $collection);
        $rules = $collection->smartRules();
        abort_unless($collection->isSmart() && $rules instanceof CatalogSmartCollectionRules, 404);
        $this->fillSmartRules($rules);
        $this->resetValidation();
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

    public function sortItem(
        int $itemId,
        int $position,
        CatalogCollectionResolver $resolver,
        CatalogCollectionItemService $items,
    ): void {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $windowStart = (max(1, $this->getPage('collectionPage')) - 1) * self::ITEMS_PER_PAGE;
        $changed = $items->moveWithinWindow(
            $this->user(),
            $collection,
            $itemId,
            targetIndex: $windowStart + $position,
            windowStart: $windowStart,
            windowSize: self::ITEMS_PER_PAGE,
        );

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
        CatalogCollectionCategoryQuery $categories,
        CatalogSmartCollectionOptionsQuery $smartOptions,
    ): View {
        $collection = $query->summary($resolver->byPublicId($this->collectionPublicId));
        Gate::authorize('update', $collection);
        $user = $this->user();
        $items = $query->items($collection, $user, new CatalogCollectionItemCriteria(
            sort: $collection->isSmart() ? $collection->sort_mode : CatalogCollectionSort::Manual,
            perPage: self::ITEMS_PER_PAGE,
        ));
        $totalItems = $collection->isSmart()
            ? $items->total()
            : (int) ($collection->total_items_count ?? 0);

        if (! $collection->isSmart()) {
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
        }
        $isEditorial = $collection->type->value === 'editorial';
        $collection->loadMissing([
            'category:id,public_id,parent_id,slug,position,is_active',
            'category.parent:id,public_id,parent_id,slug,position,is_active',
        ]);

        return view('livewire.collections.catalog-collection-editor', [
            'collection' => $collection,
            'items' => $items,
            'unavailableItems' => $query->unavailableItems($collection, $user),
            'collectionTypeLabel' => $collection->type->label(),
            'collectionVisibilityLabel' => $collection->visibility->label(),
            'collectionModerationLabel' => $collection->moderation_status->label(),
            'isEditorial' => $isEditorial,
            'isSmart' => $collection->isSmart(),
            'isPendingModeration' => $collection->moderation_status->value === 'pending',
            'canOpenPublicPage' => $collection->isPubliclyViewable()
                && ($collection->visibility !== CatalogCollectionVisibility::Public
                    || $query->isPubliclyListed($collection)),
            'itemsTitle' => trans_choice('collections.page.items', $totalItems, ['count' => $totalItems]),
            'visibilityOptions' => array_map(static fn (CatalogCollectionVisibility $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionVisibility::cases()),
            'sortOptions' => array_map(static fn (CatalogCollectionSort $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionSort::cases()),
            'smartPresetOptions' => array_map(static fn (CatalogSmartCollectionPreset $preset): array => [
                'value' => $preset->value,
                'label' => $preset->label(),
                'description' => $preset->description(),
            ], CatalogSmartCollectionPreset::cases()),
            'smartCountryOptions' => $collection->isSmart() ? $smartOptions->countries() : collect(),
            'smartGenreOptions' => $collection->isSmart() ? $smartOptions->genres() : collect(),
            'smartActorOptions' => $collection->isSmart() ? $smartOptions->actors($this->actorSearch) : collect(),
            'showSmartActorResults' => $collection->isSmart() && mb_strlen($this->actorSearch) >= 2,
            'smartCompletionOptions' => array_map(static fn (CatalogSmartCollectionCompletion $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogSmartCollectionCompletion::cases()),
            'smartWatchStatusOptions' => array_map(static fn (CatalogWatchStatus $option): array => [
                'value' => $option->value,
                'label' => __("collections.smart.watch_status.{$option->value}"),
            ], CatalogWatchStatus::cases()),
            'smartRuleSummary' => $collection->isSmart() ? $this->smartRuleSummary() : [],
            'smartBooleanOptions' => [
                ['property' => 'inLibrary', 'label' => 'in_library'],
                ['property' => 'unwatched', 'label' => 'unwatched'],
                ['property' => 'hasSubtitles', 'label' => 'has_subtitles'],
                ['property' => 'hasNewEpisodes', 'label' => 'has_new_episodes'],
                ['property' => 'videoAvailable', 'label' => 'video_available'],
            ],
            ...$this->categorySelectionViewData($categories, $collection->category),
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
        $this->mode = $collection->mode->value;
        $this->contentVersion = $collection->content_version;
        $this->fillCategorySelection($collection);

        if ($collection->isSmart() && ($rules = $collection->smartRules()) instanceof CatalogSmartCollectionRules) {
            $this->fillSmartRules($rules);
        }
    }

    private function smartRules(): CatalogSmartCollectionRules
    {
        return CatalogSmartCollectionRules::fromInput([
            'country_slug' => $this->countrySlug,
            'genre_slug' => $this->genreSlug,
            'actor_slug' => $this->actorSlug,
            'imdb_min' => $this->imdbMin,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'completion' => $this->completion,
            'episodes_max' => $this->episodesMax,
            'max_episode_minutes' => $this->maxEpisodeMinutes,
            'in_library' => $this->inLibrary,
            'unwatched' => $this->unwatched,
            'has_subtitles' => $this->hasSubtitles,
            'has_new_episodes' => $this->hasNewEpisodes,
            'watch_status' => $this->watchStatus,
            'watch_status_older_days' => $this->watchStatusOlderDays,
            'video_available' => $this->videoAvailable,
        ]);
    }

    private function fillSmartRules(CatalogSmartCollectionRules $rules): void
    {
        $this->countrySlug = $rules->countrySlug ?? '';
        $this->genreSlug = $rules->genreSlug ?? '';
        $this->actorSlug = $rules->actorSlug ?? '';
        $this->imdbMin = $rules->imdbMin === null ? '' : (string) $rules->imdbMin;
        $this->yearFrom = $rules->yearFrom === null ? '' : (string) $rules->yearFrom;
        $this->yearTo = $rules->yearTo === null ? '' : (string) $rules->yearTo;
        $this->completion = $rules->completion->value ?? '';
        $this->episodesMax = $rules->episodesMax === null ? '' : (string) $rules->episodesMax;
        $this->maxEpisodeMinutes = $rules->maxEpisodeMinutes === null ? '' : (string) $rules->maxEpisodeMinutes;
        $this->inLibrary = $rules->inLibrary;
        $this->unwatched = $rules->unwatched;
        $this->hasSubtitles = $rules->hasSubtitles;
        $this->hasNewEpisodes = $rules->hasNewEpisodes;
        $this->watchStatus = $rules->watchStatus->value ?? '';
        $this->watchStatusOlderDays = $rules->watchStatusOlderDays === null
            ? ''
            : (string) $rules->watchStatusOlderDays;
        $this->videoAvailable = $rules->videoAvailable;
    }

    /** @return list<string> */
    private function smartRuleSummary(): array
    {
        try {
            $rules = $this->smartRules();
        } catch (ValidationException) {
            return [];
        }

        return collect($rules->toArray())
            ->filter(fn (mixed $value): bool => $value !== null && $value !== false && $value !== '')
            ->keys()
            ->map(fn (string $key): string => __("collections.smart.fields.{$key}"))
            ->values()
            ->all();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
