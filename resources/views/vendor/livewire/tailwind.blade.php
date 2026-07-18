@if ($paginator->hasPages())
    <nav data-catalog-pagination role="navigation" aria-label="{{ __('pagination.navigation') }}" class="flex flex-col gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm font-semibold text-slate-600">
            {{ __('pagination.showing') }}
            <span class="font-black text-slate-800">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span>
            {{ __('pagination.of') }} <span class="font-black text-slate-800">{{ $paginator->total() }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400">
                    <x-ui.icon name="fa-solid fa-chevron-left" />
                    <span>{{ __('pagination.previous') }}</span>
                </span>
            @else
                <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" href="{{ $paginator->previousPageUrl() }}" rel="prev" wire:click.prevent="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                    <x-ui.icon name="fa-solid fa-chevron-left" />
                    <span>{{ __('pagination.previous') }}</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="inline-flex min-h-11 min-w-11 items-center justify-center text-sm font-bold text-slate-500">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span wire:key="paginator-{{ $paginator->getPageName() }}-{{ $page }}" aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">{{ $page }}</span>
                        @else
                            <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" data-pagination-page="{{ $page }}" href="{{ $url }}" wire:key="paginator-{{ $paginator->getPageName() }}-{{ $page }}" wire:click.prevent="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" aria-label="{{ __('pagination.page', ['page' => $page]) }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a data-pagination-control data-pagination-scroll-to="{{ $scrollTo ?? '' }}" href="{{ $paginator->nextPageUrl() }}" rel="next" wire:click.prevent="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="inert" wire:loading.class="opacity-60" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                    <span>{{ __('pagination.next') }}</span>
                    <x-ui.icon name="fa-solid fa-chevron-right" />
                </a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400">
                    <span>{{ __('pagination.next') }}</span>
                    <x-ui.icon name="fa-solid fa-chevron-right" />
                </span>
            @endif
        </div>
    </nav>
@endif
