<div class="mx-auto max-w-5xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">{{ __('reviews.scope.title') }}</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('reviews.profile.title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('reviews.profile.description') }}</p>
    </header>

    @if ($statusMessage)
        <div aria-live="polite" class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ $statusMessage }}</div>
    @endif

    @if ($actionError)
        <div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ $actionError }}</div>
    @endif

    @if ($notificationsAvailable)
        <form wire:submit="savePreferences" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
            <h2 class="text-lg font-black text-slate-900">{{ __('reviews.profile.preferences') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="helpfulNotifications" class="h-5 w-5 rounded border-slate-300 text-emerald-700">{{ __('reviews.profile.helpful_notifications') }}</label>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="moderationNotifications" class="h-5 w-5 rounded border-slate-300 text-emerald-700">{{ __('reviews.profile.moderation_notifications') }}</label>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="reportNotifications" class="h-5 w-5 rounded border-slate-300 text-emerald-700">{{ __('reviews.profile.report_notifications') }}</label>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="savePreferences" class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-floppy-disk" />{{ __('reviews.profile.save_preferences') }}</button>
        </form>
    @endif

    <section aria-labelledby="review-history-list-title" class="space-y-4">
        <p role="status" wire:loading.delay wire:target="sort,status,gotoPage,nextPage,previousPage,revealReview,hideReview" class="rounded-control bg-slate-50 px-4 py-3 text-sm font-bold text-slate-600">{{ __('reviews.actions.loading') }}</p>
        <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <h2 id="review-history-list-title" class="text-lg font-black text-slate-900">{{ __('reviews.section.label') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.sort') }}
                        <select wire:model.live="sort" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                            @foreach ($sortOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs font-bold text-slate-600">{{ __('reviews.profile.status_filter') }}
                        <select wire:model.live="status" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="">{{ __('reviews.filters.all') }}</option>
                            @foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </select>
                    </label>
                </div>
            </div>
        </div>

        @if ($queryFailed)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-800">{{ __('reviews.empty.query_failed') }}</div>
        @elseif (! $communityAvailable)
            <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-5 text-sm text-amber-900">{{ __('reviews.empty.disabled') }}</div>
        @else
            @forelse ($reviews as $review)
                <x-reviews.review-card :review="$review" context="profile" />
            @empty
                <div class="rounded-control bg-slate-50 px-4 py-8 text-center font-bold text-slate-700">{{ __('reviews.profile.empty') }}</div>
            @endforelse
        @endif

        @if ($reviews->hasPages())
            {{ $reviews->links() }}
        @endif
    </section>
</div>
