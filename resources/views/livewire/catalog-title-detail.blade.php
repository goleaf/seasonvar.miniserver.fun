<div
    @if ($refreshState->isActive())
        wire:poll.3s.visible="refreshCatalog"
    @endif
    class="space-y-5"
    data-livewire-catalog-title-detail
>
    <section class="grid min-w-0 gap-5 lg:grid-cols-[280px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <section class="h-full overflow-hidden rounded-panel bg-white shadow-panel">
                <div class="bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-control bg-emerald-50 text-emerald-700">
                            <x-ui.icon name="fa-solid fa-compass" />
                        </span>
                        <h2 class="text-sm font-bold text-slate-700">{{ __('catalog.title.quick_access') }}</h2>
                    </div>
                </div>
                <div class="space-y-4 p-4">
                    <nav aria-label="{{ __('catalog.title.quick_navigation') }}" class="-mx-2 grid gap-1">
                        <a data-title-quick-link href="#player" class="relative inline-flex min-h-11 items-center gap-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-black text-emerald-700 before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-emerald-600 hover:bg-emerald-100">
                            <x-ui.icon name="fa-solid fa-circle-play" />
                            <span>{{ __('catalog.title.watch') }}</span>
                        </a>

                        <a data-title-quick-link href="#seasons" class="inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-layer-group text-slate-400" />
                            <span>{{ __('catalog.title.seasons') }}</span>
                        </a>

                        <a data-title-quick-link href="#data-title-reference" class="inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-circle-info text-slate-400" />
                            <span>{{ __('catalog.title.about') }}</span>
                        </a>
                    </nav>

                    <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
                        <div class="grid min-h-16 content-center gap-1 rounded-lg bg-slate-50 px-3 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase leading-none tracking-wide text-slate-500">{{ __('catalog.title.seasons') }}</div>
                                <x-ui.icon name="fa-solid fa-layer-group text-slate-400" />
                            </div>
                            <div class="text-lg font-black leading-none tabular-nums text-slate-800">{{ $showView->parsedSeasonCount }}</div>
                        </div>

                        <div class="grid min-h-16 content-center gap-1 rounded-lg bg-slate-50 px-3 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase leading-none tracking-wide text-slate-500">{{ __('catalog.title.episodes') }}</div>
                                <x-ui.icon name="fa-solid fa-list-ol text-slate-400" />
                            </div>
                            <div class="text-lg font-black leading-none tabular-nums text-slate-800">{{ $showView->episodeCount }}</div>
                        </div>

                        <div class="grid min-h-16 content-center gap-1 rounded-lg bg-slate-50 px-3 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase leading-none tracking-wide text-slate-500">{{ __('catalog.title.video') }}</div>
                                <x-ui.icon name="fa-solid fa-file-video text-slate-400" />
                            </div>
                            <div class="text-lg font-black leading-none tabular-nums text-slate-800">{{ $showView->mediaCount }}</div>
                        </div>
                    </div>

                </div>
            </section>
        </aside>

        <div class="min-w-0 space-y-5">
            <x-ui.panel data-title-hero :pad="false" class="overflow-hidden border-emerald-100">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                        <x-ui.icon name="fa-solid fa-arrow-left" />
                        <span>{{ __('catalog.title.back_to_catalog') }}</span>
                    </a>
                    @if ($refreshStatus !== null)
                        <span @class([
                            'inline-flex min-h-9 items-center gap-2 rounded-control px-3 py-2 text-xs font-bold',
                            'bg-sky-50 text-sky-700' => $refreshStatus['tone'] === 'active',
                            'bg-emerald-50 text-emerald-700' => $refreshStatus['tone'] === 'completed',
                            'bg-rose-50 text-rose-700' => $refreshStatus['tone'] === 'failed',
                        ]) data-title-refresh-status>
                            <x-ui.icon :name="$refreshStatus['icon']" />
                            <span>{{ $refreshStatus['label'] }}</span>
                        </span>
                    @endif
                </div>

                <article class="grid gap-5 bg-gradient-to-br from-white via-white to-emerald-50 p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:p-5">
                    <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full shadow-panel sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-500" />

                    <div class="min-w-0">
                        <h1 class="flex min-w-0 items-start gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                            <x-ui.icon name="fa-solid fa-clapperboard text-emerald-700" align="start" />
                            <span class="min-w-0 break-words">{{ $showView->displayTitle }}</span>
                        </h1>
                        @if ($showView->displayOriginalTitle !== '')
                            <div class="mt-2 break-words text-sm font-semibold text-slate-500">{{ $showView->displayOriginalTitle }}</div>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                            @if ($title->year)
                                <x-ui.taxonomy-chip :href="route('titles.year', ['year' => $title->year])" active icon="fa-solid fa-calendar-days">{{ $title->year }}</x-ui.taxonomy-chip>
                            @endif
                            @foreach ($ageRatings as $ageRating)
                                <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                            @endforeach
                            <x-ui.taxonomy-chip icon="fa-solid fa-layer-group">{{ trans_choice('catalog.counts.seasons', $seasons->count()) }}</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-list-ol">{{ trans_choice('catalog.counts.episodes', $episodeCount) }}</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-file-video">{{ trans_choice('catalog.counts.videos', $mediaCount) }}</x-ui.taxonomy-chip>
                        </div>

                        <section class="mt-5 rounded-control border border-slate-200 bg-white p-4">
                            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-700">
                                <x-ui.icon name="fa-solid fa-book-open text-slate-400" />
                                <span>{{ __('catalog.title.description') }}</span>
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $showView->displayDescription !== '' ? $showView->displayDescription : __('catalog.title.description_missing') }}</p>
                        </section>

                    </div>
                </article>
            </x-ui.panel>

            <livewire:catalog-title-player
                :catalog-title-id="$title->id"
                :wire:key="'catalog-title-player-'.$title->id"
            />

            <x-ui.panel data-title-reference :title="__('catalog.title.about')" icon="fa-solid fa-circle-info">
                @if ($actors->isNotEmpty())
                    <div>
                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                            <x-ui.icon name="fa-solid fa-user-group text-slate-400" />
                            <span>{{ __('catalog.title.cast') }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($actors->take(12) as $actor)
                                <x-ui.taxonomy-chip :taxonomy="$actor" />
                            @endforeach
                        </div>
                    </div>
                @endif

                <dl class="mt-4 divide-y divide-slate-200 text-sm">
                    @foreach ($taxonomyRows as $row)
                        @if ($row['items']->isNotEmpty())
                            <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                                <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                    <x-ui.icon name="{{ $row['icon'] ?? 'fa-solid fa-tag' }} text-slate-400" />
                                    <span>{{ $row['label'] }}</span>
                                </dt>
                                <dd class="flex flex-wrap gap-1.5">
                                    @foreach ($row['items'] as $taxonomy)
                                        <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    @endforeach
                    @if ($aliases->isNotEmpty())
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                <x-ui.icon name="fa-solid fa-signature text-slate-400" />
                                <span>{{ __('catalog.title.other_names') }}</span>
                            </dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($aliases as $alias)
                                    <x-ui.status-pill variant="muted">{{ $alias->name }}</x-ui.status-pill>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($ratings->isNotEmpty())
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                <x-ui.icon name="fa-solid fa-star text-slate-400" />
                                <span>{{ __('catalog.title.ratings') }}</span>
                            </dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($ratings as $rating)
                                    <x-ui.status-pill variant="success">
                                        {{ mb_strtoupper($rating->provider) }}: {{ $rating->rating }}@if ($rating->votes) · {{ trans_choice('catalog.counts.votes', $rating->votes) }} @endif
                                    </x-ui.status-pill>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($title->year)
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                <x-ui.icon name="fa-solid fa-calendar-days" class="text-slate-400" />
                                <span>{{ __('catalog.title.released') }}</span>
                            </dt>
                            <dd><a href="{{ route('titles.year', ['year' => $title->year]) }}" class="font-bold text-emerald-700">{{ $title->year }}</a></dd>
                        </div>
                    @endif
                </dl>

                @if ($topTaxonomies->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($topTaxonomies as $taxonomy)
                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                        @endforeach
                    </div>
                @endif
            </x-ui.panel>

            <x-ui.panel :title="__('catalog.title.recommendations')" icon="fa-solid fa-thumbs-up" :pad="false">
                @if ($recommendedTitleRecommendations->first()?->recommendedTitle)
                    <div class="space-y-3 p-3">
                        <div class="grid gap-3 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                            <div class="min-w-0">
                                <x-title-card :title="$recommendedTitleRecommendations->first()->recommendedTitle" />

                                @if ($recommendedTitleRecommendations->first()->reasonLabels() !== [])
                                    <div class="mt-2 flex flex-wrap gap-1 text-xs font-bold">
                                        @foreach ($recommendedTitleRecommendations->first()->reasonLabels() as $reasonLabel)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                                                <x-ui.icon name="fa-solid fa-check text-[0.8em]" />
                                                <span>{{ $reasonLabel }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($recommendedTitleRecommendations->skip(1)->take(4)->isNotEmpty())
                                <div class="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                                        <x-ui.icon name="fa-solid fa-ranking-star text-emerald-700" />
                                        <span>{{ __('catalog.title.closest_matches') }}</span>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($recommendedTitleRecommendations->skip(1)->take(4) as $recommendation)
                                            <div>
                                                <x-title-list-row :title="$recommendation->recommendedTitle" compact :show-description="false" />

                                                @if ($recommendation->reasonLabels() !== [])
                                                    <div class="flex flex-wrap gap-1 px-3 pb-3 text-xs font-bold">
                                                        @foreach ($recommendation->reasonLabels() as $reasonLabel)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
                                                                <x-ui.icon name="fa-solid fa-check text-[0.8em] text-emerald-700" />
                                                                <span>{{ $reasonLabel }}</span>
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if ($recommendedTitleRecommendations->skip(5)->isNotEmpty())
                            <div class="grid auto-rows-fr gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($recommendedTitleRecommendations->skip(5) as $recommendation)
                                    <div class="min-w-0">
                                        <x-title-card :title="$recommendation->recommendedTitle" />

                                        @if ($recommendation->reasonLabels() !== [])
                                            <div class="mt-2 flex flex-wrap gap-1 text-xs font-bold">
                                                @foreach ($recommendation->reasonLabels() as $reasonLabel)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
                                                        <x-ui.icon name="fa-solid fa-check text-[0.8em] text-emerald-700" />
                                                        <span>{{ $reasonLabel }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @if ($genreRecommendations->isNotEmpty() || $yearRecommendations->isNotEmpty())
                        <div class="grid min-w-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                            @if ($genreRecommendations->isNotEmpty())
                                <section @class([
                                    'min-w-0',
                                    'lg:border-r lg:border-slate-200' => $yearRecommendations->isNotEmpty(),
                                ])>
                                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                            <x-ui.icon name="fa-solid fa-tags text-emerald-700" />
                                            <span>{{ __('catalog.title.similar_genres') }}</span>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($genreRecommendations->take(6) as $recommendedTitle)
                                            <x-title-list-row :title="$recommendedTitle" readable :show-description="false" />
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if ($yearRecommendations->isNotEmpty())
                                <section class="min-w-0">
                                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                            <x-ui.icon name="fa-solid fa-calendar-days text-emerald-700" />
                                            <span>{{ __('catalog.title.same_year', ['year' => $title->year]) }}</span>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($yearRecommendations->take(6) as $recommendedTitle)
                                            <x-title-list-row :title="$recommendedTitle" readable :show-description="false" />
                                        @endforeach
                                    </div>
                                </section>
                            @endif
                        </div>
                    @else
                        <div class="p-3">
                            <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                <div class="inline-flex items-center gap-2">
                                    <x-ui.icon name="fa-solid fa-circle-info text-slate-400" />
                                    <span>{{ __('catalog.title.recommendations_missing') }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </x-ui.panel>

            @if (! empty($seo['faq']))
                <x-ui.panel :title="__('catalog.title.questions')" icon="fa-solid fa-circle-question" :pad="false">
                    <div class="divide-y divide-slate-200">
                        @foreach ($seo['faq'] as $faqItem)
                            <details class="group px-4 py-3">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-700">
                                    <span>{{ $faqItem['question'] }}</span>
                                    <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                                </summary>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $faqItem['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </x-ui.panel>
            @endif

        </div>
    </section>
</div>
