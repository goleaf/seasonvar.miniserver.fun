<div class="relative" data-catalog-directory="{{ $definition->key }}">
    <div
        wire:loading.flex
        wire:target="search,letter,sort,decade,setLetter,setDecade,clearSearch,resetDirectoryFilters,gotoPage,nextPage,previousPage"
        class="fixed inset-x-3 bottom-4 z-40 mx-auto max-w-md items-center justify-center gap-3 rounded-panel bg-slate-900/95 px-5 py-4 text-sm font-bold text-white shadow-xl sm:inset-x-auto sm:right-6"
        role="status"
        aria-live="polite"
    >
        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
        <span>{{ __('catalog.directories.loading') }}</span>
    </div>

    <header class="rounded-panel bg-white px-4 py-6 shadow-sm shadow-slate-200/70 sm:px-6 lg:px-8 lg:py-8">
        <nav aria-label="{{ __('catalog.directories.breadcrumbs') }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-500">
            <a href="{{ route('home') }}" class="min-h-11 py-3 hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">{{ __('catalog.navigation.home') }}</a>
            <x-ui.icon name="fa-solid fa-chevron-right text-[10px] text-slate-300" />
            <a href="{{ route('titles.index') }}" class="min-h-11 py-3 hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">{{ __('catalog.navigation.all_titles') }}</a>
            <x-ui.icon name="fa-solid fa-chevron-right text-[10px] text-slate-300" />
            <span aria-current="page" class="py-3 text-slate-700">{{ $definition->title }}</span>
        </nav>

        <div class="mt-4 flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="max-w-4xl">
                <div class="flex items-center gap-3 text-emerald-700">
                    <x-ui.icon :name="$definition->icon.' text-xl'" />
                    <span class="text-sm font-black uppercase tracking-[0.14em]">{{ __('catalog.directories.label') }}</span>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">{{ $definition->title }}</h1>
                <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600 sm:text-lg">{{ $definition->description }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-3 sm:max-w-lg xl:min-w-[340px]">
                <div class="rounded-control bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ __('catalog.directories.total_values') }}</dt>
                    <dd class="mt-1 text-xl font-black text-slate-900">{{ trans_choice('catalog.directories.counts.values', $totalValues) }}</dd>
                </div>
                <div class="rounded-control bg-emerald-50 px-4 py-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ __('catalog.directories.total_titles') }}</dt>
                    <dd class="mt-1 text-xl font-black text-emerald-900">{{ trans_choice('catalog.counts.results', $totalTitles) }}</dd>
                </div>
            </dl>
        </div>
    </header>

    <section aria-labelledby="directory-controls" class="mt-5 rounded-panel bg-white p-4 shadow-sm shadow-slate-200/70 sm:p-6">
        <h2 id="directory-controls" class="sr-only">{{ __('catalog.directories.controls') }}</h2>
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(210px,auto)] lg:items-end">
            <div>
                <label for="directory-search" class="mb-2 block text-sm font-bold text-slate-700">{{ __('catalog.directories.search_label') }}</label>
                <div class="flex min-w-0 items-center rounded-control border border-slate-300 bg-white focus-within:border-emerald-600 focus-within:ring-4 focus-within:ring-emerald-100">
                    <x-ui.icon name="fa-solid fa-magnifying-glass ml-3 shrink-0 text-slate-400" />
                    <input
                        id="directory-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        maxlength="{{ config('catalog.directories.search_max_length', 80) }}"
                        class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-base text-slate-800 outline-none"
                        placeholder="{{ __('catalog.directories.search_placeholder', ['item' => mb_strtolower($definition->itemLabel)]) }}"
                        autocomplete="off"
                    >
                    @if ($search !== '')
                        <button type="button" wire:click="clearSearch" class="inline-flex min-h-11 min-w-11 items-center justify-center text-slate-500 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200" aria-label="{{ __('catalog.directories.clear_search') }}">
                            <x-ui.icon name="fa-solid fa-xmark" />
                        </button>
                    @endif
                </div>
            </div>

            <div>
                <label for="directory-sort" class="mb-2 block text-sm font-bold text-slate-700">{{ __('catalog.directories.sort_label') }}</label>
                <select id="directory-sort" wire:model.live="sort" class="min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100">
                    <option value="name_asc">{{ __('catalog.directories.sort_name') }}</option>
                    <option value="count_desc">{{ __('catalog.directories.sort_count') }}</option>
                </select>
            </div>
        </div>

        @if ($letterGroups['symbols'] !== [] || $letterGroups['cyrillic'] !== [] || $letterGroups['latin'] !== [])
            <div data-directory-alphabet-groups class="mt-5" aria-label="{{ __('catalog.directories.alphabet') }}">
                <p class="text-sm font-bold text-slate-700">{{ __('catalog.directories.alphabet') }}</p>
                <div data-directory-alphabet-symbols class="mt-2 flex flex-wrap items-center gap-1.5">
                    <button type="button" wire:click="setLetter('')" @class([
                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                        'bg-emerald-700 text-white' => $letter === '',
                        'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== '',
                    ])>{{ __('catalog.catalog.alphabet.all') }}</button>
                    @foreach ($letterGroups['symbols'] as $availableLetter)
                        <button type="button" wire:key="directory-letter-{{ $availableLetter }}" wire:click="setLetter(@js($availableLetter))" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                            'bg-emerald-700 text-white' => $letter === $availableLetter,
                            'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== $availableLetter,
                        ]) aria-pressed="{{ $letter === $availableLetter ? 'true' : 'false' }}">{{ $availableLetter }}</button>
                    @endforeach
                </div>

                @foreach (['cyrillic', 'latin'] as $group)
                    @if ($letterGroups[$group] !== [])
                        <div data-directory-alphabet-group="{{ $group }}" class="mt-3 grid gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:items-start">
                            <span class="py-3 text-xs font-bold text-slate-500">{{ __("catalog.catalog.alphabet.{$group}") }}</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($letterGroups[$group] as $availableLetter)
                                    <button type="button" wire:key="directory-letter-{{ $availableLetter }}" wire:click="setLetter(@js($availableLetter))" @class([
                                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-3 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                                        'bg-emerald-700 text-white' => $letter === $availableLetter,
                                        'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $letter !== $availableLetter,
                                    ]) aria-pressed="{{ $letter === $availableLetter ? 'true' : 'false' }}">{{ $availableLetter }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($decades->isNotEmpty())
            <div class="mt-5" aria-label="{{ __('catalog.directories.decades') }}">
                <p class="text-sm font-bold text-slate-700">{{ __('catalog.directories.decades') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($decades as $availableDecade)
                        <button type="button" wire:key="directory-decade-{{ $availableDecade }}" wire:click="setDecade({{ $availableDecade }})" @class([
                            'inline-flex min-h-11 items-center justify-center rounded-control px-4 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                            'bg-emerald-700 text-white' => $decade === $availableDecade,
                            'bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => $decade !== $availableDecade,
                        ]) aria-pressed="{{ $decade === $availableDecade ? 'true' : 'false' }}">{{ $availableDecade }}-е</button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($search !== '' || $letter !== '' || $decade !== null || $sort !== 'name_asc')
            <button type="button" wire:click="resetDirectoryFilters" class="mt-5 inline-flex min-h-11 items-center gap-2 px-1 py-2 text-sm font-bold text-slate-600 hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                <x-ui.icon name="fa-solid fa-rotate-left" />
                <span>{{ __('catalog.directories.reset') }}</span>
            </button>
        @endif
    </section>

    <section data-directory-results aria-labelledby="directory-results" class="mt-6 scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="directory-results" class="text-xl font-black text-slate-900">{{ __('catalog.directories.results') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ trans_choice('catalog.directories.counts.found', $items->total()) }}</p>
            </div>
            @if ($items->lastPage() > 1)
                <p class="text-sm font-semibold text-slate-500">{{ __('catalog.directories.page', ['current' => $items->currentPage(), 'last' => $items->lastPage()]) }}</p>
            @endif
        </div>

        @if ($items->isEmpty())
            <div class="mt-4 rounded-panel bg-white px-5 py-12 text-center shadow-sm shadow-slate-200/70" role="status">
                <x-ui.icon name="fa-solid fa-folder-open text-3xl text-slate-300" />
                <h3 class="mt-4 text-lg font-black text-slate-800">{{ __('catalog.directories.empty') }}</h3>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('catalog.directories.empty_hint') }}</p>
            </div>
        @elseif ($definition->isYear())
            <div class="mt-4 space-y-8">
                @foreach ($itemsByDecade as $itemDecade => $decadeItems)
                    <section aria-labelledby="decade-{{ $itemDecade }}">
                        <h3 id="decade-{{ $itemDecade }}" class="text-lg font-black text-slate-800">{{ $itemDecade }}-е</h3>
                        <div data-directory-results-list class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white">
                            @foreach ($decadeItems as $item)
                                <div wire:key="{{ $item->item_key }}">
                                    <x-catalog.directory-card :item="$item" :directory="$definition" />
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @else
            <div data-directory-results-list class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white">
                @foreach ($items as $item)
                    <div wire:key="{{ $item->item_key }}">
                        <x-catalog.directory-card :item="$item" :directory="$definition" />
                    </div>
                @endforeach
            </div>
        @endif

        @if ($items->hasPages())
            <nav class="mt-7" aria-label="{{ __('catalog.directories.pagination') }}">
                {{ $items->onEachSide(1)->links(data: ['scrollTo' => '[data-directory-results]']) }}
            </nav>
        @endif
    </section>
</div>
