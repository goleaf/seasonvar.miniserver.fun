<div class="space-y-6">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7 lg:p-9">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">{{ __('help.home.eyebrow') }}</p>
        <h1 class="mt-2 break-words text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">{{ __('help.home.title') }}</h1>
        <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">{{ __('help.home.description') }}</p>
        <div class="mt-6 max-w-4xl">
            <x-help.search-form id="home" :action="$searchUrl" :suggestions-url="$suggestionsUrl" :locale="$locale" />
        </div>
    </header>

    @if (! $schemaReady)
        <x-ui.panel><p role="status" class="text-sm text-slate-600">{{ __('help.states.unavailable') }}</p></x-ui.panel>
    @elseif ($queryFailed)
        <x-ui.panel><p role="alert" class="text-sm font-bold text-rose-700">{{ __('help.states.query_failed') }}</p></x-ui.panel>
    @else
        <section aria-labelledby="help-categories-title">
            <div class="mb-3 flex items-end justify-between gap-3">
                <h2 id="help-categories-title" class="text-xl font-black text-slate-900 sm:text-2xl">{{ __('help.home.browse_categories') }}</h2>
            </div>
            @if ($categories === [])
                <div class="rounded-panel border border-dashed border-slate-300 bg-white p-7 text-center text-sm text-slate-600 shadow-panel">{{ __('help.categories.empty') }}</div>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($categories as $category)
                        <x-help.category-card :category="$category" />
                    @endforeach
                </div>
            @endif
        </section>

        @if ($featured !== [])
            <section aria-labelledby="help-featured-title">
                <h2 id="help-featured-title" class="mb-3 text-xl font-black text-slate-900 sm:text-2xl">{{ __('help.home.featured') }}</h2>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($featured as $article)
                        <x-help.article-card :article="$article" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($popular !== [])
            <section aria-labelledby="help-popular-title">
                <h2 id="help-popular-title" class="mb-3 text-xl font-black text-slate-900 sm:text-2xl">{{ __('help.home.popular') }}</h2>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($popular as $article)
                        <x-help.article-card :article="$article" />
                    @endforeach
                </div>
            </section>
        @endif

        <aside class="rounded-panel border border-sky-200 bg-sky-50 p-5 sm:p-6">
            <h2 class="text-lg font-black text-sky-950">{{ __('help.home.support_boundary_title') }}</h2>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-sky-900">{{ __('help.home.support_boundary') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ $technicalSupportUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-900 px-4 py-2 text-sm font-black text-white hover:bg-slate-700">{{ __('help.escalations.technical_ticket.label') }}</a>
                @if ($contentRequestUrl !== null)
                    <a href="{{ $contentRequestUrl }}" class="inline-flex min-h-11 items-center rounded-control bg-white px-4 py-2 text-sm font-black text-sky-900 hover:bg-sky-100">{{ __('help.escalations.content_request.label') }}</a>
                @endif
            </div>
        </aside>
    @endif
</div>
