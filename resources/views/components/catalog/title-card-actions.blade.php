<div
    data-title-card-actions
    class="relative z-20 mt-auto flex flex-wrap items-center gap-2 pt-4"
>
    @if ($playActionUrl !== null && $playActionLabel !== null)
        <a
            data-title-card-watch
            href="{{ $playActionUrl }}"
            class="title-card-action-primary flex-1"
        >
            <x-ui.icon name="fa-solid fa-play text-xs" />
            <span>{{ $playActionLabel }}</span>
        </a>
    @endif

    @if ($interactive)
        @if ($viewerAuthenticated)
            <button
                data-title-card-library
                type="button"
                aria-pressed="{{ $userInWatchlist ? 'true' : 'false' }}"
                aria-label="{{ $userInWatchlist ? __('catalog.title.card_actions.remove_from_library_label', ['title' => $displayTitle]) : __('catalog.title.card_actions.add_to_library_label', ['title' => $displayTitle]) }}"
                wire:click="setCardWatchlist({{ $title->id }}, {{ $userInWatchlist ? 'false' : 'true' }})"
                wire:loading.attr="disabled"
                wire:target="setCardWatchlist({{ $title->id }}, {{ $userInWatchlist ? 'false' : 'true' }})"
                class="title-card-action-icon"
            >
                <x-ui.icon :name="$userInWatchlist ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark'" />
                @if ($layout === 'list')
                    <span>{{ $userInWatchlist ? __('catalog.title.card_actions.remove_from_library') : __('catalog.title.card_actions.add_to_library') }}</span>
                @endif
            </button>
        @else
            <a
                data-title-card-library
                href="{{ route('login') }}"
                aria-label="{{ __('catalog.title.card_actions.add_to_library') }}"
                class="title-card-action-icon"
            >
                <x-ui.icon name="fa-regular fa-bookmark" />
                @if ($layout === 'list')
                    <span>{{ __('catalog.title.card_actions.add_to_library') }}</span>
                @endif
            </a>
        @endif
    @endif

    @if ($layout === 'list')
        <a
            data-title-card-details
            href="{{ route('titles.show', $title) }}"
            class="title-card-action-secondary"
        >
            <span>{{ __('catalog.title.more_details') }}</span>
            <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
        </a>
    @elseif ($playActionUrl === null)
        <a
            data-title-card-details
            href="{{ route('titles.show', $title) }}"
            class="title-card-action-secondary flex-1"
        >
            <span>{{ __('catalog.title.more_details') }}</span>
        </a>
    @endif

    @if ($interactive && $viewerAuthenticated)
        <details data-title-card-menu class="group/menu relative">
            <summary
                aria-label="{{ __('catalog.title.card_actions.more_label', ['title' => $displayTitle]) }}"
                class="title-card-action-icon cursor-pointer list-none"
            >
                <x-ui.icon name="fa-solid fa-ellipsis" />
            </summary>

            <div class="title-card-action-menu-panel">
                <a
                    href="{{ route('titles.show', $title) }}"
                    class="title-card-action-menu-item"
                >
                    <x-ui.icon name="fa-solid fa-circle-info" />
                    <span>{{ __('catalog.title.more_details') }}</span>
                </a>

                <details class="group/feedback mt-1 border-t border-slate-200 pt-1">
                    <summary class="title-card-action-feedback-summary cursor-pointer list-none">
                        <span class="flex items-center gap-2">
                            <x-ui.icon name="fa-solid fa-eye-slash" />
                            <span>{{ __('recommendations.feedback.not_interested') }}</span>
                        </span>
                        <x-ui.icon name="fa-solid fa-chevron-down text-xs transition group-open/feedback:rotate-180" />
                    </summary>
                    <div class="border-t border-slate-100 pt-2">
                        <p class="px-3 text-xs leading-5 text-slate-600">{{ __('recommendations.feedback.reason_hint') }}</p>
                        <div class="mt-2 grid gap-1">
                            @foreach ($feedbackReasons as $reason)
                                <button
                                    type="button"
                                    wire:click="setCardFeedbackReason({{ $title->id }}, '{{ $reason }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="setCardFeedbackReason({{ $title->id }}, '{{ $reason }}')"
                                    class="title-card-action-feedback-reason"
                                >{{ __('recommendations.feedback.reasons.'.$reason) }}</button>
                            @endforeach
                        </div>
                    </div>
                </details>
            </div>
        </details>
    @endif
</div>

@include('components.catalog.title-card-personal-state')
