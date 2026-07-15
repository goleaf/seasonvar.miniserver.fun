@props(['review', 'reportingReviewId' => null, 'reportCategories' => [], 'context' => 'title'])

<article
    id="review-{{ $review->id }}"
    wire:key="review-{{ $review->id }}-v{{ $review->version }}"
    tabindex="-1"
    class="scroll-mt-24 rounded-panel border bg-white p-4 shadow-panel outline-none transition motion-reduce:transition-none focus-visible:ring-2 focus-visible:ring-emerald-500 sm:p-5 {{ $review->isHighlighted ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-200' }}"
>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span aria-hidden="true" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-sm font-black text-slate-700">{{ $review->authorInitial }}</span>
            <div class="min-w-0">
                <p class="break-words font-black text-slate-900">{{ $review->authorName }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
                    <span>{{ $review->publishedLabel }}</span>
                    <span>{{ $review->scopeLabel }}</span>
                    @if ($review->isEdited)<span>{{ __('reviews.dates.edited') }}</span>@endif
                </div>
                @if ($context === 'profile' && $review->targetUrl !== null && $review->targetTitle !== null)
                    <a href="{{ $review->targetUrl }}#reviews" class="mt-2 inline-flex min-h-9 items-center gap-2 break-words text-sm font-bold text-emerald-700 hover:text-emerald-600"><x-ui.icon name="fa-solid fa-clapperboard" />{{ $review->targetTitle }}</a>
                @elseif ($context === 'admin' && $review->targetTitle !== null)
                    <p class="mt-2 inline-flex items-center gap-2 break-words text-sm font-bold text-slate-700"><x-ui.icon name="fa-solid fa-clapperboard" />{{ $review->targetTitle }}</p>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($review->rating !== null)
                <span aria-label="{{ __('reviews.filters.rating_value', ['rating' => $review->rating, 'maximum' => $review->ratingMaximum]) }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-amber-50 px-3 text-xs font-black text-amber-800"><x-ui.icon name="fa-solid fa-star" />{{ $review->rating }}/{{ $review->ratingMaximum }}</span>
            @endif
            @if ($review->isVerifiedWatching)
                <span title="{{ __('reviews.verified.hint') }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-emerald-50 px-3 text-xs font-black text-emerald-800"><x-ui.icon name="fa-solid fa-circle-check" />{{ __('reviews.verified.label') }}</span>
            @endif
            @if ($review->isSpoiler)
                <span class="inline-flex min-h-8 items-center gap-1 rounded-full bg-violet-50 px-3 text-xs font-black text-violet-800"><x-ui.icon name="fa-solid fa-eye-slash" />{{ __('reviews.spoiler.badge') }}</span>
            @endif
        </div>
    </header>

    @if ($review->status !== 'published' || $review->isDeleted)
        <div class="mt-4 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ $review->isDeleted ? __('reviews.empty.deleted') : $review->statusLabel }}</div>
    @endif

    @if (! $review->isDeleted || $context === 'admin')
        @if ($review->title)
            <h3 class="mt-4 break-words text-lg font-black text-slate-900">{{ $review->title }}</h3>
        @endif

        @if ($review->bodyHidden)
            <div id="review-body-{{ $review->id }}" aria-live="polite" class="mt-4 rounded-control border border-violet-200 bg-violet-50 p-4">
                <p class="text-sm font-semibold leading-6 text-violet-900">{{ __('reviews.spoiler.warning') }}</p>
                @if ($review->canReveal)
                    <button id="review-spoiler-toggle-{{ $review->id }}" type="button" wire:click="revealReview({{ $review->id }})" wire:loading.attr="disabled" wire:target="revealReview({{ $review->id }})" aria-controls="review-body-{{ $review->id }}" aria-expanded="false" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-violet-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-violet-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-eye" />{{ __('reviews.spoiler.reveal') }}</button>
                @endif
            </div>
        @elseif ($review->body !== null)
            <div id="review-body-{{ $review->id }}" aria-live="polite" class="mt-4 whitespace-pre-line break-words text-[15px] leading-7 text-slate-700">{{ $review->body }}</div>
            @if ($review->isSpoiler && $context !== 'admin')
                <button id="review-spoiler-toggle-{{ $review->id }}" type="button" wire:click="hideReview({{ $review->id }})" aria-controls="review-body-{{ $review->id }}" aria-expanded="true" class="mt-3 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-violet-700 hover:text-violet-600"><x-ui.icon name="fa-solid fa-eye-slash" />{{ __('reviews.spoiler.hide') }}</button>
            @endif
        @endif
    @endif

    <footer class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            @if ($review->canVote && $context !== 'admin')
                <button type="button" wire:click="vote({{ $review->id }}, 'helpful')" wire:loading.attr="disabled" wire:target="vote({{ $review->id }}, 'helpful')" aria-pressed="{{ $review->viewerVote === 'helpful' ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold {{ $review->viewerVote === 'helpful' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' }}"><x-ui.icon name="fa-solid fa-thumbs-up" />{{ __('reviews.votes.helpful') }} <span class="tabular-nums">{{ $review->helpfulCount }}</span></button>
                <button type="button" wire:click="vote({{ $review->id }}, 'not_helpful')" wire:loading.attr="disabled" wire:target="vote({{ $review->id }}, 'not_helpful')" aria-pressed="{{ $review->viewerVote === 'not_helpful' ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold {{ $review->viewerVote === 'not_helpful' ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700 hover:bg-rose-50 hover:text-rose-700' }}"><x-ui.icon name="fa-solid fa-thumbs-down" />{{ __('reviews.votes.not_helpful') }} <span class="tabular-nums">{{ $review->notHelpfulCount }}</span></button>
                @if ($review->viewerVote)
                    <button type="button" wire:click="removeVote({{ $review->id }})" wire:loading.attr="disabled" wire:target="removeVote({{ $review->id }})" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600 hover:text-slate-900 disabled:opacity-60">{{ __('reviews.votes.remove') }}</button>
                @endif
            @else
                <span class="inline-flex min-h-9 items-center gap-2 rounded-full bg-slate-100 px-3 text-xs font-bold text-slate-600"><x-ui.icon name="fa-solid fa-thumbs-up" />{{ $review->helpfulCount }}</span>
                <span class="inline-flex min-h-9 items-center gap-2 rounded-full bg-slate-100 px-3 text-xs font-bold text-slate-600"><x-ui.icon name="fa-solid fa-thumbs-down" />{{ $review->notHelpfulCount }}</span>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-bold">
            @if ($review->canEdit && $context === 'title')<button type="button" wire:click="startEdit({{ $review->id }})" class="min-h-11 text-emerald-700 hover:text-emerald-600">{{ __('reviews.actions.edit') }}</button>@endif
            @if ($review->canDelete && $context === 'title')<button type="button" wire:click="deleteReview({{ $review->id }})" wire:confirm="{{ __('reviews.actions.delete_confirm') }}" wire:loading.attr="disabled" wire:target="deleteReview({{ $review->id }})" class="min-h-11 text-rose-700 hover:text-rose-600 disabled:opacity-60">{{ __('reviews.actions.delete') }}</button>@endif
            @if ($review->canRestore && $context === 'title')<button type="button" wire:click="restoreReview({{ $review->id }})" wire:loading.attr="disabled" wire:target="restoreReview({{ $review->id }})" class="min-h-11 text-emerald-700 hover:text-emerald-600 disabled:opacity-60">{{ __('reviews.actions.restore') }}</button>@endif
            @if ($review->canReport && $context === 'title')<button type="button" wire:click="startReport({{ $review->id }})" class="min-h-11 text-slate-600 hover:text-slate-900">{{ __('reviews.actions.report') }}</button>@endif
            @if ($review->canModerate && $context !== 'admin')<a href="{{ route('admin.reviews', ['review' => $review->id]) }}" class="inline-flex min-h-11 items-center text-slate-600 hover:text-slate-900">{{ __('reviews.actions.moderate') }}</a>@endif
            @if ($review->directUrl !== null)<a href="{{ $review->directUrl }}" class="inline-flex min-h-11 items-center text-slate-600 hover:text-emerald-700">{{ __('reviews.actions.direct_link') }}</a>@endif
        </div>
    </footer>

    @if ($reportingReviewId === $review->id && $context === 'title')
        <form wire:submit="submitReport" class="mt-4 space-y-3 rounded-control border border-slate-200 bg-slate-50 p-4">
            <h4 class="font-black text-slate-900">{{ __('reviews.reports.title') }}</h4>
            <label class="block text-sm font-bold text-slate-700">{{ __('reviews.reports.category') }}
                <select id="review-report-category-{{ $review->id }}" wire:model="reportCategory" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base">
                    @foreach ($reportCategories as $category)<option value="{{ $category['value'] }}">{{ $category['label'] }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700">{{ __('reviews.reports.details') }}
                <textarea wire:model="reportDetails" maxlength="1000" rows="3" class="mt-1 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base"></textarea>
                <span class="mt-1 block text-xs font-normal text-slate-500">{{ __('reviews.reports.details_hint') }}</span>
            </label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" wire:loading.attr="disabled" wire:target="submitReport" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">{{ __('reviews.reports.submit') }}</button>
                <button type="button" wire:click="cancelReport" class="inline-flex min-h-11 items-center justify-center px-4 text-sm font-bold text-slate-600">{{ __('reviews.reports.cancel') }}</button>
            </div>
        </form>
    @endif
</article>
