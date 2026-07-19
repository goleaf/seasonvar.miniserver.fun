<section id="reviews" aria-labelledby="reviews-title" class="scroll-mt-24 space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">{{ __('reviews.scope.title') }}</p>
                <h2 id="reviews-title" class="mt-1 text-xl font-black tracking-tight text-slate-900 sm:text-2xl">{{ __('reviews.section.title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('reviews.section.description') }}</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2 text-xs font-bold text-slate-600">
                <span class="rounded-full bg-slate-100 px-3 py-2">{{ trans_choice('reviews.count', $aggregate->publicCount, ['count' => $aggregate->publicCount]) }}</span>
                @if ($aggregateRatingDisplay !== null)
                    <span class="rounded-full bg-amber-50 px-3 py-2 text-amber-800">{{ __('reviews.rating_average', ['rating' => $aggregateRatingDisplay, 'maximum' => $ratingMaximum]) }}</span>
                @endif
            </div>
        </div>
    </header>

    @if ($statusMessage || $actionError)
        <div aria-live="polite" class="rounded-control border px-4 py-3 text-sm font-semibold {{ $actionError ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
            {{ $actionError ?? $statusMessage }}
        </div>
    @endif

    @if ($communityAvailable && $reviewsEnabled && ($canCreate || $editingReviewId !== null))
        <form
            wire:submit="{{ $editingReviewId !== null ? 'saveEdit' : 'createReview' }}"
            data-review-draft
            data-review-draft-key="{{ $draftKey }}"
            class="space-y-4 rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5"
            novalidate
        >
            <div>
                <h3 class="text-lg font-black text-slate-900">{{ $editingReviewId !== null ? __('reviews.composer.edit_title') : __('reviews.composer.title') }}</h3>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('reviews.composer.description') }}</p>
            </div>

            <div>
                <label for="review-title-input" class="text-sm font-bold text-slate-800">{{ __('reviews.composer.review_title') }}</label>
                <input
                    id="review-title-input"
                    type="text"
                    wire:model="reviewTitle"
                    data-review-draft-field="title"
                    minlength="{{ $reviewTitleMinimum }}"
                    maxlength="{{ $reviewTitleMaximum }}"
                    autocomplete="off"
                    class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                    required
                >
                <p class="mt-1 text-xs text-slate-500">{{ __('reviews.composer.review_title_hint') }}</p>
            </div>

            <div>
                <label for="review-body-input" class="text-sm font-bold text-slate-800">{{ __('reviews.composer.body') }}</label>
                <textarea
                    id="review-body-input"
                    wire:model="reviewBody"
                    data-review-draft-field="body"
                    rows="8"
                    minlength="{{ $reviewBodyMinimum }}"
                    maxlength="{{ $reviewBodyMaximum }}"
                    class="mt-2 w-full resize-y rounded-control border border-slate-300 bg-white px-3 py-3 text-base leading-7 text-slate-900 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                    required
                ></textarea>
                <p class="mt-1 text-xs text-slate-500">{{ __('reviews.composer.body_hint', ['minimum' => $reviewBodyMinimum]) }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
                <div>
                    <label for="review-rating-input" class="text-sm font-bold text-slate-800">{{ __('reviews.composer.rating') }}</label>
                    <select id="review-rating-input" wire:model="reviewRating" data-review-draft-field="rating" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="">{{ $emptyRatingLabel }}</option>
                        @foreach ($ratingOptions as $ratingOption)
                            <option value="{{ $ratingOption }}">{{ __('reviews.filters.rating_value', ['rating' => $ratingOption, 'maximum' => $ratingMaximum]) }}</option>
                        @endforeach
                    </select>
                    @if ($currentPortalRating !== null && $editingReviewId === null)
                        <p class="mt-1 text-xs text-slate-500">{{ __('reviews.composer.existing_rating', ['rating' => $currentPortalRating, 'maximum' => $ratingMaximum]) }}</p>
                    @endif
                </div>
                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700">
                    <input type="checkbox" wire:model="reviewSpoiler" data-review-draft-field="spoiler" class="mt-0.5 h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-500">
                    <span><span class="block font-bold text-slate-800">{{ __('reviews.composer.spoiler') }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('reviews.composer.spoiler_hint') }}</span></span>
                </label>
            </div>

            <p class="text-xs text-slate-500">{{ __('reviews.composer.draft_saved') }}</p>

            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" wire:loading.attr="disabled" wire:target="{{ $editingReviewId !== null ? 'saveEdit' : 'createReview' }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-2.5 text-sm font-black text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">
                    <x-ui.icon name="fa-solid fa-pen-to-square" />
                    <span wire:loading.remove wire:target="{{ $editingReviewId !== null ? 'saveEdit' : 'createReview' }}">{{ $editingReviewId !== null ? __('reviews.composer.save') : __('reviews.composer.submit') }}</span>
                    <span wire:loading wire:target="{{ $editingReviewId !== null ? 'saveEdit' : 'createReview' }}">{{ $editingReviewId !== null ? __('reviews.composer.saving') : __('reviews.composer.submitting') }}</span>
                </button>
                @if ($editingReviewId !== null)
                    <button type="button" wire:click="cancelEdit" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('reviews.composer.cancel') }}</button>
                @endif
            </div>
        </form>
    @elseif (! $communityAvailable)
        <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('reviews.empty.disabled') }}</div>
    @elseif (! $reviewsEnabled)
        <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('reviews.empty.disabled') }}</div>
    @elseif (! $isAuthenticated)
        <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
            <p class="text-sm leading-6 text-slate-600">{{ __('reviews.empty.authentication') }}</p>
            <a href="{{ $loginUrl }}" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">{{ __('reviews.actions.login') }}</a>
        </div>
    @elseif ($restrictionMessage !== null)
        <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $restrictionMessage }}
        </div>
    @elseif ($existingReview)
        <div class="rounded-control border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ __('reviews.errors.duplicate_review') }}</div>
    @elseif ($requiresEmailVerification)
        <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('reviews.empty.email_verification') }}</div>
    @endif

    @island(name: 'catalog-title-reviews-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="catalog-title-reviews-results">
    @if ($communityAvailable)
    <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h3 class="font-black text-slate-900">{{ __('reviews.filters.title') }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __('reviews.rating_optional') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.sort') }}
                    <select wire:model.live="sort" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                        @foreach ($sortOptions as $sortOption)<option value="{{ $sortOption['value'] }}">{{ $sortOption['label'] }}</option>@endforeach
                    </select>
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.rating') }}
                    <select wire:model.live="ratingFilter" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                        <option value="">{{ __('reviews.filters.all') }}</option>
                        @foreach ($ratingOptions as $ratingOption)<option value="{{ $ratingOption }}">{{ $ratingOption }}/{{ $ratingMaximum }}</option>@endforeach
                    </select>
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.spoiler') }}
                    <select wire:model.live="spoilerFilter" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                        <option value="">{{ __('reviews.filters.all') }}</option><option value="spoiler_free">{{ __('reviews.filters.spoiler_free') }}</option><option value="contains">{{ __('reviews.filters.contains_spoiler') }}</option>
                    </select>
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.verified') }}
                    <select wire:model.live="verifiedFilter" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                        <option value="">{{ __('reviews.filters.all') }}</option><option value="verified">{{ __('reviews.filters.verified_only') }}</option><option value="unverified">{{ __('reviews.filters.unverified_only') }}</option>
                    </select>
                </label>
            </div>
        </div>
        @if ($ratingFilter !== '' || $spoilerFilter !== '' || $verifiedFilter !== '' || $sort !== 'newest')
            <button type="button" wire:click="clearFilters" class="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-emerald-700 hover:text-emerald-600"><x-ui.icon name="fa-solid fa-filter-circle-xmark" />{{ __('reviews.filters.clear') }}</button>
        @endif
    </div>
    @endif

    <div aria-live="polite" wire:loading.class="opacity-70" wire:target="sort,ratingFilter,spoilerFilter,verifiedFilter,gotoPage,nextPage,previousPage" class="space-y-4 transition-opacity">
        <p role="status" wire:loading.delay wire:target="sort,ratingFilter,spoilerFilter,verifiedFilter,gotoPage,nextPage,previousPage" class="rounded-control bg-slate-50 px-4 py-3 text-sm font-bold text-slate-600">{{ __('reviews.actions.loading') }}</p>
        @if ($queryFailed)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-800">{{ __('reviews.empty.query_failed') }}</div>
        @else
            @forelse ($reviews as $review)
                <x-reviews.review-card :review="$review" :reporting-review-id="$reportingReviewId" :report-categories="$reportCategories" context="title" />
            @empty
                <div class="rounded-control bg-slate-50 px-4 py-8 text-center">
                    <p class="font-bold text-slate-800">{{ ($ratingFilter !== '' || $spoilerFilter !== '' || $verifiedFilter !== '') ? __('reviews.empty.filtered') : __('reviews.empty.default') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('reviews.empty.first') }}</p>
                </div>
            @endforelse
        @endif
    </div>

    @if ($reviews->hasPages())
        <div class="pt-1">{{ $reviews->links(data: ['region' => 'catalog-title-reviews-results']) }}</div>
    @endif
    </x-ui.pagination-region>
    @endisland
</section>
