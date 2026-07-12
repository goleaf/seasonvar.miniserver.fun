<article {{ $attributes->merge(['class' => $baseClass]) }}>
    @if ($readable)
        <div class="flex min-w-0 gap-3 sm:gap-4">
            <div class="shrink-0">
                <x-title-poster :title="$title" class="{{ $posterClass }}" image-class="h-full w-full object-contain" empty-class="grid h-full place-items-center px-2 text-center text-[10px] font-semibold text-slate-500" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('titles.show', $title) }}" class="block break-words text-base font-bold leading-6 text-slate-700 after:absolute after:inset-0 hover:text-emerald-700">{{ $title->title }}</a>
                        @if ($title->original_title)
                            <span class="mt-1 block break-words text-sm leading-5 text-slate-500">{{ $title->original_title }}</span>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-1.5 text-xs font-semibold lg:justify-end">
                        @if ($title->year)
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-500">
                                <i class="fa-solid fa-calendar-days text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $title->year }}</span>
                            </span>
                        @endif
                        @if ($latestSeason)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                <i class="fa-solid fa-layer-group text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $latestSeason->number }} сезон</span>
                            </span>
                        @endif
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                            <i class="fa-solid fa-circle-play text-[0.85em]" aria-hidden="true"></i>
                            <span>{{ $episodesCount }} серий</span>
                        </span>
                        @if ($mediaCount > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                                <i class="fa-solid fa-file-video text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $mediaCount }} видео</span>
                            </span>
                        @endif
                    </div>
                </div>

                @if ($showDescription && $title->description)
                    <p class="mt-2 break-words text-sm leading-6 text-slate-500">{{ $title->description }}</p>
                @endif

                @if ($cardRelations->isNotEmpty())
                    <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
                        @foreach ($cardRelations as $taxonomy)
                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="flex min-w-0 gap-3">
            <div class="shrink-0">
                <x-title-poster :title="$title" class="{{ $posterClass }}" empty-class="grid h-full place-items-center px-2 text-center text-[10px] font-semibold text-slate-500" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 flex-col gap-1 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('titles.show', $title) }}" class="block break-words font-bold leading-5 text-slate-700 after:absolute after:inset-0 hover:text-emerald-700">{{ $title->title }}</a>
                        @if ($title->original_title)
                            <span class="block break-words text-sm leading-5 text-slate-500">{{ $title->original_title }}</span>
                        @endif
                        @if ($latestSeason)
                            <span class="inline-flex min-w-0 items-center gap-1 text-sm text-slate-500">
                                <i class="fa-solid fa-layer-group shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                                <span>Сезон {{ $latestSeason->number }}</span>
                            </span>
                        @else
                            <span class="inline-flex min-w-0 items-center gap-1 text-sm text-slate-500">
                                <i class="fa-solid fa-layer-group shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                                <span>Сезон не указан</span>
                            </span>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold">
                        @if ($title->year)
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-500">
                                <i class="fa-solid fa-calendar-days text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $title->year }}</span>
                            </span>
                        @endif
                        @unless ($compact)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                <i class="fa-solid fa-layer-group text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $seasonsCount }} сезон(ов)</span>
                            </span>
                        @endunless
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                            <i class="fa-solid fa-circle-play text-[0.85em]" aria-hidden="true"></i>
                            <span>{{ $episodesCount }} серий</span>
                        </span>
                        @if ($mediaCount > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                                <i class="fa-solid fa-file-video text-[0.85em]" aria-hidden="true"></i>
                                <span>{{ $mediaCount }} видео</span>
                            </span>
                        @endif
                    </div>
                </div>

                @if ($showDescription && $title->description)
                    <p class="mt-2 text-sm leading-5 text-slate-500">{{ $title->description }}</p>
                @endif

                @if ($cardRelations->isNotEmpty())
                    <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
                        @foreach ($cardRelations as $taxonomy)
                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</article>
