<div class="mx-auto max-w-5xl space-y-5">
    <div role="status" class="rounded-panel border border-amber-300 bg-amber-50 p-4 text-sm font-bold text-amber-950">{{ __('help.admin.preview_banner') }}</div>
    <article class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-8">
        <div class="flex flex-wrap gap-2 text-xs font-black uppercase tracking-wide text-slate-500"><span>{{ $article->status->label() }}</span><span>{{ strtoupper($translation->locale) }}</span><span>{{ $article->type->label() }}</span></div>
        <h1 class="mt-4 break-words text-3xl font-black text-slate-950">{{ $translation->title }}</h1>
        <p class="mt-3 text-base leading-7 text-slate-600">{{ $translation->summary }}</p>
        <div class="help-article-content mt-6">{!! $content->html !!}</div>
    </article>
</div>
