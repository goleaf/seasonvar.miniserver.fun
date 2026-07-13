<article {{ $attributes->merge(['class' => $baseClass]) }}>
    @if ($readable)
        <div class="flex min-w-0 gap-3 sm:gap-4">
            <div class="shrink-0">
                <x-title-poster :title="$title" class="{{ $posterClass }}" empty-class="grid h-full place-items-center px-2 text-center text-[10px] font-semibold text-slate-500" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('titles.show', $title) }}" class="block break-words text-base font-bold leading-6 text-slate-700 after:absolute after:inset-0 hover:text-emerald-700">{{ $title->display_title }}</a>
                        @if ($title->display_original_title)
                            <span class="mt-1 block break-words text-sm leading-5 text-slate-500">{{ $title->display_original_title }}</span>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-1.5 text-xs font-semibold lg:justify-end">
                        @if ($title->year)
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-500">
                                <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em]" />
                                <span>{{ $title->year }}</span>
                            </span>
                        @endif
                        @if ($latestSeason)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                <x-ui.icon name="fa-solid fa-layer-group text-[0.85em]" />
                                <span>{{ __('catalog.release.season', ['number' => $latestSeason->number]) }}</span>
                            </span>
                        @endif
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                            <x-ui.icon name="fa-solid fa-circle-play text-[0.85em]" />
                            <span>{{ trans_choice('catalog.counts.episodes', $episodesCount) }}</span>
                        </span>
                        @if ($mediaCount > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                                <x-ui.icon name="fa-solid fa-file-video text-[0.85em]" />
                                <span>{{ trans_choice('catalog.counts.videos', $mediaCount) }}</span>
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
                        <a href="{{ route('titles.show', $title) }}" class="block break-words font-bold leading-5 text-slate-700 after:absolute after:inset-0 hover:text-emerald-700">{{ $title->display_title }}</a>
                        @if ($title->display_original_title)
                            <span class="block break-words text-sm leading-5 text-slate-500">{{ $title->display_original_title }}</span>
                        @endif
                        @if ($latestSeason)
                            <span class="inline-flex min-w-0 items-center gap-1 text-sm text-slate-500">
                                <x-ui.icon name="fa-solid fa-layer-group text-[0.85em] text-slate-400" />
                                <span>{{ __('catalog.release.season', ['number' => $latestSeason->number]) }}</span>
                            </span>
                        @else
                            <span class="inline-flex min-w-0 items-center gap-1 text-sm text-slate-500">
                                <x-ui.icon name="fa-solid fa-layer-group text-[0.85em] text-slate-400" />
                                <span>{{ __('catalog.release.season_without_number') }}</span>
                            </span>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold">
                        @if ($title->year)
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-500">
                                <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em]" />
                                <span>{{ $title->year }}</span>
                            </span>
                        @endif
                        @unless ($compact)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                <x-ui.icon name="fa-solid fa-layer-group text-[0.85em]" />
                                <span>{{ trans_choice('catalog.counts.seasons', $seasonsCount) }}</span>
                            </span>
                        @endunless
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                            <x-ui.icon name="fa-solid fa-circle-play text-[0.85em]" />
                            <span>{{ trans_choice('catalog.counts.episodes', $episodesCount) }}</span>
                        </span>
                        @if ($mediaCount > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700">
                                <x-ui.icon name="fa-solid fa-file-video text-[0.85em]" />
                                <span>{{ trans_choice('catalog.counts.videos', $mediaCount) }}</span>
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
