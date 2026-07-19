<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('requests.directory.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('requests.directory.description') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $mineUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-list-check" />{{ __('requests.actions.my_requests') }}</a>
                <a href="{{ $createUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"><x-ui.icon name="fa-solid fa-plus" />{{ __('requests.actions.create') }}</a>
            </div>
        </div>
    </header>

    <x-ui.panel :title="__('requests.directory.filters_title')" icon="fa-solid fa-filter">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-form.field :label="__('requests.fields.search')" for="request-search" :placeholder="__('requests.directory.search_placeholder')" wire:model.live.debounce.300ms="search" />
            <div><label for="request-type" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.type') }}</label><select id="request-type" wire:model.live="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">{{ __('requests.filters.all_types') }}</option>@foreach ($typeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
            <div><label for="request-status" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.status') }}</label><select id="request-status" wire:model.live="status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">{{ __('requests.filters.all_statuses') }}</option>@foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
            <div><label for="request-sort" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.sort') }}</label><select id="request-sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($sortOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
            <button type="button" wire:click="clearFilters" class="min-h-11 self-end rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('requests.actions.clear_filters') }}</button>
        </div>
    </x-ui.panel>

    <div wire:loading.delay wire:target="search,type,status,sort,clearFilters" role="status" aria-live="polite" class="rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">{{ __('requests.states.loading') }}</div>

    @island(name: 'content-request-directory-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="content-request-directory-results">
    @if (! $schemaReady)
        <x-ui.panel><p role="status" class="text-sm text-slate-600">{{ __('requests.states.unavailable') }}</p></x-ui.panel>
    @elseif ($queryFailed)
        <x-ui.panel><p role="alert" class="text-sm text-rose-700">{{ __('requests.errors.query_failed') }}</p></x-ui.panel>
    @elseif ($requests->isEmpty())
        <section class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
            <h2 class="text-lg font-black text-slate-700">{{ $search !== '' || $type !== '' || $status !== '' ? __('requests.states.no_results') : __('requests.states.empty') }}</h2>
            <div class="mt-4 flex flex-wrap justify-center gap-2"><button type="button" wire:click="clearFilters" class="min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.clear_filters') }}</button><a href="{{ $createUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white">{{ __('requests.actions.create') }}</a></div>
        </section>
    @else
        <section aria-label="{{ __('requests.directory.results_label') }}" class="grid gap-4 lg:grid-cols-2">
            @foreach ($requests as $request)
                <x-content-requests.card :request="$request" />
            @endforeach
        </section>
        <nav aria-label="{{ __('requests.fields.pagination') }}">{{ $requests->links(data: ['region' => 'content-request-directory-results']) }}</nav>
    @endif
    </x-ui.pagination-region>
    @endisland
</div>
