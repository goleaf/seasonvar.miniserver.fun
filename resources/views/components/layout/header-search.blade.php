@props([
    'initialQuery' => '',
    'searchUrl',
    'catalogSearchUrl',
    'requestCreateUrl' => null,
])

<div
    id="site-search-dialog"
    data-header-search-autocomplete
    data-header-search-dialog
    data-mobile-open="false"
    data-suggestions-endpoint="{{ route('api.v1.search.suggestions') }}"
    data-search-url="{{ $searchUrl }}"
    data-catalog-search-url="{{ $catalogSearchUrl }}"
    data-request-create-url="{{ $requestCreateUrl }}"
    data-group-titles="{{ __('catalog.header_search.groups.titles') }}"
    data-group-people="{{ __('catalog.header_search.groups.people') }}"
    data-group-directories="{{ __('catalog.header_search.groups.directories') }}"
    data-group-community="{{ __('catalog.header_search.groups.community') }}"
    data-group-sections="{{ __('catalog.header_search.groups.sections') }}"
    data-loading-label="{{ __('catalog.header_search.loading') }}"
    data-minimum-label="{{ __('catalog.header_search.minimum') }}"
    data-empty-label="{{ __('catalog.header_search.empty') }}"
    data-error-label="{{ __('catalog.header_search.error') }}"
    data-recent-label="{{ __('catalog.header_search.recent') }}"
    data-shortcut-label="{{ __('catalog.header_search.shortcut') }}"
    {{ $attributes->class(['relative min-w-0 flex-1']) }}
>
    <div class="hidden items-center justify-between gap-3 border-b border-slate-200 pb-3 lg:hidden" data-header-search-mobile-heading>
        <strong class="text-lg font-semibold text-slate-900">{{ __('catalog.header_search.mobile_title') }}</strong>
        <button
            type="button"
            aria-label="{{ __('catalog.header_search.close_fullscreen') }}"
            class="grid min-h-11 min-w-11 place-items-center rounded-control text-slate-600 hover:bg-slate-100 hover:text-slate-900"
            data-header-search-mobile-close
        >
            <x-ui.icon name="fa-solid fa-xmark" />
        </button>
    </div>

    <form action="{{ $searchUrl }}" method="GET" role="search" aria-label="{{ __('catalog.header_search.form_label') }}" class="flex min-w-0 items-start gap-2" data-header-search-form>
        <div class="relative min-w-0 flex-1">
            <label for="site-search" class="sr-only">{{ __('catalog.header_search.input_label') }}</label>
            <div data-header-search-input-frame class="flex min-h-11 min-w-0 items-center rounded-control border border-slate-300 bg-white">
                <span class="grid min-h-11 min-w-11 shrink-0 place-items-center text-slate-600" aria-hidden="true">
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
                    class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 text-base text-slate-900 outline-none placeholder:text-slate-600 lg:text-sm"
                    data-header-search-input
                >
                <kbd class="mr-2 hidden rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600 xl:inline" aria-hidden="true">Ctrl K</kbd>
                <button
                    type="button"
                    aria-label="{{ __('catalog.header_search.clear') }}"
                    @class([
                        'min-h-11 min-w-11 shrink-0 place-items-center text-slate-600 transition hover:text-slate-900',
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

        <button type="submit" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
            <x-ui.icon name="fa-solid fa-magnifying-glass" />
            <span class="sr-only xl:not-sr-only">{{ __('catalog.header_search.submit') }}</span>
        </button>
    </form>

    <div
        id="site-search-suggestions"
        class="absolute left-0 top-[calc(100%+0.5rem)] z-[70] hidden w-full max-w-none rounded-control border border-slate-200 bg-white p-2 shadow-elevated"
        data-header-search-dropdown
    >
        <button type="button" aria-label="{{ __('catalog.header_search.close') }}" class="ml-auto hidden min-h-11 min-w-11 place-items-center rounded-control text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 lg:grid" data-header-search-close>
            <x-ui.icon name="fa-solid fa-xmark" />
        </button>

        <section class="hidden border-b border-slate-200 pb-2" data-header-search-recent>
            <div class="flex min-h-11 items-center justify-between gap-3 px-2">
                <strong class="text-sm font-semibold text-slate-900">{{ __('catalog.header_search.recent') }}</strong>
                <button type="button" class="min-h-11 rounded-control px-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50" data-header-search-recent-clear>
                    {{ __('catalog.header_search.clear_recent') }}
                </button>
            </div>
            <div class="grid gap-1" data-header-search-recent-results></div>
        </section>

        <div id="site-search-options" role="listbox" aria-label="{{ __('catalog.header_search.suggestions_label') }}">
            <section class="hidden" role="group" aria-label="{{ __('catalog.header_search.groups.titles') }}" data-header-search-title-section>
                <span aria-hidden="true" class="block px-2 pb-1 text-xs font-semibold text-slate-600">{{ __('catalog.header_search.groups.titles') }}</span>
                <div class="grid gap-1 sm:grid-cols-2" data-header-search-title-results></div>
            </section>

            <div class="hidden border-t border-slate-200 pt-2" data-header-search-portal-section>
                <div class="grid gap-2 md:grid-cols-2" data-header-search-portal-results></div>
            </div>

            <a
                href="{{ $catalogSearchUrl }}"
                role="option"
                class="mt-2 hidden min-h-11 items-center justify-between gap-2 rounded-control border-t border-slate-200 px-3 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50"
                data-header-search-all-results
                data-search-option
            >
                <span>{{ __('catalog.header_search.search_catalog') }}</span>
                <x-ui.icon name="fa-solid fa-arrow-right" />
            </a>

            @if ($requestCreateUrl !== null)
                <a
                    href="{{ $requestCreateUrl }}"
                    role="option"
                    class="mt-2 hidden min-h-11 items-center justify-between gap-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 transition hover:bg-amber-100"
                    data-header-search-request
                    data-search-option
                >
                    <span>{{ __('catalog.header_search.create_request') }}</span>
                    <x-ui.icon name="fa-solid fa-plus" />
                </a>
            @endif
        </div>

        <p class="hidden rounded-control bg-slate-100 px-3 py-3 text-sm font-semibold leading-5 text-slate-700" role="status" aria-live="polite" data-header-search-status></p>
    </div>
</div>
