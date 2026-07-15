<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\DTOs\CatalogCollectionItemCriteria;
use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCoverService;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionReportService;
use App\Services\Collections\CatalogCollectionResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionPage extends Component
{
    use InteractsWithCollectionLocale;
    use WithPagination;

    #[Locked]
    public string $collectionPublicId = '';

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(history: true, except: '')]
    public string $genre = '';

    #[Url(history: true, except: '')]
    public string $country = '';

    #[Url(as: 'status', history: true, except: '')]
    public string $statusFilter = '';

    #[Url(history: true, except: '')]
    public string $year = '';

    #[Url(history: true, except: '')]
    public string $sort = '';

    public bool $showReport = false;

    public string $reportReason = 'spam';

    public string $reportDetails = '';

    public ?string $notice = null;

    public function openReport(): void
    {
        $this->showReport = true;
        $this->dispatch('collection-selector-opened');
    }

    public function closeReport(): void
    {
        $this->showReport = false;
        $this->resetValidation();
        $this->dispatch('collection-selector-closed');
    }

    public function mount(string $collectionPublicId, CatalogCollectionResolver $resolver, string $interfaceLocale = 'ru'): void
    {
        $this->setCollectionLocale($interfaceLocale);
        $this->collectionPublicId = $collectionPublicId;
        $collection = $resolver->byPublicId($collectionPublicId);
        Gate::authorize('view', $collection);

        if ($this->sort === '') {
            $this->sort = $collection->sort_mode->value;
        }

        $this->normalizeFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'genre', 'country', 'statusFilter', 'year', 'sort'], true)) {
            $this->normalizeFilters();
            $this->resetPage(pageName: 'collectionPage');
        }
    }

    public function applyFilters(): void
    {
        $this->normalizeFilters();
        $this->resetPage(pageName: 'collectionPage');
    }

    public function resetFilters(CatalogCollectionResolver $resolver): void
    {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        Gate::authorize('view', $collection);
        $this->reset(['search', 'genre', 'country', 'statusFilter', 'year']);
        $this->sort = $collection->sort_mode->value;
        $this->resetPage(pageName: 'collectionPage');
    }

    public function submitReport(
        CatalogCollectionResolver $resolver,
        CatalogCollectionReportService $reports,
    ): void {
        $user = $this->user();
        abort_unless($user instanceof User, 403);
        $validated = $this->validate([
            'reportReason' => ['required', Rule::enum(CatalogCollectionReportReason::class)],
            'reportDetails' => ['nullable', 'string', 'max:2000'],
        ], [
            'reportReason.*' => __('collections.reports.reason'),
            'reportDetails.*' => __('collections.validation.report_details'),
        ]);
        $created = $reports->submit(
            $user,
            $resolver->byPublicId($this->collectionPublicId),
            CatalogCollectionReportReason::from($validated['reportReason']),
            $validated['reportDetails'] !== '' ? $validated['reportDetails'] : null,
        );
        $this->notice = $created
            ? __('collections.reports.submitted')
            : __('collections.reports.duplicate');
        $this->showReport = false;
        $this->reportDetails = '';
        $this->dispatch('collection-selector-closed');
    }

    public function removeItem(
        int $catalogTitleId,
        CatalogCollectionResolver $resolver,
        CatalogCollectionItemService $items,
    ): void {
        $collection = $resolver->byPublicId($this->collectionPublicId);
        $user = $this->viewer();
        abort_unless($user instanceof User, 403);
        $items->remove($user, $collection, $catalogTitleId);
        $this->notice = __('collections.membership.removed');
        $this->resetPage(pageName: 'collectionPage');
    }

    public function render(
        CatalogCollectionResolver $resolver,
        CatalogCollectionQuery $query,
        CatalogCollectionCoverService $covers,
    ): View {
        $collection = $query->summary($resolver->byPublicId($this->collectionPublicId));
        Gate::authorize('view', $collection);
        $viewer = $this->viewer();
        $canManage = $viewer instanceof User && Gate::forUser($viewer)->allows('update', $collection);
        $itemViewer = $viewer;
        $criteria = new CatalogCollectionItemCriteria(
            search: $this->search,
            genre: $this->genre !== '' ? $this->genre : null,
            country: $this->country !== '' ? $this->country : null,
            status: $this->statusFilter !== '' ? $this->statusFilter : null,
            year: ctype_digit($this->year) ? (int) $this->year : null,
            sort: CatalogCollectionSort::from($this->sort),
            perPage: 24,
        );
        $items = $query->items($collection, $itemViewer, $criteria);
        $queryState = array_filter([
            'q' => $this->search,
            'genre' => $this->genre,
            'country' => $this->country,
            'status' => $this->statusFilter,
            'year' => $this->year,
            'sort' => $this->sort !== $collection->sort_mode->value ? $this->sort : null,
            'collectionPage' => $items->currentPage() > 1 ? $items->currentPage() : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
        $usesLocalizedEditorialCanonical = $collection->type->value === 'editorial'
            && $this->interfaceLocale !== $defaultLocale
            && $collection->translations->contains('locale', $this->interfaceLocale);
        $headerItemCount = (int) ($canManage
            ? ($collection->total_items_count ?? 0)
            : ($collection->visible_items_count ?? 0));
        [$visibilityIcon, $visibilityNotice] = match ($collection->visibility) {
            CatalogCollectionVisibility::Private => ['fa-solid fa-lock text-slate-400', __('collections.page.private_notice')],
            CatalogCollectionVisibility::Unlisted => ['fa-solid fa-link text-slate-400', __('collections.page.unlisted_notice')],
            CatalogCollectionVisibility::Public => ['fa-solid fa-earth-europe text-slate-400', __('collections.page.public_notice')],
        };
        $ownerPublicId = $collection->owner?->getAttribute('public_id');

        return view('livewire.collections.catalog-collection-page', [
            'collection' => $collection,
            'items' => $items,
            'filterOptions' => $query->filterOptions($collection, $itemViewer),
            'collectionTypeLabel' => $collection->type->label(),
            'collectionVisibilityLabel' => $collection->visibility->label(),
            'collectionModerationLabel' => $collection->moderation_status->label(),
            'collectionSortLabel' => $collection->sort_mode->label(),
            'collectionUpdatedAtIso' => $collection->updated_at?->toAtomString(),
            'collectionUpdatedAtLabel' => $collection->updated_at?->format('d.m.Y'),
            'isEditorial' => $collection->type->value === 'editorial',
            'canShare' => $collection->visibility !== CatalogCollectionVisibility::Private,
            'headerItemCountLabel' => trans_choice('collections.page.items', $headerItemCount, [
                'count' => $headerItemCount,
            ]),
            'itemResultTitle' => trans_choice('collections.page.items', $items->total(), [
                'count' => $items->total(),
            ]),
            'visibilityIcon' => $visibilityIcon,
            'visibilityNotice' => $visibilityNotice,
            'ownerUrl' => is_string($ownerPublicId) && $ownerPublicId !== ''
                ? route('profiles.collections', ['userPublicId' => $ownerPublicId])
                : null,
            'sortOptions' => array_map(static fn (CatalogCollectionSort $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
            ], CatalogCollectionSort::cases()),
            'unavailableItems' => $canManage && $collection->isOwnedBy($viewer)
                ? $query->unavailableItems($collection, $viewer)
                : collect(),
            'relatedCollections' => $collection->visibility === CatalogCollectionVisibility::Public
                ? $query->related($collection, $viewer)
                : collect(),
            'coverUrl' => $covers->url($collection) ?? $collection->getAttribute('fallback_poster_url'),
            'canonicalUrl' => $usesLocalizedEditorialCanonical
                ? route('localized.collections.show', [
                    'locale' => $this->interfaceLocale,
                    'collectionSlug' => $collection->slug,
                ])
                : route('collections.show', ['collectionSlug' => $collection->slug]),
            'canManage' => $canManage,
            'canReport' => $viewer instanceof User && Gate::forUser($viewer)->allows('report', $collection),
            'reportReasons' => array_map(static fn (CatalogCollectionReportReason $reason): array => [
                'value' => $reason->value,
                'label' => $reason->label(),
            ], CatalogCollectionReportReason::cases()),
            'hasFilters' => $this->search !== '' || $this->genre !== '' || $this->country !== ''
                || $this->statusFilter !== '' || $this->year !== '' || $this->sort !== $collection->sort_mode->value,
            'localeUrls' => collect($this->collectionLocales())
                ->mapWithKeys(fn (string $locale): array => [$locale => route('localized.collections.show', [
                    'locale' => $locale,
                    'collectionSlug' => $collection->slug,
                    ...$queryState,
                ])])
                ->all(),
        ]);
    }

    private function normalizeFilters(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');

        foreach (['genre', 'country', 'statusFilter'] as $property) {
            if ($this->{$property} !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $this->{$property}) !== 1) {
                $this->{$property} = '';
            }
        }

        if ($this->year !== '' && (! ctype_digit($this->year) || (int) $this->year < 1900 || (int) $this->year > now()->year + 5)) {
            $this->year = '';
        }

        if (CatalogCollectionSort::tryFrom($this->sort) === null) {
            $this->sort = CatalogCollectionSort::Manual->value;
        }
    }

    private function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function user(): ?User
    {
        return $this->viewer();
    }
}
