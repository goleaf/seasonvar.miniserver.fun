@props(['article'])

<article {{ $attributes->merge(['class' => 'flex h-full min-w-0 flex-col rounded-panel border border-slate-200 bg-white p-5 shadow-panel']) }}>
    <div class="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $article->typeLabel }}</span>
        @if ($article->usesFallback)
            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-800">{{ strtoupper($article->locale) }}</span>
        @endif
    </div>
    <h3 class="mt-3 break-words text-lg font-black leading-6 text-slate-800">
        <a href="{{ $article->url }}" class="rounded-sm hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
            {{ $article->title }}
        </a>
    </h3>
    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ $article->summary }}</p>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
        <a href="{{ $article->categoryUrl }}" class="font-bold text-emerald-700 hover:underline">{{ $article->categoryTitle }}</a>
        @if ($article->lastReviewedLabel !== null)
            <span>{{ __('help.article.last_reviewed', ['date' => $article->lastReviewedLabel]) }}</span>
        @endif
    </div>
</article>
