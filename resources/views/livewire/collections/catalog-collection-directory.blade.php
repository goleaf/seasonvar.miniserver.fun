<div class="space-y-5">
    <header class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
        <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                        <x-ui.icon name="fa-solid fa-layer-group" />
                    </span>
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('collections.directory.title') }}</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('collections.directory.description') }}</p>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $collectionAction['url'] }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                    <x-ui.icon :name="$collectionAction['icon']" />
                    <span>{{ $collectionAction['label'] }}</span>
                </a>
            </div>
        </div>
    </header>

    <x-ui.panel :title="__('collections.directory.search_label')" icon="fa-solid fa-magnifying-glass">
        <form wire:submit="applySearch" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(13rem,18rem)_auto] md:items-end">
            <x-form.field
                :label="__('collections.directory.search_label')"
                for="collection-directory-search"
                :placeholder="__('collections.directory.search_placeholder')"
                wire:model="search"
            />
            <div>
                <label for="collection-directory-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.directory.sort_label') }}</label>
                <select id="collection-directory-sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:cursor-wait disabled:opacity-60">
                <x-ui.icon name="fa-solid fa-magnifying-glass" />
                <span>{{ __('collections.form.filter') }}</span>
            </button>
        </form>
    </x-ui.panel>

    <div wire:loading.delay wire:target="search,sort,applySearch,clearSearch" role="status">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
            <span>{{ __('collections.page.loading') }}</span>
        </div>
    </div>

    @if ($collections->isEmpty())
        <section class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
            <x-ui.icon name="fa-solid fa-folder-open text-3xl text-slate-300" />
            <h2 class="mt-3 text-lg font-black text-slate-700">{{ $search !== '' ? __('collections.directory.no_results') : __('collections.directory.empty') }}</h2>
            @if ($search !== '')
                <button type="button" wire:click="clearSearch" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">
                    <x-ui.icon name="fa-solid fa-xmark" />
                    <span>{{ __('collections.directory.clear') }}</span>
                </button>
            @endif
        </section>
    @else
        <section aria-label="{{ __('collections.navigation.public_collections') }}" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($collections as $collection)
                <x-collections.collection-card wire:key="public-collection-{{ $collection->public_id }}" :collection="$collection" />
            @endforeach
        </section>
        <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links() }}</nav>
    @endif
</div>
