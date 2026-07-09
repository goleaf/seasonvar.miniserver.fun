@props(['title'])

@php
    $seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
    $episodesCount = (int) ($title->episodes_count ?? 0);
    $cardRelations = collect()
        ->merge($title->relationLoaded('genres') ? $title->genres : collect())
        ->merge($title->relationLoaded('countries') ? $title->countries : collect())
        ->merge($title->relationLoaded('ageRatings') ? $title->ageRatings : collect())
        ->merge($title->relationLoaded('translations') ? $title->translations : collect())
        ->merge($title->relationLoaded('tags') ? $title->tags : collect())
        ->take(3);
@endphp

<a href="{{ route('titles.show', $title) }}" class="group flex h-full flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60 transition hover:border-emerald-300 hover:shadow-md hover:shadow-emerald-100">
    <x-title-poster :title="$title" class="aspect-[3/2] rounded-none sm:aspect-[16/10]" image-class="h-full w-full object-cover transition group-hover:scale-[1.02]" empty-class="flex h-full items-center justify-center text-sm text-slate-400" />

    <div class="flex flex-1 flex-col p-3 sm:p-4">
        <div class="flex items-center gap-2 text-xs text-slate-500">
            <span>{{ $title->type === 'serial' ? 'сериал' : $title->type }}</span>
            @if ($title->year)
                <span>{{ $title->year }}</span>
            @endif
        </div>
        <h3 class="mt-2 line-clamp-2 text-base font-bold text-slate-700">{{ $title->title }}</h3>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">{{ $seasonsCount }} сезон(ов)</span>
            <span class="rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                {{ $episodesCount > 0 ? $episodesCount.' серий' : 'серии разбираются' }}
            </span>
        </div>
        <div class="mt-3 flex flex-wrap gap-1">
            @foreach ($cardRelations as $taxonomy)
                <span class="rounded-full bg-slate-50 px-2 py-1 text-xs text-slate-500 ring-1 ring-slate-200">{{ $taxonomy->name }}</span>
            @endforeach
        </div>
    </div>
</a>
