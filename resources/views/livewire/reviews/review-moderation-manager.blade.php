<div class="mx-auto max-w-6xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('reviews.moderation.title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('reviews.moderation.description') }}</p>
    </header>

    @if ($statusMessage || $actionError)
        <div aria-live="polite" class="rounded-control border px-4 py-3 text-sm font-semibold {{ $actionError ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $actionError ?? $statusMessage }}</div>
    @endif

    <p role="status" wire:loading.delay class="rounded-control bg-slate-50 px-4 py-3 text-sm font-bold text-slate-600">{{ __('reviews.actions.loading') }}</p>

    <section aria-labelledby="review-admin-filters" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <h2 id="review-admin-filters" class="text-lg font-black text-slate-900">{{ __('reviews.filters.title') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.moderation.status') }}
                    <select wire:model.live="status" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">{{ __('reviews.filters.all') }}</option>@foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select>
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.moderation.author_filter') }}
                    <input type="search" wire:model.live.debounce.400ms="authorSearch" maxlength="120" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.moderation.target_filter') }}
                    <input type="search" wire:model.live.debounce.400ms="targetSearch" maxlength="160" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="text-xs font-bold text-slate-600">{{ __('reviews.filters.rating') }}
                    <select wire:model.live="ratingFilter" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">{{ __('reviews.filters.all') }}</option>@foreach ($ratingOptions as $rating)<option value="{{ $rating }}">{{ $rating }}</option>@endforeach</select>
                </label>
            </div>
        </div>
        <button type="button" wire:click="clearFilters" class="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-emerald-700"><x-ui.icon name="fa-solid fa-filter-circle-xmark" />{{ __('reviews.filters.clear') }}</button>
    </section>

    @if (! $communityAvailable)
        <div class="rounded-control border border-amber-200 bg-amber-50 px-4 py-5 text-sm text-amber-900">{{ __('reviews.empty.disabled') }}</div>
    @elseif ($queryFailed)
        <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-800">{{ __('reviews.empty.query_failed') }}</div>
    @else
        <section aria-label="{{ __('reviews.moderation.title') }}" class="space-y-5">
            @forelse ($reviews as $adminItem)
                <div class="space-y-3 rounded-panel border border-slate-300 bg-slate-50 p-3 sm:p-4">
                    <x-reviews.review-card :review="$adminItem->review" context="admin" />

                    @if ($adminItem->reports !== [])
                        <div class="rounded-control border border-amber-200 bg-amber-50 p-4">
                            <h3 class="font-black text-amber-950">{{ __('reviews.moderation.reports_summary', ['count' => count($adminItem->reports), 'open' => $adminItem->openReportCount]) }}</h3>
                            <div class="mt-3 space-y-3">
                                @foreach ($adminItem->reports as $report)
                                    <article wire:key="review-report-{{ $report['id'] }}" class="rounded-control bg-white p-3 text-sm text-slate-700">
                                        <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $report['category_label'] }}</strong><span class="text-xs text-slate-500">{{ $report['status_label'] }} · {{ $report['resolved_at'] ?? $report['created_at'] }}</span></div>
                                        @if ($report['details'])<p class="mt-2 whitespace-pre-line break-words leading-6">{{ $report['details'] }}</p>@endif
                                        @if ($report['private_note'])<p class="mt-2 rounded-control bg-slate-100 px-3 py-2 text-xs text-slate-600"><strong>{{ __('reviews.moderation.private_note') }}:</strong> {{ $report['private_note'] }}</p>@endif
                                        @if ($report['status'] === 'open')
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <button type="button" wire:click="resolveReport({{ $report['id'] }}, {{ $adminItem->review->id }}, 'resolved')" wire:confirm="{{ __('reviews.reports.resolve_confirm') }}" wire:loading.attr="disabled" wire:target="resolveReport({{ $report['id'] }}, {{ $adminItem->review->id }}, 'resolved')" class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-60">{{ __('reviews.reports.statuses.resolved') }}</button>
                                                <button type="button" wire:click="resolveReport({{ $report['id'] }}, {{ $adminItem->review->id }}, 'dismissed')" wire:confirm="{{ __('reviews.reports.dismiss_confirm') }}" wire:loading.attr="disabled" wire:target="resolveReport({{ $report['id'] }}, {{ $adminItem->review->id }}, 'dismissed')" class="inline-flex min-h-11 items-center rounded-control bg-slate-200 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-60">{{ __('reviews.reports.statuses.dismissed') }}</button>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form wire:submit="moderateReview({{ $adminItem->review->id }})" class="grid gap-3 rounded-control border border-slate-200 bg-white p-4 lg:grid-cols-2">
                        <label class="text-sm font-bold text-slate-700">{{ __('reviews.moderation.status') }}
                            <select wire:model="moderationStatuses.{{ $adminItem->review->id }}" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5">@foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select>
                        </label>
                        <label class="text-sm font-bold text-slate-700">{{ __('reviews.moderation.reason') }}
                            <select wire:model="moderationReasons.{{ $adminItem->review->id }}" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5">@foreach ($moderationReasonOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select>
                        </label>
                        <label class="lg:col-span-2 text-sm font-bold text-slate-700">{{ __('reviews.moderation.private_note') }}
                            <textarea wire:model="moderationNotes.{{ $adminItem->review->id }}" maxlength="2000" rows="3" class="mt-1 w-full rounded-control border border-slate-300 px-3 py-2.5"></textarea>
                            <span class="mt-1 block text-xs font-normal text-slate-500">{{ __('reviews.moderation.private_note_hint') }}</span>
                        </label>
                        <label class="flex min-h-11 items-center gap-3 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="spoilerFlags.{{ $adminItem->review->id }}" class="h-5 w-5 rounded border-slate-300 text-violet-700">{{ __('reviews.composer.spoiler') }}</label>
                        <button type="submit" wire:confirm="{{ __('reviews.moderation.confirm') }}" wire:loading.attr="disabled" wire:target="moderateReview({{ $adminItem->review->id }})" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60">{{ __('reviews.moderation.apply') }}</button>
                    </form>

                    @if ($adminItem->authorUserId !== null)
                        @if ($adminItem->activeRestriction)
                            <div class="flex flex-col gap-3 rounded-control border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 sm:flex-row sm:items-center sm:justify-between">
                                <p><strong>{{ $adminItem->activeRestriction['type'] }}</strong> · {{ $adminItem->activeRestriction['reason'] }} @if ($adminItem->activeRestriction['expires_at']) · {{ $adminItem->activeRestriction['expires_at'] }} @endif</p>
                                <button type="button" wire:click="revokeRestriction({{ $adminItem->activeRestriction['id'] }})" wire:confirm="{{ __('reviews.restrictions.revoke_confirm') }}" wire:loading.attr="disabled" wire:target="revokeRestriction({{ $adminItem->activeRestriction['id'] }})" class="inline-flex min-h-11 items-center justify-center rounded-control bg-white px-4 py-2.5 font-bold text-rose-700 disabled:opacity-60">{{ __('reviews.restrictions.revoke') }}</button>
                            </div>
                        @else
                            <form wire:submit="restrictReviewer({{ $adminItem->review->id }}, {{ $adminItem->authorUserId }})" class="grid gap-3 rounded-control border border-rose-200 bg-rose-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                <label class="text-sm font-bold text-rose-900">{{ __('reviews.restrictions.type') }}<select wire:model="restrictionTypes.{{ $adminItem->review->id }}" class="mt-1 min-h-11 w-full rounded-control border border-rose-200 bg-white px-3 py-2">@foreach ($restrictionTypeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-rose-900">{{ __('reviews.restrictions.reason') }}<select wire:model="restrictionReasons.{{ $adminItem->review->id }}" class="mt-1 min-h-11 w-full rounded-control border border-rose-200 bg-white px-3 py-2">@foreach ($restrictionReasonOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-rose-900">{{ __('reviews.restrictions.duration_days') }}<input type="number" wire:model="restrictionDurations.{{ $adminItem->review->id }}" min="1" max="365" class="mt-1 min-h-11 w-full rounded-control border border-rose-200 bg-white px-3 py-2"></label>
                                <button type="submit" wire:confirm="{{ __('reviews.restrictions.apply_confirm') }}" wire:loading.attr="disabled" wire:target="restrictReviewer({{ $adminItem->review->id }}, {{ $adminItem->authorUserId }})" class="min-h-11 self-end rounded-control bg-rose-700 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ __('reviews.restrictions.apply') }}</button>
                                <label class="sm:col-span-2 lg:col-span-4 text-sm font-bold text-rose-900">{{ __('reviews.moderation.private_note') }}<textarea wire:model="restrictionNotes.{{ $adminItem->review->id }}" maxlength="2000" rows="2" class="mt-1 w-full rounded-control border border-rose-200 bg-white px-3 py-2"></textarea></label>
                            </form>
                        @endif
                    @endif
                </div>
            @empty
                <div class="rounded-control bg-slate-50 px-4 py-8 text-center font-bold text-slate-700">{{ __('reviews.moderation.empty') }}</div>
            @endforelse
        </section>

        @if ($reviews->hasPages())<div class="pt-2">{{ $reviews->links() }}</div>@endif
    @endif
</div>
