<div class="mx-auto max-w-7xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-rose-50 text-rose-700"><x-ui.icon name="fa-solid fa-shield-halved" /></span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('comments.admin.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('comments.admin.description') }}</p>
            </div>
        </div>
    </header>

    <div aria-live="polite" aria-atomic="true">
        @if ($notice !== null)
            <div class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status"><x-ui.icon name="fa-solid fa-circle-check" /> {{ $notice }}</div>
        @endif
        @if ($actionError !== null)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert"><x-ui.icon name="fa-solid fa-circle-exclamation" /> {{ $actionError }}</div>
        @endif
    </div>

    @if (! $available)
        <div class="rounded-panel border border-slate-200 bg-white p-6 text-center shadow-panel">
            <p class="text-sm font-semibold text-slate-600">{{ __('comments.states.comments_disabled') }}</p>
        </div>
    @elseif ($queryFailed)
        <div class="rounded-panel border border-rose-200 bg-white p-6 text-center shadow-panel" role="alert">
            <x-ui.icon name="fa-solid fa-triangle-exclamation text-2xl text-rose-600" />
            <p class="mt-3 text-sm font-semibold text-slate-700">{{ __('comments.states.query_failed') }}</p>
        </div>
    @else
        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" aria-labelledby="comment-moderation-filters">
            <h2 id="comment-moderation-filters" class="text-lg font-black text-slate-800">{{ __('comments.admin.queue') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <label for="comment-admin-status" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.status_filter') }}</label>
                    <select id="comment-admin-status" wire:model.live="status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="attention">{{ __('comments.admin.queue') }}</option>
                        <option value="">{{ __('comments.admin.all') }}</option>
                        @foreach ($statusOptions as $statusOption)
                            <option value="{{ $statusOption['value'] }}">{{ $statusOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="comment-admin-target" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.target_filter') }}</label>
                    <select id="comment-admin-target" wire:model.live="target" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="">{{ __('comments.admin.all') }}</option>
                        @foreach ($targetOptions as $targetOption)
                            <option value="{{ $targetOption['value'] }}">{{ $targetOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="comment-admin-author" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.user_filter') }}</label>
                    <input id="comment-admin-author" type="search" wire:model.live.debounce.400ms="author" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                </div>
            </div>
            <button type="button" wire:click="clearFilters" wire:loading.attr="disabled" class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-4 text-sm font-bold text-slate-700 hover:bg-slate-100"><x-ui.icon name="fa-solid fa-xmark" />{{ __('comments.admin.all') }}</button>
        </section>

        <div wire:loading.delay.flex wire:target="status,target,author,clearFilters,gotoPage,previousPage,nextPage" class="items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700" role="status"><x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('comments.loading.comments') }}</div>

        @if ($comments->isEmpty())
            <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
                <p class="text-sm font-semibold text-slate-600">{{ __('comments.admin.empty') }}</p>
            </div>
        @else
            <ol class="space-y-4">
                @foreach ($comments as $comment)
                    <li wire:key="moderation-comment-{{ $comment->id }}" class="min-w-0 rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                        <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <x-ui.status-pill variant="warning">{{ $comment->statusLabel }}</x-ui.status-pill>
                                    <x-ui.status-pill variant="muted">{{ $comment->targetLabel }}</x-ui.status-pill>
                                    @if ($comment->isSpoiler)<x-ui.status-pill variant="warning"><x-ui.icon name="fa-solid fa-triangle-exclamation" />{{ __('comments.spoiler.label') }}</x-ui.status-pill>@endif
                                    @if ($comment->isDeleted)<x-ui.status-pill variant="warning">{{ __('comments.states.deleted_comment') }}</x-ui.status-pill>@endif
                                </div>
                                <p class="mt-3 break-words text-sm font-black text-slate-800">{{ $comment->authorName }}</p>
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-7 text-slate-700 [overflow-wrap:anywhere]">{{ $comment->body }}</p>
                                <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
                                    <time datetime="{{ $comment->createdAtIso }}">{{ $comment->createdAtLabel }}</time>
                                    @if ($comment->editedAtLabel !== null)<span>{{ __('comments.states.edited', ['time' => $comment->editedAtLabel]) }}</span>@endif
                                    <span>{{ trans_choice('comments.reply_count', $comment->replyCount, ['count' => $comment->replyCount]) }}</span>
                                    <span>{{ trans_choice('comments.admin.reports', $comment->reportCount, ['count' => $comment->reportCount]) }}</span>
                                    @if ($comment->moderationReasonLabel !== null)<span>{{ $comment->moderationReasonLabel }}</span>@endif
                                </div>
                                @if ($comment->privateNote !== null)
                                    <div class="mt-3 rounded-control border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-900"><x-ui.icon name="fa-solid fa-lock" /> {{ $comment->privateNote }}</div>
                                @endif
                                @if ($comment->activeRestriction !== null)
                                    <div class="mt-3 flex min-w-0 flex-wrap items-center gap-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                                        <x-ui.icon name="fa-solid fa-user-lock" />
                                        <span>{{ $comment->activeRestriction->typeLabel }} · {{ $comment->activeRestriction->reasonLabel }}@if ($comment->activeRestriction->expiresAtLabel !== null) · {{ $comment->activeRestriction->expiresAtLabel }}@endif</span>
                                        <button type="button" wire:click="revokeRestriction({{ $comment->activeRestriction->id }})" wire:loading.attr="disabled" class="ml-auto inline-flex min-h-10 items-center gap-2 rounded-control bg-white px-3 text-xs font-bold text-amber-900 hover:bg-amber-100"><x-ui.icon name="fa-solid fa-unlock" />{{ __('comments.admin.revoke') }}</button>
                                    </div>
                                @endif
                            </div>
                            <div class="flex w-full flex-wrap gap-2 lg:w-auto lg:max-w-64">
                                <a href="{{ $comment->directUrl }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-slate-100"><x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />{{ __('comments.admin.open_context') }}</a>
                                <button type="button" data-comment-report-trigger wire:click="openModeration({{ $comment->id }})" wire:loading.attr="disabled" aria-haspopup="dialog" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-700 px-3 text-sm font-bold text-white hover:bg-rose-600"><x-ui.icon name="fa-solid fa-gavel" />{{ __('comments.admin.moderate') }}</button>
                            </div>
                        </div>

                        @if ($comment->reports !== [])
                            <section class="mt-4 border-t border-slate-200 pt-4" aria-label="{{ trans_choice('comments.admin.reports', $comment->reportCount, ['count' => $comment->reportCount]) }}">
                                <ul class="space-y-2">
                                    @foreach ($comment->reports as $report)
                                        <li wire:key="moderation-report-{{ $report->id }}" class="rounded-control bg-slate-50 p-3">
                                            <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-500"><x-ui.status-pill variant="warning">{{ $report->categoryLabel }}</x-ui.status-pill><span>{{ $report->statusLabel }}</span><span>{{ $report->createdAtLabel }}</span></div>
                                            @if ($report->details !== null)<p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-700">{{ $report->details }}</p>@endif
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <button type="button" wire:click="resolveReport({{ $report->id }}, '{{ $resolvedReportStatus }}')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-100 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('comments.admin.resolve_report') }}</button>
                                                <button type="button" wire:click="resolveReport({{ $report->id }}, '{{ $dismissedReportStatus }}')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-xs font-bold text-slate-700 hover:bg-slate-200 sm:flex-none"><x-ui.icon name="fa-solid fa-xmark" />{{ __('comments.admin.dismiss_report') }}</button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($comments->hasPages())
                <nav aria-label="{{ __('comments.accessibility.pagination') }}">{{ $comments->links() }}</nav>
            @endif
        @endif
    @endif

    @if (! $queryFailed && $selectedCommentId !== null)
        <dialog data-comment-dialog data-comment-dialog-open class="max-h-[calc(100vh-2rem)] w-[min(48rem,calc(100%-2rem))] overflow-y-auto rounded-panel border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="comment-moderation-dialog-title">
            <div class="p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="comment-moderation-dialog-title" class="text-xl font-black text-slate-800">{{ __('comments.admin.title') }}</h2>
                        <p class="mt-2 text-sm text-slate-600">{{ __('comments.admin.description') }}</p>
                    </div>
                    <button type="button" data-comment-dialog-close wire:click="closeModeration" class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('comments.accessibility.close_dialog') }}"><x-ui.icon name="fa-solid fa-xmark" /></button>
                </div>

                @if ($selectedThread !== null)
                    <section class="mt-5 rounded-control border border-slate-200 bg-slate-50 p-3 sm:p-4" aria-labelledby="comment-moderation-context-title">
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                            <h3 id="comment-moderation-context-title" class="text-base font-black text-slate-800">{{ __('comments.admin.thread_context') }}</h3>
                            <span class="text-xs font-bold text-slate-500">{{ trans_choice('comments.reply_count', $selectedThread->replyCount, ['count' => $selectedThread->replyCount]) }}</span>
                        </div>
                        <ol class="mt-3 max-h-80 space-y-2 overflow-y-auto" aria-label="{{ __('comments.admin.thread_context') }}">
                            @foreach ($selectedThread->items as $contextComment)
                                <li @class([
                                    'min-w-0 rounded-control border bg-white p-3',
                                    'border-emerald-400 ring-2 ring-emerald-100' => $contextComment->isSelected,
                                    'border-slate-200' => ! $contextComment->isSelected,
                                ])>
                                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                        <span class="break-words text-slate-700">{{ $contextComment->authorName }}</span>
                                        <x-ui.status-pill variant="muted">{{ $contextComment->statusLabel }}</x-ui.status-pill>
                                        @if ($contextComment->isDeleted)<x-ui.status-pill variant="warning">{{ __('comments.states.deleted_comment') }}</x-ui.status-pill>@endif
                                        <time datetime="{{ $contextComment->createdAtIso }}">{{ $contextComment->createdAtLabel }}</time>
                                    </div>
                                    <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-700 [overflow-wrap:anywhere]">{{ $contextComment->body }}</p>
                                </li>
                            @endforeach
                        </ol>
                        @if ($selectedThread->hasMore)
                            <p class="mt-3 text-xs font-semibold text-slate-500">{{ __('comments.admin.context_limited') }}</p>
                        @endif
                    </section>
                @endif

                <form wire:submit="saveModeration" class="mt-5 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="comment-moderation-status" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.status_filter') }}</label>
                            <select id="comment-moderation-status" wire:model="moderationStatus" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                @foreach ($statusOptions as $statusOption)<option value="{{ $statusOption['value'] }}">{{ $statusOption['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="comment-moderation-reason" class="block text-sm font-bold text-slate-700">{{ __('comments.reports.category') }}</label>
                            <select id="comment-moderation-reason" wire:model="moderationReason" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                @foreach ($moderationReasons as $reason)<option value="{{ $reason['value'] }}">{{ $reason['label'] }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="comment-moderator-note" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.private_note') }}</label>
                        <textarea id="comment-moderator-note" wire:model="privateNote" rows="4" maxlength="2000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-base leading-6 text-slate-800"></textarea>
                    </div>
                    <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="resolveOpenReports" class="h-4 w-4 rounded border-slate-300 text-emerald-700"><span>{{ __('comments.admin.resolve_report') }}</span></label>
                    <button type="submit" wire:confirm="{{ __('comments.confirmations.moderate') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-4 text-sm font-bold text-white hover:bg-rose-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-gavel" />{{ __('comments.admin.moderate') }}</button>
                </form>

                <form wire:submit="applyRestriction" class="mt-6 space-y-4 border-t border-slate-200 pt-5">
                    <h3 class="text-lg font-black text-slate-800">{{ __('comments.admin.restrict') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="comment-restriction-type" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.restrict') }}</label>
                            <select id="comment-restriction-type" wire:model.live="restrictionType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                @foreach ($restrictionTypes as $type)<option value="{{ $type['value'] }}">{{ $type['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="comment-restriction-reason" class="block text-sm font-bold text-slate-700">{{ __('comments.reports.category') }}</label>
                            <select id="comment-restriction-reason" wire:model="restrictionReason" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                @foreach ($restrictionReasons as $reason)<option value="{{ $reason['value'] }}">{{ $reason['label'] }}</option>@endforeach
                            </select>
                        </div>
                        @if ($restrictionType === 'temporary')
                            <div>
                                <label for="comment-restriction-duration" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.duration') }}</label>
                                <select id="comment-restriction-duration" wire:model="restrictionDuration" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                    @foreach ($restrictionDurations as $duration)<option value="{{ $duration }}">{{ __('comments.admin.days', ['count' => $duration]) }}</option>@endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    <div>
                        <label for="comment-restriction-note" class="block text-sm font-bold text-slate-700">{{ __('comments.admin.private_note') }}</label>
                        <textarea id="comment-restriction-note" wire:model="restrictionNote" rows="3" maxlength="2000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-base leading-6 text-slate-800"></textarea>
                    </div>
                    <button type="submit" wire:confirm="{{ __('comments.confirmations.restrict') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-amber-900 px-4 text-sm font-bold text-white hover:bg-amber-800 disabled:opacity-60"><x-ui.icon name="fa-solid fa-user-lock" />{{ __('comments.admin.restrict') }}</button>
                </form>
            </div>
        </dialog>
    @endif
</div>
