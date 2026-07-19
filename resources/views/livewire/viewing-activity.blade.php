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
                <div data-viewing-continue-list class="divide-y divide-slate-200">
                    @foreach ($continueWatching as $item)
                        <x-ui.poster-card
                            :src="$item->title->poster_url"
                            :alt="__('catalog.seo.poster_alt', ['title' => $item->title->display_title])"
                            layout="list"
                            data-continue-watching-card
                            wire:key="continue-watching-{{ $item->title->id }}"
                        >
                            <div class="flex min-w-0 flex-col">
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

                                <h2 class="mt-1 break-words text-base font-black leading-6 text-slate-800">{{ $item->title->display_title }}</h2>
                                @if ($item->title->display_original_title)
                                    <p class="mt-0.5 break-words text-xs font-semibold leading-5 text-slate-500">{{ $item->title->display_original_title }}</p>
                                @endif

                                @if ($item->actionType === 'continue' && $item->progressPercent !== null)
                                    <div class="mt-3" aria-label="{{ __('catalog.viewing.watched_percent', ['percent' => $item->progressPercent]) }}">
                                        <x-ui.progress
                                            :value="$item->progressPercent"
                                            :label="__('catalog.viewing.watched_percent', ['percent' => $item->progressPercent])"
                                            :value-text="__('catalog.viewing.watched_percent_label', ['percent' => $item->progressPercent])"
                                            size="sm"
                                        />
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
                        </x-ui.poster-card>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.panel>

    @island(name: 'viewing-history-pagination', always: true, with: $this->paginationPage)
    <x-ui.pagination-region name="viewing-history-results">
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
            <div data-viewing-history-list class="divide-y divide-slate-200">
                @foreach ($history as $progress)
                    <x-ui.poster-card
                        :src="$progress->is_accessible && $progress->catalogTitle ? $progress->catalogTitle->poster_url : null"
                        :alt="$progress->is_accessible && $progress->catalogTitle ? __('catalog.seo.poster_alt', ['title' => $progress->catalogTitle->display_title]) : __('catalog.viewing.unavailable_episode')"
                        empty-label=""
                        layout="compact"
                        wire:key="viewing-history-{{ $progress->id }}"
                    >
                        <div class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                            <div class="min-w-0">
                                @if ($progress->is_accessible && $progress->catalogTitle && $progress->episode)
                                    <a
                                        href="{{ route('titles.show', ['catalogTitle' => $progress->catalogTitle, 'season' => $progress->episode->season_id, 'episode' => $progress->episode->id]) }}"
                                        wire:navigate
                                        class="break-words text-base font-black text-slate-800 hover:text-emerald-700"
                                    >
                                        {{ $progress->catalogTitle->display_title }}
                                    </a>
                                    @if ($progress->catalogTitle->display_original_title)
                                        <p class="mt-0.5 break-words text-xs font-semibold leading-5 text-slate-500">{{ $progress->catalogTitle->display_original_title }}</p>
                                    @endif
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
                                        {{ $progress->last_watched_at_label }}
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
                        </div>
                    </x-ui.poster-card>
                @endforeach
            </div>

            @if ($history->hasPages())
                <div class="border-t border-slate-200 bg-slate-50 p-4">
                    {{ $history->links(data: ['region' => 'viewing-history-results']) }}
                </div>
            @endif
        @endif
    </x-ui.panel>
    </x-ui.pagination-region>
    @endisland
</div>
