<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
        <h1 class="break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('help.search.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('help.search.description') }}</p>
        <div class="mt-5 max-w-4xl">
            <x-help.search-form id="directory" :action="$searchUrl" :suggestions-url="$suggestionsUrl" :locale="$locale" :value="$query" autofocus />
        </div>
    </header>

    <div wire:loading.delay wire:target="query,category,clearSearch" role="status" aria-live="polite" class="rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-800">{{ __('help.states.searching') }}</div>

    @if (! $schemaReady)
        <x-ui.panel><p role="status">{{ __('help.states.unavailable') }}</p></x-ui.panel>
    @elseif ($queryFailed)
        <x-ui.panel><p role="alert" class="font-bold text-rose-700">{{ __('help.states.query_failed') }}</p></x-ui.panel>
    @else
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <section aria-labelledby="help-results-title" class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="help-results-title" class="text-xl font-black text-slate-900">{{ __('help.search.results') }}</h2>
                        @if ($query !== '')
                            <p class="mt-1 text-sm text-slate-600" role="status" aria-live="polite">{{ trans_choice('help.search.results_count', $results->total(), ['count' => $results->total()]) }}</p>
                        @endif
                    </div>
                    @if ($query !== '' || $category !== '')
                        <button type="button" wire:click="clearSearch" class="min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('help.search.clear') }}</button>
                    @endif
                </div>

                @if ($query === '')
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($popular as $article)
                            <x-help.article-card :article="$article" />
                        @endforeach
                    </div>
                @elseif ($results->isEmpty())
                    <div class="rounded-panel border border-dashed border-slate-300 bg-white p-7 text-center shadow-panel">
                        <h3 class="text-lg font-black text-slate-800">{{ __('help.search.no_results_title') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('help.search.no_results') }}</p>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            <a href="{{ $homeUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-4 py-2 text-sm font-black text-white hover:bg-emerald-600">{{ __('help.categories.all') }}</a>
                            <a href="{{ $technicalSupportUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-900 px-4 py-2 text-sm font-black text-white hover:bg-slate-700">{{ __('help.escalations.technical_ticket.label') }}</a>
                            @if ($contentRequestUrl !== null)
                                <a href="{{ $contentRequestUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-black text-slate-800 hover:bg-slate-200">{{ __('help.escalations.content_request.label') }}</a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($results as $article)
                            <x-help.article-card :article="$article" wire:key="help-result-{{ $article->publicId }}" />
                        @endforeach
                    </div>
                    <nav aria-label="{{ __('help.accessibility.pagination') }}">{{ $results->links() }}</nav>
                @endif
            </section>

            <aside aria-label="{{ __('help.accessibility.category_navigation') }}" class="h-fit rounded-panel border border-slate-200 bg-white p-4 shadow-panel lg:sticky lg:top-4">
                <h2 class="font-black text-slate-900">{{ __('help.categories.title') }}</h2>
                <ul class="mt-3 space-y-1">
                    @foreach ($categories as $item)
                        <li><a href="{{ $item->url }}" class="flex min-h-11 items-center justify-between gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 hover:text-emerald-700"><span>{{ $item->title }}</span><span class="text-xs text-slate-400">{{ $item->articleCount }}</span></a></li>
                    @endforeach
                </ul>
            </aside>
        </div>
    @endif
</div>
