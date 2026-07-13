<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-clock-rotate-left text-emerald-700" />
                    <span>{{ __('catalog.viewing.title') }}</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                    {{ __('catalog.viewing.intro') }}
                </p>
            </div>

            @if ($history->isNotEmpty())
                <button
                    type="button"
                    wire:click="clearHistory"
                    wire:confirm.prompt="{{ __('catalog.viewing.clear_confirmation') }}&#10;&#10;{{ __('catalog.viewing.clear_prompt') }}|{{ __('catalog.viewing.clear_token') }}"
                    wire:loading.attr="disabled"
                    wire:target="clearHistory"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-ui.icon name="fa-solid fa-trash-can" />
                    <span wire:loading.remove wire:target="clearHistory">{{ __('catalog.viewing.clear') }}</span>
                    <span wire:loading wire:target="clearHistory">{{ __('catalog.viewing.clearing') }}</span>
                </button>
            @endif
        </div>
    </header>

    <x-ui.panel
        :title="__('catalog.viewing.continue')"
        :subtitle="__('catalog.viewing.continue_description')"
        icon="fa-solid fa-circle-play"
        :pad="false"
    >
        <div wire:loading.flex wire:target="removeHistoryItem,clearHistory" class="min-h-24 items-center justify-center gap-2 px-4 py-8 text-sm font-semibold text-slate-500">
            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
            <span>{{ __('catalog.viewing.updating') }}</span>
        </div>

        <div wire:loading.remove wire:target="removeHistoryItem,clearHistory">
            @if ($continueWatching->isEmpty())
                <div class="px-4 py-8 text-center">
                    <x-ui.icon name="fa-regular fa-circle-check text-3xl text-emerald-700" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('catalog.viewing.continue_empty') }}</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('catalog.viewing.continue_empty_hint') }}</p>
                </div>
            @else
                <div class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($continueWatching as $item)
                        <article data-continue-watching-card wire:key="continue-watching-{{ $item->title->id }}" class="grid min-w-0 grid-cols-1 overflow-hidden rounded-panel border border-slate-200 bg-white min-[420px]:grid-cols-[5.5rem_minmax(0,1fr)]">
                            <x-title-poster :title="$item->title" class="aspect-[16/9] max-h-48 min-h-36 w-full rounded-none border-0 bg-slate-50 min-[420px]:aspect-auto min-[420px]:h-full min-[420px]:max-h-none min-[420px]:w-auto" image-class="h-full w-full object-contain" />

                            <div class="flex min-w-0 flex-col p-3">
                                <div class="text-xs font-semibold text-slate-500">
                                    @if ($item->episode->season?->number !== null)
                                        {{ __('catalog.release.season', ['number' => $item->episode->season->number]) }}
                                    @else
                                        {{ __('catalog.viewing.special_season') }}
                                    @endif
                                    <span aria-hidden="true"> · </span>
                                    @if ($item->episode->number !== null)
                                        {{ __('catalog.viewing.episode_number', ['number' => $item->episode->number]) }}
                                    @else
                                        {{ __('catalog.viewing.episode_without_number') }}
                                    @endif
                                </div>

                                <h2 class="mt-1 break-words text-base font-black leading-6 text-slate-800">{{ $item->title->title }}</h2>

                                @if ($item->actionType === 'continue' && $item->progressPercent !== null)
                                    <div class="mt-3" aria-label="{{ __('catalog.viewing.watched_percent', ['percent' => $item->progressPercent]) }}">
                                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-emerald-600" style="width: {{ $item->progressPercent }}%"></div>
                                        </div>
                                        <div class="mt-1 text-xs font-semibold text-slate-500">{{ __('catalog.viewing.watched_percent_label', ['percent' => $item->progressPercent]) }}</div>
                                    </div>
                                @endif

                                <a
                                    href="{{ route('titles.show', ['catalogTitle' => $item->title, 'season' => $item->episode->season_id, 'episode' => $item->episode->id]) }}"
                                    wire:navigate
                                    class="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-600"
                                >
                                    <x-ui.icon name="fa-solid fa-play" />
                                    <span>{{ $item->actionLabel }}</span>
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.panel>

    <x-ui.panel
        :title="__('catalog.viewing.history')"
        :subtitle="__('catalog.viewing.history_description').' '.trans_choice('catalog.counts.history_items', $history->total()).'.'"
        icon="fa-solid fa-list-ul"
        :pad="false"
    >
        @if ($history->isEmpty())
            <div class="px-4 py-10 text-center">
                <x-ui.icon name="fa-regular fa-clock text-3xl text-slate-400" />
                <p class="mt-3 text-sm font-bold text-slate-700">{{ __('catalog.viewing.history_empty') }}</p>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('catalog.viewing.history_empty_hint') }}</p>
            </div>
        @else
            <div class="divide-y divide-slate-200">
                @foreach ($history as $progress)
                    <article wire:key="viewing-history-{{ $progress->id }}" class="grid min-w-0 gap-3 p-4 sm:grid-cols-[4.5rem_minmax(0,1fr)_auto] sm:items-center">
                        @if ($progress->is_accessible && $progress->catalogTitle)
                            <x-title-poster :title="$progress->catalogTitle" class="hidden aspect-[2/3] w-[4.5rem] rounded-control border border-slate-200 bg-slate-50 sm:block" image-class="h-full w-full object-contain" />
                        @else
                            <div class="hidden aspect-[2/3] w-[4.5rem] place-items-center rounded-control bg-slate-100 text-slate-400 sm:grid">
                                <x-ui.icon name="fa-solid fa-ban" />
                            </div>
                        @endif

                        <div class="min-w-0">
                            @if ($progress->is_accessible && $progress->catalogTitle && $progress->episode)
                                <a
                                    href="{{ route('titles.show', ['catalogTitle' => $progress->catalogTitle, 'season' => $progress->episode->season_id, 'episode' => $progress->episode->id]) }}"
                                    wire:navigate
                                    class="break-words text-base font-black text-slate-800 hover:text-emerald-700"
                                >
                                    {{ $progress->catalogTitle->title }}
                                </a>
                                <p class="mt-1 text-sm font-semibold text-slate-600">
                                    @if ($progress->episode->season?->number !== null)
                                        {{ __('catalog.release.season', ['number' => $progress->episode->season->number]) }},
                                    @endif
                                    @if ($progress->episode->number !== null)
                                        {{ __('catalog.viewing.episode_number', ['number' => $progress->episode->number]) }}
                                    @else
                                        {{ __('catalog.viewing.episode_without_number') }}
                                    @endif
                                    @if ($progress->episode->title)
                                        — {{ $progress->episode->title }}
                                    @endif
                                </p>
                            @else
                                <div class="text-base font-black text-slate-700">{{ __('catalog.viewing.unavailable_episode') }}</div>
                                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('catalog.viewing.unavailable_hint') }}</p>
                            @endif

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.icon name="fa-regular fa-clock" />
                                    {{ $progress->last_watched_at->format('d.m.Y H:i') }}
                                </span>
                                @if ($progress->completed_at)
                                    <span class="inline-flex items-center gap-1 text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-circle-check" />
                                        {{ __('catalog.viewing.completed') }}
                                    </span>
                                @elseif ($progress->progress_percent !== null)
                                    <span>{{ $progress->progress_percent }}%</span>
                                @endif
                            </div>
                        </div>

                        <button
                            type="button"
                            wire:click="removeHistoryItem({{ $progress->id }})"
                            wire:confirm="{{ __('catalog.viewing.remove_confirmation') }}"
                            wire:loading.attr="disabled"
                            wire:target="removeHistoryItem({{ $progress->id }})"
                            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto sm:justify-self-end"
                        >
                            <x-ui.icon name="fa-solid fa-xmark" />
                            <span>{{ __('catalog.viewing.remove') }}</span>
                        </button>
                    </article>
                @endforeach
            </div>

            @if ($history->hasPages())
                <div class="border-t border-slate-200 bg-slate-50 p-4">
                    {{ $history->links() }}
                </div>
            @endif
        @endif
    </x-ui.panel>
</div>
