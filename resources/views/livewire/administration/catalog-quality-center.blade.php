<div class="space-y-5" data-catalog-quality-center>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <div class="mt-2 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('catalog-quality.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('catalog-quality.description') }}</p>
            </div>
            <span class="inline-flex min-h-11 items-center gap-2 self-start rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">
                <x-ui.icon name="fa-solid fa-shield-halved text-emerald-700" />
                {{ __('catalog-quality.read_only') }}
            </span>
        </div>
    </header>

    @if (! $available)
        <x-administration.state type="unavailable" :title="__('catalog-quality.states.unavailable_title')" :description="__('catalog-quality.states.unavailable')" />
    @else
        <nav aria-label="{{ __('catalog-quality.queues_label') }}" class="rounded-panel border border-slate-200 bg-white p-3 shadow-panel">
            <ul class="flex flex-wrap gap-2">
                @foreach ($summary as $queueItem)
                    <li wire:key="quality-queue-{{ $queueItem->code }}">
                        <button
                            type="button"
                            wire:click="$set('queue', '{{ $queueItem->code }}')"
                            @if ($queue === $queueItem->code) aria-current="page" @endif
                            @class([
                                'inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold transition',
                                'bg-emerald-700 text-white' => $queue === $queueItem->code,
                                'bg-slate-100 text-slate-700 hover:bg-slate-200' => $queue !== $queueItem->code,
                            ])
                        >
                            <span>{{ $queueItem->label }}</span>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs tabular-nums',
                                'bg-white/20 text-white' => $queue === $queueItem->code,
                                'bg-white text-slate-600' => $queue !== $queueItem->code,
                            ])>{{ $queueItem->count }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>

        @if ($errors->any())
            <x-administration.state type="error" :title="__('administration.shared.error')" :description="$errors->first()" />
        @endif

        <x-administration.filters :label="__('catalog-quality.filters.label')" :active-count="$activeFilterCount">
            <label class="grid gap-1 text-sm font-bold text-slate-700">
                <span>{{ __('administration.shared.search') }}</span>
                <input type="search" wire:model.live.debounce.400ms="search" maxlength="80" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal" placeholder="{{ __('catalog-quality.filters.search_placeholder') }}">
            </label>
            <label class="grid gap-1 text-sm font-bold text-slate-700">
                <span>{{ __('catalog-quality.filters.minimum_score') }}</span>
                <input type="number" wire:model.live="minimumScore" min="0" max="100" inputmode="numeric" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
            </label>
            <label class="grid gap-1 text-sm font-bold text-slate-700">
                <span>{{ __('catalog-quality.filters.maximum_score') }}</span>
                <input type="number" wire:model.live="maximumScore" min="0" max="100" inputmode="numeric" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
            </label>
            <label class="grid gap-1 text-sm font-bold text-slate-700">
                <span>{{ __('catalog-quality.filters.sort') }}</span>
                <select wire:model.live="sort" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                    <option value="score_asc">{{ __('catalog-quality.sorts.score_asc') }}</option>
                    <option value="score_desc">{{ __('catalog-quality.sorts.score_desc') }}</option>
                    <option value="stale">{{ __('catalog-quality.sorts.stale') }}</option>
                    <option value="title">{{ __('catalog-quality.sorts.title') }}</option>
                </select>
            </label>
            <label class="grid gap-1 text-sm font-bold text-slate-700">
                <span>{{ __('catalog-quality.filters.per_page') }}</span>
                <select wire:model.live="perPage" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </label>
            <button type="button" wire:click="resetFilters" class="min-h-11 self-end rounded-control border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100">
                {{ __('catalog-quality.filters.reset') }}
            </button>
        </x-administration.filters>

        <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
            {{ __('administration.shared.loading') }}
        </div>

        @if ($queryFailed)
            <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
        @endif

        @island(name: 'catalog-quality-results', always: true, with: $this->paginationIslandPage)
        <x-ui.pagination-region name="catalog-quality-results">
            @if ($items->isEmpty())
                <x-administration.state type="empty" :title="__('catalog-quality.states.empty_title')" :description="__('catalog-quality.states.empty')" />
            @else
                <div class="space-y-3" aria-label="{{ __('catalog-quality.results_label') }}">
                    @foreach ($items as $item)
                        <article wire:key="catalog-quality-{{ $item->catalogTitleId }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="break-words text-lg font-black text-slate-800">{{ $item->title }}</h2>
                                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">#{{ $item->catalogTitleId }}</span>
                                        @if ($item->needsRefresh)
                                            <span class="rounded-full bg-sky-50 px-2 py-1 text-xs font-bold text-sky-800">{{ __('catalog-quality.values.refresh_pending') }}</span>
                                        @endif
                                    </div>
                                    @if ($item->originalTitle !== null)
                                        <p class="mt-1 break-words text-sm text-slate-500">{{ $item->originalTitle }}</p>
                                    @endif
                                    <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-600">
                                        <div class="flex gap-1"><dt class="font-bold">{{ __('catalog-quality.values.year') }}:</dt><dd>{{ $item->yearLabel }}</dd></div>
                                        <div class="flex gap-1"><dt class="font-bold">{{ __('catalog-quality.values.source_checked') }}:</dt><dd>{{ $item->sourceCheckedAtLabel }}</dd></div>
                                        <div class="flex gap-1"><dt class="font-bold">{{ __('catalog-quality.values.evaluated') }}:</dt><dd>{{ $item->evaluatedAtLabel }}</dd></div>
                                    </dl>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <div @class([
                                        'grid size-16 place-items-center rounded-2xl border text-xl font-black tabular-nums',
                                        'border-rose-200 bg-rose-50 text-rose-800' => $item->severity === 'critical',
                                        'border-amber-200 bg-amber-50 text-amber-800' => $item->severity === 'warning',
                                        'border-sky-200 bg-sky-50 text-sky-800' => $item->severity === 'notice',
                                        'border-emerald-200 bg-emerald-50 text-emerald-800' => $item->severity === 'healthy',
                                    ]) aria-label="{{ __('catalog-quality.values.score_label', ['score' => $item->score]) }}">
                                        {{ $item->score }}
                                    </div>
                                    <a href="{{ $item->editUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">
                                        <x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />
                                        {{ __('catalog-quality.open_catalog') }}
                                    </a>
                                </div>
                            </div>

                            <div class="mt-4 border-t border-slate-100 pt-4">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                    {{ trans_choice('catalog-quality.issue_count', $item->issueCount, ['count' => $item->issueCount]) }}
                                </p>
                                @if ($item->issues === [])
                                    <p class="mt-2 text-sm text-emerald-800">{{ __('catalog-quality.states.no_issues') }}</p>
                                @else
                                    <ul class="mt-2 grid gap-2 md:grid-cols-2">
                                        @foreach ($item->issues as $issue)
                                            <li class="rounded-control border border-slate-200 bg-slate-50 px-3 py-2" wire:key="catalog-quality-issue-{{ $item->catalogTitleId }}-{{ $issue->code }}">
                                                <div class="flex items-start justify-between gap-3">
                                                    <p class="text-sm font-bold text-slate-800">{{ $issue->label }}</p>
                                                    <span @class([
                                                        'shrink-0 rounded-full px-2 py-0.5 text-xs font-bold',
                                                        'bg-rose-100 text-rose-800' => $issue->severity === 'critical',
                                                        'bg-amber-100 text-amber-800' => $issue->severity === 'warning',
                                                        'bg-sky-100 text-sky-800' => $issue->severity === 'notice',
                                                        'bg-emerald-100 text-emerald-800' => $issue->severity === 'healthy',
                                                    ])>{{ $issue->severityLabel }}</span>
                                                </div>
                                                @if ($issue->detail !== '')
                                                    <p class="mt-1 break-words text-xs leading-5 text-slate-600">{{ $issue->detail }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <details class="mt-4 border-t border-slate-100 pt-4">
                                <summary class="min-h-11 cursor-pointer rounded-control px-2 py-3 text-sm font-bold text-slate-800 hover:bg-slate-50">
                                    {{ __('catalog-quality.provenance.title') }}
                                    <span class="ml-1 text-xs font-normal text-slate-500">
                                        {{ trans_choice('catalog-quality.provenance.row_count', count($item->provenance), ['count' => count($item->provenance)]) }}
                                    </span>
                                </summary>

                                @if ($item->provenance === [])
                                    <p class="px-2 pb-2 text-sm text-slate-600">{{ __('catalog-quality.provenance.empty') }}</p>
                                @else
                                    <div class="mt-2 grid gap-2" role="list" aria-label="{{ __('catalog-quality.provenance.list_label') }}">
                                        @foreach ($item->provenance as $provenance)
                                            <div wire:key="catalog-provenance-{{ $item->catalogTitleId }}-{{ $provenance->key }}" role="listitem" class="grid gap-2 rounded-control border border-slate-200 bg-slate-50 p-3 text-xs sm:grid-cols-[minmax(7rem,0.7fr)_minmax(10rem,1.4fr)_minmax(8rem,1fr)_auto] sm:items-center">
                                                <div>
                                                    <p class="font-bold text-slate-800">{{ $provenance->fieldLabel }}</p>
                                                    <p class="mt-1 break-words leading-5 text-slate-600">{{ $provenance->valueLabel }}</p>
                                                </div>
                                                <div class="text-slate-600">
                                                    <span class="font-bold text-slate-700">{{ __('catalog-quality.provenance.source') }}:</span>
                                                    {{ $provenance->sourceLabel }}
                                                </div>
                                                <div class="text-slate-600">
                                                    <span class="font-bold text-slate-700">{{ __('catalog-quality.provenance.confirmed_at') }}:</span>
                                                    {{ $provenance->confirmedAtLabel }}
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                                    <span class="rounded-full bg-white px-2 py-1 font-black tabular-nums text-slate-700 ring-1 ring-slate-200">
                                                        {{ $provenance->confidence }}%
                                                    </span>
                                                    <span @class([
                                                        'rounded-full px-2 py-1 font-bold',
                                                        'bg-emerald-100 text-emerald-800' => $provenance->status === 'confirmed',
                                                        'bg-amber-100 text-amber-800' => $provenance->status === 'review',
                                                        'bg-rose-100 text-rose-800' => $provenance->status === 'conflict',
                                                    ])>{{ $provenance->statusLabel }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        </article>
                    @endforeach
                </div>

                <div class="mt-4">
                    {{ $items->links(data: ['region' => 'catalog-quality-results']) }}
                </div>
            @endif
        </x-ui.pagination-region>
        @endisland
    @endif
</div>
