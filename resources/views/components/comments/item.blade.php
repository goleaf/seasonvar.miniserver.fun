@props([
    'comment',
    'isReply' => false,
    'threadExpanded' => false,
    'replies' => [],
    'hasMoreReplies' => false,
    'replyLimitReached' => false,
    'editingCommentId' => null,
    'replyToCommentId' => null,
    'maximumLength' => 5000,
])

<article
    id="comment-{{ $comment->id }}"
    tabindex="-1"
    @if ($comment->isFocused) data-comment-focused @endif
    @if ($comment->isFocused) aria-describedby="comment-focused-{{ $comment->id }}" @endif
    aria-label="{{ __($isReply ? 'comments.accessibility.reply' : 'comments.accessibility.comment', ['id' => $comment->id]) }}"
    {{ $attributes->class([
        'scroll-mt-28 min-w-0 rounded-control border bg-white p-3 sm:p-4',
        'border-emerald-400 ring-4 ring-emerald-100' => $comment->isFocused,
        'border-slate-200' => ! $comment->isFocused,
        'ml-3 sm:ml-8' => $isReply,
    ]) }}
>
    <span id="comment_{{ $comment->id }}" class="sr-only" aria-hidden="true"></span>
    @if ($comment->isFocused)
        <span id="comment-focused-{{ $comment->id }}" class="sr-only">{{ __('comments.accessibility.focused') }}</span>
    @endif

    <header class="flex min-w-0 items-start gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500" aria-hidden="true">
            <x-ui.icon name="fa-solid fa-user" />
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                @if ($comment->author->profileUrl)
                    <a href="{{ $comment->author->profileUrl }}" class="break-words text-sm font-black text-slate-800 hover:text-emerald-700">{{ $comment->author->name }}</a>
                @else
                    <span class="break-words text-sm font-black text-slate-800">{{ $comment->author->name }}</span>
                @endif
                @if ($comment->moderationLabel !== null)
                    <x-ui.status-pill variant="warning">{{ $comment->moderationLabel }}</x-ui.status-pill>
                @endif
                @if ($comment->isSpoiler)
                    <x-ui.status-pill variant="warning"><x-ui.icon name="fa-solid fa-triangle-exclamation" />{{ __('comments.spoiler.label') }}</x-ui.status-pill>
                @endif
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-slate-500">
                <time datetime="{{ $comment->createdAtIso }}">{{ $comment->createdAtLabel }}</time>
                @if ($comment->editedAtLabel !== null)
                    <span aria-label="{{ __('comments.states.edited', ['time' => $comment->editedAtLabel]) }}">· {{ __('comments.states.edited', ['time' => $comment->editedAtLabel]) }}</span>
                @endif
                @if ($comment->directUrl !== null)
                    <a href="{{ $comment->directUrl }}" class="inline-flex min-h-8 items-center gap-1 rounded-control px-2 font-bold text-slate-500 hover:bg-slate-50 hover:text-emerald-700" aria-label="{{ __('comments.actions.copy_link') }}">
                        <x-ui.icon name="fa-solid fa-link" />
                        <span class="sr-only">{{ __('comments.actions.copy_link') }}</span>
                    </a>
                @endif
            </div>
            @if ($comment->replyToAuthor !== null)
                <p class="mt-1 break-words text-xs font-semibold text-slate-500">{{ __('comments.author.replying_to', ['name' => $comment->replyToAuthor]) }}</p>
            @endif
        </div>
    </header>

    <div class="mt-3 min-w-0 sm:pl-[3.25rem]" aria-live="polite">
        @if ($comment->isUnavailable)
            <div class="rounded-control bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-500" role="note">
                <span class="inline-flex items-center gap-2"><x-ui.icon name="fa-solid fa-eye-slash" />{{ $comment->unavailableMessage }}</span>
            </div>
        @elseif ($comment->isSpoiler && ! $comment->spoilerRevealed)
            <div class="rounded-control border border-amber-200 bg-amber-50 p-3" role="note" aria-label="{{ __('comments.accessibility.spoiler_hidden') }}">
                <p class="text-sm font-semibold leading-6 text-amber-900">{{ __('comments.spoiler.warning') }}</p>
                <button
                    type="button"
                    data-comment-spoiler-toggle
                    wire:click="toggleSpoiler({{ $comment->id }})"
                    wire:loading.attr="disabled"
                    wire:target="toggleSpoiler({{ $comment->id }})"
                    aria-expanded="false"
                    aria-controls="comment-body-{{ $comment->id }}"
                    class="mt-3 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-amber-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-amber-800 disabled:opacity-60"
                >
                    <x-ui.icon name="fa-solid fa-eye" />{{ __('comments.spoiler.reveal') }}
                </button>
            </div>
        @elseif ($comment->body !== null)
            <div class="min-w-0">
                <p id="comment-body-{{ $comment->id }}" class="whitespace-pre-line break-words text-sm leading-7 text-slate-700 [overflow-wrap:anywhere]">{{ $comment->body }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($comment->isLong)
                        <button
                            type="button"
                            wire:click="toggleBody({{ $comment->id }})"
                            wire:loading.attr="disabled"
                            aria-expanded="{{ $comment->bodyExpanded ? 'true' : 'false' }}"
                            aria-controls="comment-body-{{ $comment->id }}"
                            class="inline-flex min-h-10 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100"
                        >
                            <x-ui.icon :name="$comment->bodyExpanded ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" />
                            {{ $comment->bodyExpanded ? __('comments.actions.show_less') : __('comments.actions.show_more') }}
                        </button>
                    @endif
                    @if ($comment->isSpoiler && $comment->spoilerRevealed)
                        <button
                            type="button"
                            data-comment-spoiler-toggle
                            wire:click="toggleSpoiler({{ $comment->id }})"
                            wire:loading.attr="disabled"
                            aria-expanded="true"
                            aria-controls="comment-body-{{ $comment->id }}"
                            class="inline-flex min-h-10 items-center gap-2 rounded-control bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 hover:bg-amber-100"
                        >
                            <x-ui.icon name="fa-solid fa-eye-slash" />{{ __('comments.spoiler.hide') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif

        @if ($editingCommentId === $comment->id)
            <form id="comment-edit-{{ $comment->id }}" wire:submit="saveEdit" class="mt-4 space-y-3 rounded-control border border-emerald-200 bg-emerald-50 p-3">
                <div data-comment-character-counter>
                    <label for="comment-edit-body-{{ $comment->id }}" class="block text-sm font-bold text-slate-700">{{ __('comments.composer.edit_label') }}</label>
                    <textarea id="comment-edit-body-{{ $comment->id }}" wire:model="editBody" rows="5" maxlength="{{ $maximumLength }}" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base leading-6 text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <p class="mt-2 text-right text-xs font-semibold tabular-nums text-slate-500" data-comment-character-output data-comment-character-template="{{ __('comments.composer.characters', ['count' => '__COUNT__', 'maximum' => $maximumLength]) }}">{{ __('comments.composer.characters', ['count' => 0, 'maximum' => $maximumLength]) }}</p>
                </div>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700">
                    <input type="checkbox" wire:model="editIsSpoiler" class="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                    <span>{{ __('comments.composer.spoiler') }}</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveEdit" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60 sm:flex-none">
                        <x-ui.icon name="fa-solid fa-floppy-disk" />{{ __('comments.composer.save') }}
                    </button>
                    <button type="button" wire:click="cancelEdit" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 sm:flex-none">
                        {{ __('comments.composer.cancel') }}
                    </button>
                </div>
            </form>
        @endif

        @if ($replyToCommentId === $comment->id)
            <form id="comment-reply-{{ $comment->id }}" wire:submit="publishReply" class="mt-4 space-y-3 rounded-control border border-sky-200 bg-sky-50 p-3">
                <div data-comment-character-counter>
                    <label for="comment-reply-body-{{ $comment->id }}" class="block text-sm font-bold text-slate-700">{{ __('comments.composer.reply_label') }}</label>
                    <textarea id="comment-reply-body-{{ $comment->id }}" wire:model="replyBody" rows="4" maxlength="{{ $maximumLength }}" placeholder="{{ __('comments.composer.placeholder') }}" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base leading-6 text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <p class="mt-2 text-right text-xs font-semibold tabular-nums text-slate-500" data-comment-character-output data-comment-character-template="{{ __('comments.composer.characters', ['count' => '__COUNT__', 'maximum' => $maximumLength]) }}">{{ __('comments.composer.characters', ['count' => 0, 'maximum' => $maximumLength]) }}</p>
                </div>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700">
                    <input type="checkbox" wire:model="replyIsSpoiler" class="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                    <span>{{ __('comments.composer.spoiler') }}</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="publishReply" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-sky-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-600 disabled:opacity-60 sm:flex-none">
                        <x-ui.icon name="fa-solid fa-reply" />{{ __('comments.composer.publish_reply') }}
                    </button>
                    <button type="button" wire:click="cancelReply" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 sm:flex-none">
                        {{ __('comments.composer.cancel') }}
                    </button>
                </div>
            </form>
        @endif

        @if (! $comment->isUnavailable)
            <footer class="mt-4 flex min-w-0 flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                @if ($comment->canReact)
                    <button
                        type="button"
                        @if ($comment->reactions->viewerReaction?->value === 'up') wire:click="react({{ $comment->id }}, null)" @else wire:click="react({{ $comment->id }}, 'up')" @endif
                        wire:loading.attr="disabled"
                        aria-pressed="{{ $comment->reactions->viewerReaction?->value === 'up' ? 'true' : 'false' }}"
                        aria-label="{{ $comment->reactions->viewerReaction?->value === 'up' ? __('comments.reactions.remove') : __('comments.accessibility.vote_up', ['count' => $comment->reactions->up]) }}"
                        @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 text-sm font-bold',
                            'bg-emerald-700 text-white' => $comment->reactions->viewerReaction?->value === 'up',
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $comment->reactions->viewerReaction?->value !== 'up',
                        ])
                    ><x-ui.icon name="fa-solid fa-thumbs-up" /><span class="tabular-nums">{{ $comment->reactions->up }}</span></button>
                    <button
                        type="button"
                        @if ($comment->reactions->viewerReaction?->value === 'down') wire:click="react({{ $comment->id }}, null)" @else wire:click="react({{ $comment->id }}, 'down')" @endif
                        wire:loading.attr="disabled"
                        aria-pressed="{{ $comment->reactions->viewerReaction?->value === 'down' ? 'true' : 'false' }}"
                        aria-label="{{ $comment->reactions->viewerReaction?->value === 'down' ? __('comments.reactions.remove') : __('comments.accessibility.vote_down', ['count' => $comment->reactions->down]) }}"
                        @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 text-sm font-bold',
                            'bg-rose-700 text-white' => $comment->reactions->viewerReaction?->value === 'down',
                            'bg-slate-50 text-slate-600 hover:bg-rose-50 hover:text-rose-700' => $comment->reactions->viewerReaction?->value !== 'down',
                        ])
                    ><x-ui.icon name="fa-solid fa-thumbs-down" /><span class="tabular-nums">{{ $comment->reactions->down }}</span></button>
                @else
                    <span class="inline-flex min-h-10 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-500" aria-label="{{ __('comments.reactions.score', ['score' => $comment->reactions->score]) }}">
                        <x-ui.icon name="fa-solid fa-arrow-up" />{{ $comment->reactions->up }}
                        <x-ui.icon name="fa-solid fa-arrow-down" />{{ $comment->reactions->down }}
                    </span>
                @endif

                @if ($comment->canReply)
                    <button type="button" wire:click="beginReply({{ $comment->id }})" wire:loading.attr="disabled" aria-expanded="{{ $replyToCommentId === $comment->id ? 'true' : 'false' }}" aria-controls="comment-reply-{{ $comment->id }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-700 hover:bg-sky-50 hover:text-sky-700">
                        <x-ui.icon name="fa-solid fa-reply" />{{ __('comments.actions.reply') }}
                    </button>
                @endif
                @if ($comment->canEdit)
                    <button type="button" wire:click="beginEdit({{ $comment->id }})" wire:loading.attr="disabled" aria-expanded="{{ $editingCommentId === $comment->id ? 'true' : 'false' }}" aria-controls="comment-edit-{{ $comment->id }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                        <x-ui.icon name="fa-solid fa-pen" />{{ __('comments.actions.edit') }}
                    </button>
                @endif
                @if ($comment->canDelete)
                    <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('comments.confirmations.delete') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 text-xs font-bold text-rose-700 hover:bg-rose-100">
                        <x-ui.icon name="fa-solid fa-trash" />{{ __('comments.actions.delete') }}
                    </button>
                @endif
                @if ($comment->canRestore)
                    <button type="button" wire:click="restoreComment({{ $comment->id }})" wire:confirm="{{ __('comments.confirmations.restore') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-100">
                        <x-ui.icon name="fa-solid fa-trash-arrow-up" />{{ __('comments.actions.restore') }}
                    </button>
                @endif
                @if ($comment->canReport)
                    <button type="button" data-comment-report-trigger wire:click="openReport({{ $comment->id }})" wire:loading.attr="disabled" aria-haspopup="dialog" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-600 hover:bg-amber-50 hover:text-amber-800">
                        <x-ui.icon name="fa-solid fa-flag" />{{ __('comments.actions.report') }}
                    </button>
                @endif
                @if ($comment->canMute)
                    <button type="button" wire:click="muteAuthor({{ $comment->id }})" wire:confirm="{{ __('comments.confirmations.mute') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-600 hover:bg-slate-100">
                        <x-ui.icon name="fa-solid fa-volume-xmark" />{{ __('comments.actions.mute') }}
                    </button>
                @endif
                @if ($comment->canBlock)
                    <button type="button" wire:click="blockAuthor({{ $comment->id }})" wire:confirm="{{ __('comments.confirmations.block') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-600 hover:bg-rose-50 hover:text-rose-700">
                        <x-ui.icon name="fa-solid fa-ban" />{{ __('comments.actions.block') }}
                    </button>
                @endif
            </footer>
        @elseif ($comment->canRestore)
            <footer class="mt-4 border-t border-slate-100 pt-3">
                <button type="button" wire:click="restoreComment({{ $comment->id }})" wire:confirm="{{ __('comments.confirmations.restore') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-100">
                    <x-ui.icon name="fa-solid fa-trash-arrow-up" />{{ __('comments.actions.restore') }}
                </button>
            </footer>
        @endif

        @if (! $isReply && $comment->visibleReplyCount > 0)
            <div class="mt-4 border-t border-slate-100 pt-3">
                <button type="button" wire:click="toggleThread({{ $comment->id }})" wire:loading.attr="disabled" aria-expanded="{{ $threadExpanded ? 'true' : 'false' }}" aria-controls="comment-thread-{{ $comment->id }}" aria-label="{{ __($threadExpanded ? 'comments.actions.hide_replies' : 'comments.actions.show_replies') }}: {{ trans_choice('comments.reply_count', $comment->visibleReplyCount, ['count' => $comment->visibleReplyCount]) }}" class="inline-flex min-h-11 w-full items-center justify-between gap-3 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-sky-50 hover:text-sky-700 sm:w-auto">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="fa-solid fa-comments" />{{ trans_choice('comments.reply_count', $comment->visibleReplyCount, ['count' => $comment->visibleReplyCount]) }}</span>
                    <x-ui.icon :name="$threadExpanded ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" />
                </button>
            </div>
        @endif
    </div>

    @if (! $isReply && $threadExpanded)
        <section id="comment-thread-{{ $comment->id }}" class="mt-3 space-y-3" aria-label="{{ trans_choice('comments.reply_count', $comment->visibleReplyCount, ['count' => $comment->visibleReplyCount]) }}">
            <div wire:loading.delay.flex wire:target="toggleThread({{ $comment->id }}),loadMoreReplies" class="items-center gap-2 rounded-control bg-sky-50 px-3 py-2 text-sm font-bold text-sky-700" role="status">
                <x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('comments.loading.replies') }}
            </div>
            @foreach ($replies as $reply)
                <x-comments.item
                    wire:key="comment-reply-{{ $reply->id }}"
                    :comment="$reply"
                    is-reply
                    :editing-comment-id="$editingCommentId"
                    :reply-to-comment-id="$replyToCommentId"
                    :maximum-length="$maximumLength"
                />
            @endforeach
            @if ($hasMoreReplies)
                <button type="button" wire:click="loadMoreReplies" wire:loading.attr="disabled" aria-controls="comment-thread-{{ $comment->id }}" class="ml-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-sky-50 px-4 text-sm font-bold text-sky-700 hover:bg-sky-100 sm:ml-8">
                    <x-ui.icon name="fa-solid fa-chevron-down" />{{ __('comments.actions.load_more_replies') }}
                </button>
            @elseif ($replyLimitReached)
                <p class="ml-3 rounded-control bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 sm:ml-8" role="status">{{ __('comments.states.reply_window_limited') }}</p>
            @elseif ($comment->visibleReplyCount > 0)
                <p class="ml-3 text-xs font-semibold text-slate-500 sm:ml-8">{{ __('comments.states.end_of_replies') }}</p>
            @endif
        </section>
    @endif
</article>
