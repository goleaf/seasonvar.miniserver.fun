<section id="collections" data-collection-explorer class="scroll-mt-28 space-y-4" aria-labelledby="collections-heading">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 max-w-3xl">
                <div class="flex items-center gap-2 text-sm font-black uppercase tracking-[0.12em] text-emerald-700">
                    <x-ui.icon name="fa-solid fa-layer-group" />
                    <span>{{ __('collections.navigation.collections') }}</span>
                </div>
                <h2 id="collections-heading" class="mt-2 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('collections.directory.title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('collections.directory.description') }}</p>
            </div>
            <a href="{{ $collectionAction['url'] }}" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                <x-ui.icon :name="$collectionAction['icon']" />
                <span>{{ $collectionAction['label'] }}</span>
            </a>
        </div>

        <form wire:submit="applySearch" class="mt-5 grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(12rem,16rem)_auto] md:items-end">
            <x-form.field :label="__('collections.directory.search_label')" for="collection-explorer-search" :placeholder="__('collections.directory.search_placeholder')" name="collections_q" wire:model="search" />
            <div>
                <label for="collection-explorer-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.directory.sort_label') }}</label>
                <select id="collection-explorer-sort" name="collections_sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:opacity-60">
                <x-ui.icon name="fa-solid fa-magnifying-glass" />
                <span>{{ __('collections.form.filter') }}</span>
            </button>
        </form>
    </header>

    <div wire:loading.delay wire:target="search,sort,category,subcategory,selectCategory,applySearch,clearSearch,resetFilters" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('collections.page.loading') }}</span>
        </div>
    </div>

    <div class="min-w-0 space-y-4">
        <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
            <input type="hidden" name="collections_category" value="{{ $category }}">
            <input type="hidden" name="collections_subcategory" value="{{ $subcategory }}">

            <nav data-collection-category-tree aria-label="{{ __('collections.directory.category_label') }}">
                <button type="button" wire:click="$set('category', '')" @class([
                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-bold',
                    'bg-emerald-700 text-white' => $category === '',
                    'text-slate-700 hover:bg-slate-100' => $category !== '',
                ])>
                    <span>{{ __('collections.directory.all_categories') }}</span>
                    <span class="text-xs">{{ $totalCount }}</span>
                </button>

                <ul class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($categoryNavigation as $option)
                        <li data-collection-category="{{ $option['slug'] }}" class="min-w-0 rounded-control border border-slate-200 bg-slate-50 p-2">
                            @if ($option['is_filterable'])
                                <button type="button" wire:click="$set('category', '{{ $option['slug'] }}')" @class([
                                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-black',
                                    'bg-emerald-700 text-white' => $category === $option['slug'] && $subcategory === '',
                                    'text-slate-800 hover:bg-white' => $category !== $option['slug'] || $subcategory !== '',
                                ])>
                                    <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                                    <span class="shrink-0 text-xs">{{ $option['count'] }}</span>
                                </button>
                            @else
                                <div @class([
                                    'flex min-h-11 items-center justify-between gap-3 rounded-control px-3 py-2 text-sm font-black',
                                    'bg-emerald-50 text-emerald-900' => $category === $option['slug'] && $subcategory === '',
                                    'text-slate-700' => $category !== $option['slug'] || $subcategory !== '',
                                ])>
                                    <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                                    <span class="shrink-0 text-xs text-slate-500">{{ $option['count'] }}</span>
                                </div>
                            @endif

                            @if ($option['children'] !== [])
                                <ul class="mt-1 space-y-1 border-t border-slate-200 pt-1">
                                    @foreach ($option['children'] as $child)
                                        <li data-collection-subcategory="{{ $child['slug'] }}">
                                            @if ($child['is_filterable'])
                                                <button type="button" wire:click="selectCategory('{{ $option['slug'] }}', '{{ $child['slug'] }}')" @class([
                                                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-semibold',
                                                    'bg-slate-800 text-white' => $subcategory === $child['slug'],
                                                    'text-slate-600 hover:bg-white hover:text-slate-900' => $subcategory !== $child['slug'],
                                                ])>
                                                    <span class="min-w-0 break-words">{{ $child['label'] }}</span>
                                                    <span class="shrink-0 text-xs">{{ $child['count'] }}</span>
                                                </button>
                                            @else
                                                <div @class([
                                                    'flex min-h-9 items-center justify-between gap-3 rounded-control px-3 py-1.5 text-sm',
                                                    'bg-slate-200 font-semibold text-slate-900' => $subcategory === $child['slug'],
                                                    'text-slate-600' => $subcategory !== $child['slug'],
                                                ])>
                                                    <span class="min-w-0 break-words">{{ $child['label'] }}</span>
                                                    <span class="shrink-0 text-xs text-slate-500">{{ $child['count'] }}</span>
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($showUncategorizedFilter)
                    <button type="button" wire:click="$set('category', 'uncategorized')" @class([
                        'mt-3 flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-bold',
                        'bg-emerald-700 text-white' => $category === 'uncategorized',
                        'text-slate-700 hover:bg-slate-100' => $category !== 'uncategorized',
                    ])>
                        <span>{{ __('collections.directory.uncategorized') }}</span>
                        <span class="text-xs">{{ $uncategorizedCount }}</span>
                    </button>
                @endif
            </nav>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <p class="text-sm font-bold text-slate-700">{{ __('collections.directory.results_count', ['count' => $collections->total()]) }}</p>
                @if ($hasActiveFilters)
                    <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">
                        <x-ui.icon name="fa-solid fa-xmark" />
                        <span>{{ __('collections.directory.reset_all') }}</span>
                    </button>
                @endif
            </div>
        </div>

        @island(name: 'collection-explorer-pagination', always: true, with: $this->paginationIslandPage)
        <x-ui.pagination-region name="collection-explorer-results">
            @if ($collections->isEmpty())
                <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center">
                    <x-ui.icon name="fa-solid fa-folder-open text-3xl text-slate-300" />
                    <h3 class="mt-3 text-lg font-black text-slate-700">
                        {{ $hasActiveFilters ? __('collections.directory.category_empty') : __('collections.directory.empty') }}
                    </h3>
                    @if ($hasActiveFilters)
                        <button type="button" wire:click="resetFilters" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">
                            <x-ui.icon name="fa-solid fa-xmark" />
                            <span>{{ __('collections.directory.reset_all') }}</span>
                        </button>
                    @endif
                </div>
            @else
                <div class="grid min-w-0 gap-3 sm:grid-cols-2" aria-label="{{ __('collections.navigation.public_collections') }}">
                    @foreach ($collections as $collection)
                        <x-collections.collection-card wire:key="discovery-collection-{{ $collection->public_id }}" :collection="$collection" compact />
                    @endforeach
                </div>
                <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links(data: ['region' => 'collection-explorer-results']) }}</nav>
            @endif
        </x-ui.pagination-region>
        @endisland
    </div>
</section>
