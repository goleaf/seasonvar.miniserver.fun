@props(['category'])

<article {{ $attributes->merge(['class' => 'min-w-0 rounded-panel border border-slate-200 bg-white p-5 shadow-panel']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="break-words text-lg font-black text-slate-800">
                <a href="{{ $category->url }}" class="rounded-sm hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200" aria-label="{{ __('help.categories.open', ['title' => $category->title]) }}">
                    {{ $category->title }}
                </a>
            </h3>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $category->description }}</p>
        </div>
        <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-800">{{ $category->articleCount }}</span>
    </div>
    @if ($category->children !== [])
        <ul class="mt-4 space-y-2 border-t border-slate-100 pt-3">
            @foreach ($category->children as $child)
                <li class="flex min-w-0 items-center justify-between gap-3">
                    <a href="{{ $child->url }}" class="break-words text-sm font-bold text-emerald-700 hover:underline">{{ $child->title }}</a>
                    <span class="text-xs text-slate-500">{{ $child->articleCount }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</article>
