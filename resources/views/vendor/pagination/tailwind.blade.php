@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Страницы каталога" class="flex flex-col gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm font-semibold text-slate-600">
            Показано
            <span class="font-black text-slate-800">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span>
            из <span class="font-black text-slate-800">{{ $paginator->total() }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400 ring-1 ring-slate-200">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    <span>{{ __('pagination.previous') }}</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
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
                            <span aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="Страница {{ $page }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <span>{{ __('pagination.next') }}</span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400 ring-1 ring-slate-200">
                    <span>{{ __('pagination.next') }}</span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </span>
            @endif
        </div>
    </nav>
@endif
