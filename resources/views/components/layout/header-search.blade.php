@props(['initialQuery' => '', 'searchUrl'])

<div
    data-header-search-autocomplete
    data-suggestions-endpoint="{{ route('api.v1.search.suggestions') }}"
    data-search-url="{{ $searchUrl }}"
    data-group-titles="{{ __('catalog.header_search.groups.titles') }}"
    data-group-people="{{ __('catalog.header_search.groups.people') }}"
    data-group-directories="{{ __('catalog.header_search.groups.directories') }}"
    data-group-community="{{ __('catalog.header_search.groups.community') }}"
    data-group-sections="{{ __('catalog.header_search.groups.sections') }}"
    data-loading-label="{{ __('catalog.header_search.loading') }}"
    data-minimum-label="{{ __('catalog.header_search.minimum') }}"
    data-empty-label="{{ __('catalog.header_search.empty') }}"
    data-error-label="{{ __('catalog.header_search.error') }}"
    class="relative min-w-0 flex-1"
>
    <form action="{{ $searchUrl }}" method="GET" role="search" aria-label="{{ __('catalog.header_search.form_label') }}" class="flex min-w-0 items-start gap-2" data-header-search-form>
        <div class="relative min-w-0 flex-1">
            <label for="site-search" class="sr-only">{{ __('catalog.header_search.input_label') }}</label>
            <div data-header-search-input-frame class="flex min-h-11 min-w-0 items-center rounded-control border border-slate-300 bg-white shadow-sm">
                <span class="grid min-h-11 min-w-11 shrink-0 place-items-center text-slate-400" aria-hidden="true">
                    <x-ui.icon name="fa-solid fa-magnifying-glass" />
                </span>
                <input
                    id="site-search"
                    name="q"
                    type="search"
                    maxlength="80"
                    autocomplete="off"
                    spellcheck="false"
                    value="{{ $initialQuery }}"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="site-search-options"
                    aria-expanded="false"
                    aria-activedescendant=""
                    placeholder="{{ __('catalog.header_search.placeholder') }}"
                    class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 text-sm text-slate-800 outline-none placeholder:text-slate-500"
                    data-header-search-input
                >
                <button
                    type="button"
                    aria-label="{{ __('catalog.header_search.clear') }}"
                    @class([
                        'min-h-11 min-w-11 shrink-0 place-items-center text-slate-500 transition hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-300',
                        'hidden' => $initialQuery === '',
                        'grid' => $initialQuery !== '',
                    ])
                    data-header-search-clear
                >
                    <x-ui.icon name="fa-solid fa-xmark" />
                </button>
                <span class="hidden min-h-11 min-w-11 shrink-0 place-items-center text-emerald-700" role="status" aria-label="{{ __('catalog.header_search.loading') }}" data-header-search-spinner>
                    <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                </span>
            </div>
        </div>

        <button type="submit" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
            <x-ui.icon name="fa-solid fa-magnifying-glass" />
            <span class="sr-only sm:not-sr-only">{{ __('catalog.header_search.submit') }}</span>
        </button>
    </form>

    <div
        id="site-search-suggestions"
        class="absolute right-0 top-[calc(100%+0.5rem)] z-[70] hidden w-[min(56rem,calc(100vw-1.5rem))] rounded-control border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/15"
        data-header-search-dropdown
    >
        <button type="button" aria-label="{{ __('catalog.header_search.close') }}" class="ml-auto grid min-h-11 min-w-11 place-items-center rounded-control text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300" data-header-search-close>
            <x-ui.icon name="fa-solid fa-xmark" />
        </button>

        <div id="site-search-options" role="listbox" aria-label="{{ __('catalog.header_search.suggestions_label') }}">
            <section class="hidden" role="group" aria-label="{{ __('catalog.header_search.groups.titles') }}" data-header-search-title-section>
                <span aria-hidden="true" class="block px-2 pb-1 text-[0.6875rem] font-black uppercase tracking-[0.12em] text-slate-500">{{ __('catalog.header_search.groups.titles') }}</span>
                <div class="grid gap-1 sm:grid-cols-2" data-header-search-title-results></div>
            </section>

            <div class="hidden border-t border-slate-100 pt-2" data-header-search-portal-section>
                <div class="grid gap-2 md:grid-cols-2" data-header-search-portal-results></div>
            </div>

            <a
                href="{{ $searchUrl }}"
                role="option"
                class="mt-2 hidden min-h-11 items-center justify-center gap-2 rounded-control border-t border-slate-100 px-3 py-2 text-sm font-black text-emerald-800 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"
                data-header-search-all-results
                data-search-option
            >
                <span>{{ __('catalog.header_search.all_results') }}</span>
                <x-ui.icon name="fa-solid fa-arrow-right" />
            </a>
        </div>

        <p class="hidden rounded-control bg-slate-50 px-3 py-3 text-sm font-semibold leading-5 text-slate-600" role="status" aria-live="polite" data-header-search-status></p>
    </div>
</div>
