<a href="{{ route('titles.show', $title) }}" {{ $attributes->merge(['class' => $baseClass]) }}>
    <div class="flex min-w-0 gap-3">
        <x-title-poster :title="$title" class="{{ $posterClass }} shrink-0" empty-class="grid h-full place-items-center px-2 text-center text-[10px] font-semibold text-slate-400" />

        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <span @class([
                        'font-bold leading-5 text-slate-700',
                        'line-clamp-2' => ! $readable,
                    ])>{{ $title->title }}</span>
                    @if ($title->original_title)
                        <span @class([
                            'text-sm text-slate-500',
                            'line-clamp-1' => ! $readable,
                        ])>{{ $title->original_title }}</span>
                    @endif
                    @if ($latestSeason)
                        <span class="inline-flex items-center gap-1 text-sm text-slate-500">
                            <i class="fa-solid fa-layer-group text-[0.85em] text-slate-400" aria-hidden="true"></i>
                            <span>Сезон {{ $latestSeason->number }}</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-sm text-slate-500">
                            <i class="fa-solid fa-layer-group text-[0.85em] text-slate-400" aria-hidden="true"></i>
                            <span>Сезон скоро появится</span>
                        </span>
                    @endif
                </div>

                <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold">
                    @if ($title->year)
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-500 ring-1 ring-slate-200">
                            <i class="fa-solid fa-calendar-days text-[0.85em]" aria-hidden="true"></i>
                            <span>{{ $title->year }}</span>
                        </span>
                    @endif
                    @unless ($compact)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                            <i class="fa-solid fa-layer-group text-[0.85em]" aria-hidden="true"></i>
                            <span>{{ $seasonsCount }} сезон(ов)</span>
                        </span>
                    @endunless
                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                        <i class="fa-solid fa-circle-play text-[0.85em]" aria-hidden="true"></i>
                        <span>{{ $episodesCount > 0 ? $episodesCount.' серий' : 'серии скоро появятся' }}</span>
                    </span>
                </div>
            </div>

            @if ($showDescription && $title->description)
                <p @class([
                    'mt-2 text-sm leading-5 text-slate-500',
                    'line-clamp-2' => ! $readable,
                    'line-clamp-3' => $readable,
                ])>{{ $title->description }}</p>
            @endif
        </div>
    </div>
</a>
