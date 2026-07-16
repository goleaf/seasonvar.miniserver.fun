@if ($hasPersonalState)
    <div
        data-user-card-state
        data-user-in-watchlist="{{ $userInWatchlist ? '1' : '0' }}"
        @if ($userRating !== null) data-user-rating="{{ $userRating }}" @endif
        @if ($userProgressPercent !== null) data-user-progress="{{ $userProgressPercent }}" @endif
        class="relative z-10 mt-3 border-t border-slate-100 pt-3"
    >
        <div class="flex flex-wrap gap-1.5 text-xs font-bold">
            @if ($userInWatchlist)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                    <x-ui.icon name="fa-solid fa-bookmark" />
                    <span>{{ __('catalog.player.in_watchlist') }}</span>
                </span>
            @endif
            @if ($userRating !== null)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                    <x-ui.icon name="fa-solid fa-star" />
                    <span>{{ __('catalog.player.your_rating_value', ['rating' => $userRating, 'maximum' => 10]) }}</span>
                </span>
            @endif
            @if ($userProgressPercent !== null)
                <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                    <x-ui.icon name="fa-solid fa-circle-play" />
                    <span>{{ __('catalog.viewing.watched_percent_label', ['percent' => $userProgressPercent]) }}</span>
                </span>
            @endif
        </div>

        @if ($userPrimaryAction)
            <a href="{{ $userPrimaryAction['url'] }}" class="mt-2 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                <x-ui.icon name="fa-solid fa-play" />
                <span>{{ $userPrimaryAction['label'] }}</span>
            </a>
        @endif
    </div>
@endif
