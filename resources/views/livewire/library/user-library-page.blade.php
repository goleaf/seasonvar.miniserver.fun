<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-bookmark text-emerald-700" />
                    <span>{{ __('library.title') }}</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('library.description') }}</p>
            </div>

            <div class="flex flex-col items-start gap-2 lg:items-end">
                @if ($lastWatchedAtLabel !== null)
                    <p class="text-xs font-semibold text-slate-500">{{ __('library.last_watched_at', ['date' => $lastWatchedAtLabel]) }}</p>
                @endif
                <a href="{{ route('discover.index', ['type' => 'personalized']) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800 hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
                    <x-ui.icon name="fa-solid fa-compass" />
                    <span>{{ __('recommendations.types.personalized.title') }}</span>
                </a>
            </div>
        </div>

        <nav class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5" aria-label="{{ __('library.tabs.label') }}">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['url'] }}" @class([
                    'flex min-h-16 items-center gap-3 rounded-control border p-3 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700',
                    'border-emerald-200 bg-emerald-50 text-emerald-800' => $section === $tab['section'],
                    'border-slate-200 bg-slate-50 text-slate-700 hover:border-emerald-200 hover:bg-emerald-50' => $section !== $tab['section'],
                ]) @if ($section === $tab['section']) aria-current="page" @endif>
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-white text-emerald-700">
                        <x-ui.icon :name="$tab['icon']" />
                    </span>
                    <span class="min-w-0">
                        @if ($tab['countLabel'] !== null)
                            <span class="block text-lg font-black">{{ $tab['countLabel'] }}</span>
                        @endif
                        <span class="block break-words text-xs font-bold uppercase tracking-wide">{{ $tab['label'] }}</span>
                    </span>
                </a>
            @endforeach
        </nav>
    </header>

    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">
        <span wire:loading>{{ __('library.loading') }}</span>
    </div>

    @if ($status)
        <x-form.status-message :message="$status" />
    @endif

    @unless ($canInteract)
        <div class="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 shadow-panel" role="status">
            <p class="font-bold">{{ __('library.verification.title') }}</p>
            <p class="mt-1">{{ __('library.verification.description') }}</p>
            <a href="{{ route('verification.notice') }}" class="mt-2 inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 font-bold text-amber-800 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">
                <x-ui.icon name="fa-solid fa-envelope-circle-check" />
                <span>{{ __('library.verification.action') }}</span>
            </a>
        </div>
    @endunless

    @if ($errors->any())
        <div class="rounded-panel border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($filterable)
        <x-ui.panel :title="__('library.filters.title')" :subtitle="__('library.filters.description')" icon="fa-solid fa-filter">
            <form wire:submit="applyFilters" class="grid gap-3 lg:grid-cols-6 lg:items-end">
                <label class="grid gap-1 text-sm font-bold text-slate-700 lg:col-span-2">
                    <span>{{ __('library.filters.search') }}</span>
                    <input wire:model="filters.query" type="search" maxlength="160" autocomplete="off" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('library.filters.search_placeholder') }}">
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('library.filters.type') }}</span>
                    <select wire:model="filters.type" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="">{{ __('library.filters.any_type') }}</option>
                        @foreach ($publicationTypes as $publicationType)
                            <option value="{{ $publicationType['value'] }}">{{ $publicationType['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('library.filters.year') }}</span>
                    <input wire:model="filters.year" type="number" min="1900" max="{{ $maximumYear }}" inputmode="numeric" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('library.filters.any_year') }}">
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('library.filters.personal_tag') }}</span>
                    <select wire:model="filters.personalTag" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="">{{ __('library.filters.any_tag') }}</option>
                        @foreach ($personalTags as $personalTag)
                            <option value="{{ $personalTag->public_id }}">{{ $personalTag->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('library.filters.sort') }}</span>
                    <select wire:model="filters.sort" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        @foreach ($sortOptions as $sortOption)
                            <option value="{{ $sortOption['value'] }}">{{ $sortOption['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('library.filters.direction') }}</span>
                    <select wire:model="filters.direction" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="desc">{{ __('library.direction.desc') }}</option>
                        <option value="asc">{{ __('library.direction.asc') }}</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2 lg:col-span-5">
                    <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-check" />
                        <span>{{ __('library.filters.apply') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-rotate-left" />
                        <span>{{ __('library.filters.reset') }}</span>
                    </button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    @island(name: 'user-library-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.panel :title="$sectionTitle" :subtitle="$sectionDescription" icon="fa-solid fa-list" :pad="false">
        @if ($stateItems !== null)
            <x-ui.pagination-region name="library-state-results">
            @if ($stateItems->isEmpty())
                <div class="px-4 py-10 text-center">
                    <x-ui.icon name="fa-regular fa-folder-open text-3xl text-slate-400" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.empty.'.$section) }}</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                            <x-ui.icon name="fa-solid fa-table-list" />
                            <span>{{ __('library.empty.open_catalog') }}</span>
                        </a>
                        <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">
                            <x-ui.icon name="fa-solid fa-filter-circle-xmark" />
                            <span>{{ __('library.filters.reset') }}</span>
                        </button>
                    </div>
                </div>
            @else
                <div data-library-{{ $section }}-list class="divide-y divide-slate-200" aria-live="polite">
                    @foreach ($stateItems as $state)
                        <article wire:key="library-state-{{ $section }}-{{ $state->id }}" class="min-w-0">
                            <x-catalog.title-card
                                :title="$state->catalogTitle"
                                layout="list"
                                :show-description="false"
                                :user-in-watchlist="(bool) $state->in_watchlist"
                                :user-rating="$state->rating"
                            />
                            <div class="grid gap-3 px-3 pb-4 sm:grid-cols-2 sm:px-4 lg:grid-cols-3">
                                @if ($canInteract)
                                    <button type="button" wire:click="setWatchlist({{ $state->catalog_title_id }}, {{ $state->in_watchlist ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="setWatchlist" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 disabled:opacity-60">
                                        <x-ui.icon name="{{ $state->in_watchlist ? 'fa-solid' : 'fa-regular' }} fa-bookmark" />
                                        <span>{{ __($state->in_watchlist ? 'library.actions.remove_bookmark' : 'library.actions.add_bookmark') }}</span>
                                    </button>
                                    <label class="grid gap-1 text-sm font-bold text-slate-700">
                                        <span>{{ __('library.fields.watch_status') }}</span>
                                        <select wire:change="setWatchStatus({{ $state->catalog_title_id }}, $event.target.value)" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                                            <option value="">{{ __('recommendations.watch_status.none') }}</option>
                                            @foreach ($watchStatusOptions as $watchStatusOption)
                                                <option value="{{ $watchStatusOption['value'] }}" @selected($state->watch_status?->value === $watchStatusOption['value'])>{{ $watchStatusOption['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @if ($section === 'ratings')
                                        <label class="grid gap-1 text-sm font-bold text-slate-700">
                                            <span>{{ __('library.fields.rating') }}</span>
                                            <select wire:change="setRating({{ $state->catalog_title_id }}, $event.target.value)" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                                                <option value="">{{ __('library.actions.remove_rating') }}</option>
                                                @foreach ($ratingOptions as $rating)
                                                    <option value="{{ $rating }}" @selected($state->rating === $rating)>{{ $rating }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                @endif
                                @if ($section === 'with-updates')
                                    <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-3">
                                        @foreach ($state->personal_update_labels ?? [] as $updateLabel)
                                            <x-ui.status-pill variant="success" icon="fa-solid fa-bell">{{ $updateLabel }}</x-ui.status-pill>
                                        @endforeach
                                        @if ($canInteract)
                                            <button type="button" wire:click="acknowledgeUpdates({{ $state->catalog_title_id }})" wire:loading.attr="disabled" wire:confirm="{{ __('library.confirm.acknowledge_updates') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800 hover:bg-emerald-100 disabled:opacity-60">
                                                <x-ui.icon name="fa-solid fa-check-double" />
                                                <span>{{ __('library.actions.acknowledge_updates') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($stateItems->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $stateItems->links(data: ['region' => 'library-state-results']) }}</div>
                @endif
            @endif
            </x-ui.pagination-region>
        @elseif ($feedbackItems !== null)
            <x-ui.pagination-region name="library-feedback-results">
            @if ($feedbackItems->isEmpty())
                <div class="px-4 py-10 text-center">
                    <x-ui.icon name="fa-regular fa-eye text-3xl text-slate-400" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.empty.'.$section) }}</p>
                </div>
            @else
                <div class="divide-y divide-slate-200">
                    @foreach ($feedbackItems as $state)
                        <article wire:key="library-feedback-{{ $state->id }}" class="min-w-0">
                            <x-catalog.title-card :title="$state->catalogTitle" layout="list" :show-description="false" :user-in-watchlist="(bool) $state->in_watchlist" :user-rating="$state->rating" />
                            <div class="flex flex-wrap items-center justify-between gap-3 px-3 pb-4 sm:px-4">
                                <x-ui.status-pill variant="warning" icon="fa-solid fa-eye-slash">{{ __('recommendations.feedback.'.$state->recommendation_feedback->value) }}</x-ui.status-pill>
                                @if ($canInteract)
                                    <button type="button" wire:click="undoRecommendationFeedback({{ $state->catalog_title_id }})" wire:loading.attr="disabled" wire:confirm="{{ __('library.confirm.restore_recommendation') }}" class="min-h-11 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800 hover:bg-emerald-100 disabled:opacity-60">
                                        {{ __('recommendations.library.restore') }}
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($feedbackItems->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $feedbackItems->links(data: ['region' => 'library-feedback-results']) }}</div>
                @endif
            @endif
            </x-ui.pagination-region>
        @elseif ($markers !== null)
            <x-ui.pagination-region name="library-marker-results">
            @if ($markers->isEmpty())
                <div class="px-4 py-10 text-center">
                    <x-ui.icon name="fa-solid fa-location-dot text-3xl text-slate-400" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.empty.markers') }}</p>
                </div>
            @else
                <div class="divide-y divide-slate-200">
                    @foreach ($markers as $marker)
                        <x-ui.poster-card :src="$marker->catalogTitle?->poster_url" :alt="__('library.poster_alt', ['title' => $marker->catalogTitle?->display_title])" layout="compact" wire:key="playback-marker-{{ $marker->public_id }}">
                            <div class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                <div class="min-w-0">
                                    <h2 class="break-words text-base font-black text-slate-800">{{ $marker->catalogTitle?->display_title }}</h2>
                                    <p class="mt-1 text-sm font-semibold text-slate-600">{{ __('library.marker_episode', ['episode' => $marker->episode?->number ?? '—', 'position' => $marker->position_label]) }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ $marker->resume_url }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                                        <x-ui.icon name="fa-solid fa-play" />
                                        <span>{{ __('library.actions.resume_marker') }}</span>
                                    </a>
                                    @if ($canInteract)
                                        <button type="button" wire:click="deleteMarker('{{ $marker->public_id }}')" wire:confirm="{{ __('library.confirm.delete_marker') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">
                                            <x-ui.icon name="fa-solid fa-trash-can" />
                                            <span>{{ __('library.actions.delete_marker') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </x-ui.poster-card>
                    @endforeach
                </div>
                @if ($markers->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $markers->links(data: ['region' => 'library-marker-results']) }}</div>
                @endif
            @endif
            </x-ui.pagination-region>
        @elseif ($continueWatching !== null)
            @if ($continueWatching->isEmpty())
                <div class="px-4 py-10 text-center">
                    <x-ui.icon name="fa-regular fa-circle-check text-3xl text-emerald-700" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.empty.continue-watching') }}</p>
                </div>
            @else
                <div data-library-continue-list class="divide-y divide-slate-200">
                    @foreach ($continueWatching as $item)
                        <x-ui.poster-card :src="$item->title->poster_url" :alt="__('library.poster_alt', ['title' => $item->title->display_title])" layout="list" wire:key="continue-watching-{{ $item->title->id }}">
                            <p class="text-xs font-semibold text-slate-500">{{ __('library.episode_context', ['season' => $item->episode->season?->number ?? '—', 'episode' => $item->episode->number ?? '—']) }}</p>
                            <h2 class="mt-1 break-words text-base font-black text-slate-800">{{ $item->title->display_title }}</h2>
                            @if ($item->progressPercent !== null)
                                <progress class="mt-3 h-2 w-full accent-emerald-600" max="100" value="{{ $item->progressPercent }}" aria-label="{{ __('library.progress_label', ['percent' => $item->progressPercent]) }}"></progress>
                            @endif
                            <a href="{{ route('titles.show', ['catalogTitle' => $item->title, 'season' => $item->episode->season_id, 'episode' => $item->episode->id]) }}" class="relative z-10 mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                                <x-ui.icon name="fa-solid fa-play" />
                                <span>{{ $item->actionLabel }}</span>
                            </a>
                        </x-ui.poster-card>
                    @endforeach
                </div>
            @endif
        @elseif ($history !== null)
            <x-ui.pagination-region name="library-history-results">
            @if ($history->isEmpty())
                <div class="px-4 py-10 text-center">
                    <x-ui.icon name="fa-regular fa-clock text-3xl text-slate-400" />
                    <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.empty.history') }}</p>
                </div>
            @else
                @if ($canInteract)
                    <div class="flex justify-end border-b border-slate-200 p-4">
                        <button type="button" wire:click="clearHistory" wire:confirm.prompt="{{ __('library.confirm.clear_history_prompt') }}|{{ __('library.confirm.clear_history_word') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100">
                            <x-ui.icon name="fa-solid fa-trash-can" />
                            <span>{{ __('library.actions.clear_history') }}</span>
                        </button>
                    </div>
                @endif
                <div data-library-history-list class="divide-y divide-slate-200">
                    @foreach ($history as $progress)
                        <x-ui.poster-card :src="$progress->is_accessible && $progress->catalogTitle ? $progress->catalogTitle->poster_url : null" :alt="$progress->is_accessible && $progress->catalogTitle ? __('library.poster_alt', ['title' => $progress->catalogTitle->display_title]) : __('library.unavailable_episode')" layout="compact" wire:key="history-{{ $progress->id }}">
                            <div class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                <div class="min-w-0">
                                    @if ($progress->is_accessible && $progress->catalogTitle && $progress->episode)
                                        <a href="{{ route('titles.show', ['catalogTitle' => $progress->catalogTitle, 'season' => $progress->episode->season_id, 'episode' => $progress->episode->id]) }}" class="relative z-10 break-words text-base font-black text-slate-800 hover:text-emerald-700">{{ $progress->catalogTitle->display_title }}</a>
                                        <p class="mt-1 text-sm font-semibold text-slate-600">{{ __('library.episode_context', ['season' => $progress->episode->season?->number ?? '—', 'episode' => $progress->episode->number ?? '—']) }}</p>
                                    @else
                                        <p class="text-base font-black text-slate-700">{{ __('library.unavailable_episode') }}</p>
                                    @endif
                                    <p class="mt-2 text-xs font-semibold text-slate-500">{{ $progress->last_watched_at_label }}</p>
                                </div>
                                @if ($canInteract)
                                    <button type="button" wire:click="removeHistoryItem({{ $progress->id }})" wire:confirm="{{ __('library.confirm.remove_history_item') }}" class="relative z-10 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-rose-50 hover:text-rose-700">
                                        <x-ui.icon name="fa-solid fa-xmark" />
                                        <span>{{ __('library.actions.remove') }}</span>
                                    </button>
                                @endif
                            </div>
                        </x-ui.poster-card>
                    @endforeach
                </div>
                @if ($history->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $history->links(data: ['region' => 'library-history-results']) }}</div>
                @endif
            @endif
            </x-ui.pagination-region>
        @else
            <div class="px-4 py-10 text-center" role="status">
                <x-ui.icon name="fa-solid fa-triangle-exclamation text-3xl text-amber-600" />
                <p class="mt-3 text-sm font-bold text-slate-700">{{ __('library.errors.unavailable') }}</p>
            </div>
        @endif
    </x-ui.panel>
    @endisland
</div>
