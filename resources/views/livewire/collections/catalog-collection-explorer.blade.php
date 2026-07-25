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

    <div wire:loading.delay wire:target="search,sort,category,subcategory,applySearch,clearSearch,resetFilters" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('collections.page.loading') }}</span>
        </div>
    </div>

    <div class="grid min-w-0 gap-4 lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start">
        <aside class="hidden rounded-panel border border-slate-200 bg-white p-3 shadow-panel lg:block" aria-label="{{ __('collections.directory.category_label') }}">
            <div class="space-y-1">
                <button type="button" wire:click="$set('category', '')" @class([
                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-bold',
                    'bg-emerald-700 text-white' => $category === '',
                    'text-slate-700 hover:bg-slate-100' => $category !== '',
                ])>
                    <span>{{ __('collections.directory.all_categories') }}</span>
                    <span class="text-xs">{{ $totalCount }}</span>
                </button>
                @foreach ($categoryNavigation as $option)
                    <button type="button" wire:click="$set('category', '{{ $option['slug'] }}')" @class([
                        'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-bold',
                        'bg-emerald-700 text-white' => $category === $option['slug'],
                        'text-slate-700 hover:bg-slate-100' => $category !== $option['slug'],
                    ])>
                        <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                        <span class="shrink-0 text-xs">{{ $option['count'] }}</span>
                    </button>
                @endforeach
                <button type="button" wire:click="$set('category', 'uncategorized')" @class([
                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm font-bold',
                    'bg-emerald-700 text-white' => $category === 'uncategorized',
                    'text-slate-700 hover:bg-slate-100' => $category !== 'uncategorized',
                ])>
                    <span>{{ __('collections.directory.uncategorized') }}</span>
                    <span class="text-xs">{{ $uncategorizedCount }}</span>
                </button>
            </div>
        </aside>

        <div class="min-w-0 space-y-4">
            <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                <div class="grid gap-3 sm:grid-cols-2 lg:hidden">
                    <div>
                        <label for="collection-explorer-category" class="block text-sm font-bold text-slate-700">{{ __('collections.directory.category_label') }}</label>
                        <select id="collection-explorer-category" name="collections_category" wire:model.live="category" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            <option value="">{{ __('collections.directory.all_categories') }} ({{ $totalCount }})</option>
                            @foreach ($categoryNavigation as $option)
                                <option value="{{ $option['slug'] }}">{{ $option['label'] }} ({{ $option['count'] }})</option>
                            @endforeach
                            <option value="uncategorized">{{ __('collections.directory.uncategorized') }} ({{ $uncategorizedCount }})</option>
                        </select>
                    </div>
                    @if ($subcategoryOptions !== [])
                        <div>
                            <label for="collection-explorer-subcategory" class="block text-sm font-bold text-slate-700">{{ __('collections.directory.subcategory_label') }}</label>
                            <select id="collection-explorer-subcategory" name="collections_subcategory" wire:model.live="subcategory" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                <option value="">{{ __('collections.directory.all_subcategories') }}</option>
                                @foreach ($subcategoryOptions as $option)
                                    <option value="{{ $option['slug'] }}">{{ $option['label'] }} ({{ $option['count'] }})</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="collections_subcategory" value="">
                    @endif
                </div>

                @if ($subcategoryOptions !== [])
                    <nav class="mt-4 hidden flex-wrap gap-2 lg:flex" aria-label="{{ __('collections.directory.subcategory_label') }}">
                        <button type="button" wire:click="$set('subcategory', '')" @class([
                            'inline-flex min-h-11 items-center rounded-control px-3 py-2 text-sm font-bold',
                            'bg-slate-800 text-white' => $subcategory === '',
                            'bg-slate-100 text-slate-700 hover:bg-slate-200' => $subcategory !== '',
                        ])>{{ __('collections.directory.all_subcategories') }}</button>
                        @foreach ($subcategoryOptions as $option)
                            <button type="button" wire:click="$set('subcategory', '{{ $option['slug'] }}')" @class([
                                'inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold',
                                'bg-slate-800 text-white' => $subcategory === $option['slug'],
                                'bg-slate-100 text-slate-700 hover:bg-slate-200' => $subcategory !== $option['slug'],
                            ])>
                                <span>{{ $option['label'] }}</span>
                                <span class="text-xs">{{ $option['count'] }}</span>
                            </button>
                        @endforeach
                    </nav>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 @if ($subcategoryOptions !== []) mt-4 border-t border-slate-200 pt-4 @endif">
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
    </div>
</section>
