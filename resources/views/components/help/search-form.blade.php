@props(['action', 'suggestionsUrl', 'locale', 'value' => '', 'autofocus' => false])

<form action="{{ $action }}" method="GET" role="search" class="relative" data-help-search data-suggestions-url="{{ $suggestionsUrl }}" data-help-locale="{{ $locale }}" data-help-searching="{{ __('help.states.searching') }}" data-help-search-failed="{{ __('help.states.query_failed') }}" data-help-search-empty="{{ __('help.search.no_results') }}" data-help-search-updated="{{ __('help.accessibility.search_results_status') }}">
    <label for="help-search-{{ $attributes->get('id', 'main') }}" class="block text-sm font-black text-slate-800">{{ __('help.home.search_label') }}</label>
    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
        <div class="relative min-w-0 flex-1">
            <x-ui.icon name="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
                id="help-search-{{ $attributes->get('id', 'main') }}"
                name="q"
                value="{{ $value }}"
                type="search"
                maxlength="120"
                autocomplete="off"
                @if ($autofocus) autofocus @endif
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-controls="help-suggestions-{{ $attributes->get('id', 'main') }}"
                placeholder="{{ __('help.home.search_placeholder') }}"
                class="min-h-12 w-full rounded-control border border-slate-300 bg-white py-3 pl-11 pr-4 text-base text-slate-800 shadow-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"
                data-help-search-input
            >
            <div id="help-suggestions-{{ $attributes->get('id', 'main') }}" role="listbox" aria-label="{{ __('help.accessibility.search_combobox') }}" class="absolute inset-x-0 top-full z-40 mt-2 hidden max-h-80 overflow-y-auto rounded-panel border border-slate-200 bg-white p-2 shadow-xl" data-help-search-list></div>
        </div>
        <button type="submit" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-3 text-sm font-black text-white hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
            <x-ui.icon name="fa-solid fa-magnifying-glass" />
            {{ __('help.search.submit') }}
        </button>
    </div>
    <p class="sr-only" aria-live="polite" data-help-search-status></p>
</form>
