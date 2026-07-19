<div class="space-y-5" data-livewire-catalog-administration-page>
    <header class="rounded-panel bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl"><x-ui.icon name="fa-solid fa-screwdriver-wrench text-emerald-700" /><span>{{ __('collections.admin.catalog_and_collections') }}</span></h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('collections.admin.catalog_and_collections_description') }}</p>
            </div>
            @if ($canImport)
                <a href="{{ route('admin.imports') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700"><x-ui.icon name="fa-solid fa-cloud-arrow-down" /><span>{{ __('administration.navigation.imports') }}</span></a>
            @endif
        </div>
        <nav class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('collections.admin.sections') }}">
            <button type="button" wire:click="setSection('catalog')" @class(['inline-flex min-h-11 items-center gap-2 rounded-control px-4 py-2 text-sm font-bold', 'bg-emerald-700 text-white' => $section === 'catalog', 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $section !== 'catalog']) @if ($section === 'catalog') aria-current="page" @endif><x-ui.icon name="fa-solid fa-film" /><span>{{ __('collections.admin.catalog_section') }}</span></button>
            @if ($canModerateCollections)
                <button type="button" wire:click="setSection('collections')" @class(['inline-flex min-h-11 items-center gap-2 rounded-control px-4 py-2 text-sm font-bold', 'bg-emerald-700 text-white' => $section === 'collections', 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $section !== 'collections']) @if ($section === 'collections') aria-current="page" @endif><x-ui.icon name="fa-solid fa-layer-group" /><span>{{ __('collections.admin.collections_section') }}</span></button>
            @endif
        </nav>
    </header>

    @if ($section === 'collections' && $canModerateCollections)
        <livewire:collections.catalog-collection-administration-manager :key="'admin-collections'" />
    @else
        <livewire:catalog-administration-manager :key="'admin-catalog'" />
    @endif
</div>
