@props(['title', 'compact' => false, 'showDescription' => true])

@php
    $seasonsCount = (int) ($title->seasons_count ?? ($title->relationLoaded('seasons') ? $title->seasons->count() : 0));
    $episodesCount = (int) ($title->episodes_count ?? 0);
    $latestSeason = $title->relationLoaded('seasons') ? $title->seasons->sortByDesc('number')->first() : null;
    $posterClass = $compact ? 'h-24 w-16' : 'h-20 w-14 sm:h-24 sm:w-16';
    $baseClass = $compact ? 'block p-3 hover:bg-emerald-50' : 'block px-4 py-3 hover:bg-emerald-50';
@endphp

<a href="{{ route('titles.show', $title) }}" {{ $attributes->merge(['class' => $baseClass]) }}>
    <div class="flex min-w-0 gap-3">
        <x-title-poster :title="$title" class="{{ $posterClass }} shrink-0" empty-class="grid h-full place-items-center px-2 text-center text-[10px] font-semibold text-slate-400" />

        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <span class="line-clamp-2 font-bold leading-5 text-slate-700">{{ $title->title }}</span>
                    @if ($title->original_title)
                        <span class="line-clamp-1 text-sm text-slate-500">{{ $title->original_title }}</span>
                    @endif
                    @if ($latestSeason)
                        <span class="text-sm text-slate-500">Сезон {{ $latestSeason->number }}</span>
                    @else
                        <span class="text-sm text-slate-500">Сезон разбирается</span>
                    @endif
                </div>

                <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold">
                    @if ($title->year)
                        <span class="rounded-full bg-slate-50 px-2 py-1 text-slate-500 ring-1 ring-slate-200">{{ $title->year }}</span>
                    @endif
                    @unless ($compact)
                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">{{ $seasonsCount }} сезон(ов)</span>
                    @endunless
                    <span class="rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                        {{ $episodesCount > 0 ? $episodesCount.' серий' : 'серии разбираются' }}
                    </span>
                </div>
            </div>

            @if ($showDescription && $title->description)
                <p class="mt-2 line-clamp-2 text-sm leading-5 text-slate-500">{{ $title->description }}</p>
            @endif
        </div>
    </div>
</a>
