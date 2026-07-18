<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
        @if ($category->usesFallback)
            <div class="mb-4 rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                <strong>{{ __('help.article.fallback_title') }}</strong>
                <span>{{ __('help.article.fallback', ['locale' => strtoupper($category->locale)]) }}</span>
            </div>
        @endif
        <h1 class="break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ $category->title }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ $category->description }}</p>
        <p class="mt-3 text-xs font-bold uppercase tracking-wide text-slate-500">{{ trans_choice('help.categories.articles_count', $category->articleCount, ['count' => $category->articleCount]) }}</p>
    </header>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-800">{{ __('help.states.loading') }}</div>

    @if ($queryFailed)
        <x-ui.panel><p role="alert" class="font-bold text-rose-700">{{ __('help.states.query_failed') }}</p></x-ui.panel>
    @elseif ($articles->isEmpty())
        <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
            <p class="text-sm text-slate-600">{{ __('help.categories.empty') }}</p>
            <a href="{{ $homeUrl }}" class="mt-4 inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">{{ __('help.article.back_to_help') }}</a>
        </div>
    @else
        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="{{ $category->title }}">
            @foreach ($articles as $article)
                <x-help.article-card :article="$article" wire:key="help-category-{{ $article->publicId }}" />
            @endforeach
        </section>
        <nav aria-label="{{ __('help.accessibility.pagination') }}">{{ $articles->links() }}</nav>
    @endif
</div>
