<div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4 rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div><p class="text-xs font-black uppercase tracking-widest text-emerald-700">{{ __('issues.title') }}</p><h1 class="mt-2 text-2xl font-black text-slate-900 sm:text-3xl">{{ __('issues.mine.title') }}</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('issues.mine.description') }}</p></div>
        <a href="{{ $createUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 font-bold text-white"><x-ui.icon name="fa-solid fa-plus" />{{ __('issues.actions.create') }}</a>
    </header>

    @if (session('technical_issue_query_error'))<p role="alert" class="rounded-control bg-rose-50 p-4 text-sm font-bold text-rose-800">{{ session('technical_issue_query_error') }}</p>@endif

    <section aria-labelledby="issue-filters" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex items-center justify-between gap-3"><h2 id="issue-filters" class="font-black text-slate-900">{{ __('issues.mine.filters') }}</h2><button type="button" wire:click="clearFilters" class="min-h-11 rounded-control px-3 text-sm font-bold text-emerald-700">{{ __('issues.actions.clear_filters') }}</button></div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="sm:col-span-2"><label for="issue-search" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.search') }}</label><input id="issue-search" wire:model.live.debounce.350ms="search" type="search" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div>
            <div><label for="issue-scope" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.scope') }}</label><select id="issue-scope" wire:model.live="scope" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3">@foreach ($scopeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }} · {{ $option['count'] }}</option>@endforeach</select></div>
            <div><label for="issue-status" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.status') }}</label><select id="issue-status" wire:model.live="status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3"><option value="">{{ __('issues.mine.all_statuses') }}</option>@foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
            <div><label for="issue-sort" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.sort') }}</label><select id="issue-sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3">@foreach ($sortOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
            <div class="sm:col-span-2 lg:col-span-5"><label for="issue-type-filter" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.issue_type') }}</label><select id="issue-type-filter" wire:model.live="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3"><option value="">{{ __('issues.mine.all_types') }}</option>@foreach ($typeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
        </div>
    </section>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-sky-50 p-3 text-sm font-bold text-sky-800">{{ __('issues.states.loading') }}</div>
    @if (! $schemaReady)
        <p role="status" class="rounded-control bg-amber-50 p-4 text-sm font-bold text-amber-900">{{ __('issues.states.schema_unavailable') }}</p>
    @elseif ($issues->isEmpty())
        <section class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center"><h2 class="font-black text-slate-900">{{ __('issues.states.empty') }}</h2><p class="mt-2 text-sm text-slate-600">{{ __('issues.states.empty_hint') }}</p><a href="{{ $createUrl }}" class="mt-4 inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-4 py-2 font-bold text-white">{{ __('issues.actions.create') }}</a></section>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($issues as $issue)
                <x-technical-issues.card :issue="$issue" />
            @endforeach
        </div>
        <div>{{ $issues->links() }}</div>
    @endif
</div>
