<section
    id="discussion"
    data-comment-discussion
    class="scroll-mt-28 space-y-4"
    @if ($activeTarget !== null) aria-label="{{ __('comments.accessibility.region', ['target' => $activeTarget->label]) }}" @endif
>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-xl font-black text-slate-800 sm:text-2xl">
                    <x-ui.icon name="fa-solid fa-comments text-emerald-700" />
                    <span>{{ __('comments.title') }}</span>
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $activeTarget?->label ?? __('comments.description') }}</p>
                <p class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ trans_choice('comments.count', $publicCount, ['count' => $publicCount]) }}</p>
            </div>

            @if ($showScopeSelector)
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ __('comments.scope') }}</p>
                    <div class="mt-2 flex min-w-0 flex-wrap gap-2" role="group" aria-label="{{ __('comments.scope') }}">
                        @foreach ($scopeOptions as $scopeOption)
                            <button
                                type="button"
                                wire:key="discussion-scope-{{ $scopeOption->type->value }}-{{ $scopeOption->id }}"
                                wire:click="selectScope('{{ $scopeOption->type->value }}')"
                                wire:loading.attr="disabled"
                                @if ($scopeOption->active) aria-pressed="true" @else aria-pressed="false" @endif
                                @class([
                                    'min-h-11 max-w-full rounded-control px-3 py-2 text-left text-xs font-bold transition',
                                    'bg-emerald-700 text-white' => $scopeOption->active,
                                    'bg-slate-50 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => ! $scopeOption->active,
                                ])
                            >
                                <span class="block break-words">{{ $scopeOption->label }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </header>

    <div role="status" aria-live="polite" aria-atomic="true" aria-label="{{ __('comments.accessibility.status_region') }}">
        @if ($notice !== null)
            <div class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status">
                <span class="inline-flex items-center gap-2"><x-ui.icon name="fa-solid fa-circle-check" />{{ $notice }}</span>
            </div>
        @endif
        @if ($actionError !== null)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert">
                <span class="inline-flex items-center gap-2"><x-ui.icon name="fa-solid fa-circle-exclamation" />{{ $actionError }}</span>
            </div>
        @endif
    </div>

    @if (! $available)
        <div class="rounded-panel border border-slate-200 bg-white p-6 text-center shadow-panel">
            <x-ui.icon name="fa-solid fa-comment-slash text-2xl text-slate-400" />
            <p class="mt-3 text-sm font-semibold text-slate-600">{{ __('comments.states.comments_disabled') }}</p>
        </div>
    @elseif ($queryFailed)
        <div class="rounded-panel border border-rose-200 bg-white p-6 text-center shadow-panel" role="alert">
            <x-ui.icon name="fa-solid fa-triangle-exclamation text-2xl text-rose-600" />
            <p class="mt-3 text-sm font-semibold text-slate-700">{{ __('comments.states.query_failed') }}</p>
        </div>
    @else
        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" aria-labelledby="comment-composer-title">
            <h3 id="comment-composer-title" class="text-base font-black text-slate-800">{{ __('comments.composer.label') }}</h3>
            @if ($canCompose)
                <form wire:submit="publish" class="mt-4 space-y-3">
                    <div data-comment-character-counter>
                        <label for="comment-body-{{ $targetType }}-{{ $targetId }}" class="block text-sm font-bold text-slate-700">{{ __('comments.composer.label') }}</label>
                        <textarea
                            id="comment-body-{{ $targetType }}-{{ $targetId }}"
                            wire:model="body"
                            rows="5"
                            maxlength="{{ $maximumLength }}"
                            placeholder="{{ __('comments.composer.placeholder') }}"
                            class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-base leading-7 text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                        ></textarea>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-slate-500">
                            <span>{{ __('comments.composer.plain_text_hint') }}</span>
                            <span
                                class="tabular-nums"
                                data-comment-character-output
                                data-comment-character-template="{{ __('comments.composer.characters', ['count' => '__COUNT__', 'maximum' => $maximumLength]) }}"
                            >{{ __('comments.composer.characters', ['count' => $bodyLength, 'maximum' => $maximumLength]) }}</span>
                        </div>
                    </div>
                    <label class="flex min-h-11 items-center gap-3 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900">
                        <input type="checkbox" wire:model="isSpoiler" class="h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-600">
                        <span>{{ __('comments.composer.spoiler') }}</span>
                    </label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="publish" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                        <span wire:loading.remove wire:target="publish" class="inline-flex items-center gap-2"><x-ui.icon name="fa-solid fa-paper-plane" />{{ __('comments.composer.publish') }}</span>
                        <span wire:loading wire:target="publish" class="items-center gap-2"><x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('comments.loading.action') }}</span>
                    </button>
                </form>
            @elseif ($restrictionMessage !== null)
                <div class="mt-4 rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" role="status">
                    <span class="inline-flex items-start gap-2"><x-ui.icon name="fa-solid fa-clock" align="start" />{{ $restrictionMessage }}</span>
                </div>
            @elseif (! $isAuthenticated)
                <div class="mt-4 rounded-control bg-slate-50 p-4 text-sm text-slate-600">
                    <p>{{ __('comments.states.authentication_required') }}</p>
                    <a href="{{ route('login') }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 font-bold text-white hover:bg-emerald-600"><x-ui.icon name="fa-solid fa-right-to-bracket" />{{ __('comments.actions.sign_in') }}</a>
                </div>
            @elseif (! $isVerified)
                <div class="mt-4 rounded-control border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                    <p>{{ __('comments.states.verification_required') }}</p>
                    <a href="{{ route('verification.notice') }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-amber-900 px-4 py-2.5 font-bold text-white hover:bg-amber-800"><x-ui.icon name="fa-solid fa-envelope-circle-check" />{{ __('comments.states.verification_required') }}</a>
                </div>
            @endif
        </section>

        <div class="flex flex-col gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h3 class="text-sm font-black text-slate-800">{{ trans_choice('comments.count', $publicCount, ['count' => $publicCount]) }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __('comments.description') }}</p>
            </div>
            <div class="w-full sm:w-56">
                <label for="comment-sort-{{ $targetType }}-{{ $targetId }}" class="block text-xs font-bold text-slate-600">{{ __('comments.sort.label') }}</label>
                <select id="comment-sort-{{ $targetType }}-{{ $targetId }}" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                    @foreach ($sortOptions as $sortOption)
                        <option value="{{ $sortOption['value'] }}">{{ $sortOption['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div wire:loading.delay wire:target="sort,selectScope,syncPlayerTarget,gotoPage,previousPage,nextPage" role="status" aria-live="polite">
            <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
                <x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('comments.loading.comments') }}
            </div>
        </div>

        @if ($comments !== null && $comments->isEmpty())
            <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
                <x-ui.icon name="fa-regular fa-comment-dots text-3xl text-slate-400" />
                <p class="mt-3 text-sm font-semibold text-slate-600">{{ __('comments.states.no_comments') }}</p>
            </div>
        @elseif ($comments !== null)
            <div class="space-y-3" role="feed" aria-busy="false">
                @foreach ($comments as $comment)
                    <x-comments.item
                        wire:key="comment-root-{{ $comment->id }}"
                        :comment="$comment"
                        :thread-expanded="$expandedThreadId === $comment->id"
                        :replies="$expandedThreadId === $comment->id ? $replies : []"
                        :has-more-replies="$expandedThreadId === $comment->id && $hasMoreReplies"
                        :editing-comment-id="$editingCommentId"
                        :reply-to-comment-id="$replyToCommentId"
                        :maximum-length="$maximumLength"
                    />
                @endforeach
            </div>

            @if ($comments->hasPages())
                <nav aria-label="{{ __('comments.accessibility.pagination') }}">
                    {{ $comments->links(data: ['scrollTo' => '#discussion']) }}
                </nav>
            @endif
        @endif
    @endif

    @if ($reportingCommentId !== null)
        <dialog data-comment-dialog data-comment-dialog-open class="w-[min(42rem,calc(100%-2rem))] rounded-panel border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="comment-report-title">
            <form wire:submit="submitReport" class="p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 id="comment-report-title" class="text-xl font-black text-slate-800">{{ __('comments.reports.title') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('comments.reports.details_hint') }}</p>
                    </div>
                    <button type="button" data-comment-dialog-close wire:click="closeReport" class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('comments.accessibility.close_dialog') }}"><x-ui.icon name="fa-solid fa-xmark" /></button>
                </div>
                <div class="mt-5 space-y-4">
                    <div>
                        <label for="comment-report-category" class="block text-sm font-bold text-slate-700">{{ __('comments.reports.category') }}</label>
                        <select id="comment-report-category" wire:model="reportCategory" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($reportCategories as $category)
                                <option value="{{ $category['value'] }}">{{ $category['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="comment-report-details" class="block text-sm font-bold text-slate-700">{{ __('comments.reports.details') }}</label>
                        <textarea id="comment-report-details" wire:model="reportDetails" rows="5" maxlength="2000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-base leading-6 text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submitReport" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-600 disabled:cursor-wait disabled:opacity-60"><x-ui.icon name="fa-solid fa-flag" />{{ __('comments.reports.submit') }}</button>
                </div>
            </form>
        </dialog>
    @endif
</section>
