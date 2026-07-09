@props(['title'])

<a href="{{ route('titles.show', $title) }}" class="group block overflow-hidden border border-white/10 bg-white/[0.04] hover:border-emerald-300/60">
    <div class="aspect-[16/10] bg-zinc-900">
        @if ($title->poster_url)
            <img src="{{ $title->poster_url }}" alt="{{ $title->title }}" class="h-full w-full object-cover opacity-90 transition group-hover:opacity-100">
        @else
            <div class="flex h-full items-center justify-center text-sm text-zinc-500">No poster</div>
        @endif
    </div>
    <div class="p-4">
        <div class="flex items-center gap-2 text-xs uppercase text-zinc-500">
            <span>{{ $title->type }}</span>
            @if ($title->year)
                <span>{{ $title->year }}</span>
            @endif
        </div>
        <h3 class="mt-2 line-clamp-2 text-base font-semibold text-white">{{ $title->title }}</h3>
        <div class="mt-3 flex flex-wrap gap-1">
            @foreach ($title->taxonomies->take(3) as $taxonomy)
                <span class="rounded-full bg-white/10 px-2 py-1 text-xs text-zinc-300">{{ $taxonomy->name }}</span>
            @endforeach
        </div>
    </div>
</a>
