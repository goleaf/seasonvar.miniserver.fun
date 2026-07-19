<section id="collections" data-collection-explorer class="scroll-mt-28 space-y-4" aria-labelledby="collections-heading">
    <div class="rounded-panel bg-white p-4 shadow-sm shadow-slate-200/70 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 max-w-3xl">
                <div class="flex items-center gap-2 text-sm font-black uppercase tracking-[0.12em] text-emerald-700"><x-ui.icon name="fa-solid fa-layer-group" /><span>{{ __('collections.navigation.collections') }}</span></div>
                <h2 id="collections-heading" class="mt-2 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('collections.directory.title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('collections.directory.description') }}</p>
            </div>
            <a href="{{ $collectionAction['url'] }}" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"><x-ui.icon :name="$collectionAction['icon']" /><span>{{ $collectionAction['label'] }}</span></a>
        </div>

        <form wire:submit="applySearch" class="mt-5 grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(12rem,16rem)_auto] md:items-end">
            <x-form.field :label="__('collections.directory.search_label')" for="collection-explorer-search" :placeholder="__('collections.directory.search_placeholder')" name="collections_q" wire:model="search" />
            <div>
                <label for="collection-explorer-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.directory.sort_label') }}</label>
                <select id="collection-explorer-sort" name="collections_sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                    @foreach ($sortOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:opacity-60"><x-ui.icon name="fa-solid fa-magnifying-glass" /><span>{{ __('collections.form.filter') }}</span></button>
        </form>
    </div>

    <div wire:loading.delay wire:target="search,sort,applySearch,clearSearch" role="status" aria-live="polite"><div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700"><x-ui.icon name="fa-solid fa-spinner fa-spin" /><span>{{ __('collections.page.loading') }}</span></div></div>

    @island(name: 'collection-explorer-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="collection-explorer-results">
    @if ($collections->isEmpty())
        <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center"><x-ui.icon name="fa-solid fa-folder-open text-3xl text-slate-300" /><h3 class="mt-3 text-lg font-black text-slate-700">{{ $search !== '' ? __('collections.directory.no_results') : __('collections.directory.empty') }}</h3>@if ($search !== '')<button type="button" wire:click="clearSearch" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-xmark" /><span>{{ __('collections.directory.clear') }}</span></button>@endif</div>
    @else
        <div class="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" aria-label="{{ __('collections.navigation.public_collections') }}">
            @foreach ($collections as $collection)<x-collections.collection-card wire:key="discovery-collection-{{ $collection->public_id }}" :collection="$collection" compact />@endforeach
        </div>
        <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links(data: ['region' => 'collection-explorer-results']) }}</nav>
    @endif
    </x-ui.pagination-region>
    @endisland
</section>
