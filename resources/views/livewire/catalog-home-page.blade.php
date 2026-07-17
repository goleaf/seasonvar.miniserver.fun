<div class="space-y-5">
        <h1 class="sr-only">{{ __('home.title') }}</h1>

        <div data-home-metrics class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-stat :label="__('home.statistics.titles')" :value="$stats['titles']" icon="fa-solid fa-clapperboard" />
            <x-stat :label="__('home.statistics.episodes')" :value="$stats['episodes']" icon="fa-solid fa-circle-play" />
            <x-stat :label="__('home.statistics.videos')" :value="$stats['videos']" icon="fa-solid fa-file-video" />
            <x-stat :label="__('home.statistics.genres')" :value="$stats['genres']" icon="fa-solid fa-masks-theater" />
            <x-stat :label="__('home.statistics.countries')" :value="$stats['countries']" icon="fa-solid fa-earth-europe" />
        </div>

        <section class="grid gap-5 xl:grid-cols-[300px_minmax(0,1fr)] 2xl:grid-cols-[320px_minmax(0,1fr)]">
            <div class="min-w-0 space-y-5 xl:order-2">
                @if ($featuredCollections->isNotEmpty())
                    <section aria-labelledby="home-featured-collections">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h2 id="home-featured-collections" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-star text-amber-500" />{{ __('collections.home.featured') }}</h2>
                            <a href="{{ $collectionsUrl }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-600">{{ __('collections.navigation.public_collections') }}</a>
                        </div>
                        <div class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($featuredCollections as $featuredCollection)
                                <x-collections.collection-card :collection="$featuredCollection" :timezone="$accountTimezone" />
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($homeRecommendationItems->isNotEmpty())
                    <x-ui.panel :title="$homeRecommendationPresentation['title']" icon="fa-solid fa-compass" :pad="false">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-sm leading-6 text-slate-600">{{ $homeRecommendationPresentation['description'] }}</p>
                                <a href="{{ $discoveryUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-50">
                                    <span>{{ __('recommendations.navigation.all') }}</span>
                                    <x-ui.icon name="fa-solid fa-arrow-right" />
                                </a>
                            </div>
                        </div>
                        <ol class="divide-y divide-slate-200" aria-label="{{ $homeRecommendationPresentation['accessibility'] }}" data-home-recommendations>
                            @foreach ($homeRecommendationItems as $recommendationItem)
                                <li data-recommendation-row>
                                    <x-catalog.title-card :title="$recommendationItem->title" layout="recommendation" :rank="$recommendationItem->rank" :reason-labels="$recommendationItem->reasonLabels" />
                                </li>
                            @endforeach
                        </ol>
                    </x-ui.panel>
                @endif

                <x-ui.panel :title="__('home.sections.latest_updates')" icon="fa-solid fa-clock-rotate-left" :pad="false">
                    <div data-home-latest-updates-list aria-label="{{ __('home.accessibility.latest_updates') }}" class="divide-y divide-slate-200">
                        @forelse ($latestByDate as $date => $titlesForDate)
                            <div class="flex items-center gap-2 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600">
                                <x-ui.icon name="fa-solid fa-calendar-days text-slate-400" />
                                <span>{{ $date }}</span>
                            </div>

                            @foreach ($titlesForDate as $catalogTitle)
                                <x-catalog.title-card :title="$catalogTitle" layout="list" :show-description="false" />
                            @endforeach
                        @empty
                            <div class="p-6 text-sm text-slate-500">
                                {{ __('home.empty_states.titles') }}
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel :title="__('home.sections.new_episodes')" icon="fa-solid fa-circle-play" :pad="false">
                    <div aria-label="{{ __('home.accessibility.new_episodes') }}" class="divide-y divide-slate-200">
                        @forelse ($latestReleaseGroups as $releaseGroup)
                            <x-catalog.latest-media-card
                                :title="$releaseGroup['title']"
                                :episodes="$releaseGroup['episodes']"
                                :media="$releaseGroup['media']"
                                :timezone="$accountTimezone"
                            />
                        @empty
                            <div class="p-6 text-sm text-slate-500">
                                {{ __('home.empty_states.episodes') }}
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel :title="__('home.sections.watch_now')" icon="fa-solid fa-file-video" :pad="false">
                    <div aria-label="{{ __('home.accessibility.watch_now') }}" class="divide-y divide-slate-200">
                        @forelse ($videoTitles as $catalogTitle)
                            <x-catalog.title-card :title="$catalogTitle" layout="list" :show-description="false" />
                        @empty
                            <div class="p-6 text-sm text-slate-500">
                                {{ __('home.empty_states.videos') }}
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>
            </div>

            <aside class="space-y-4 xl:order-1">
                <x-ui.panel :title="__('home.sections.navigation')" icon="fa-solid fa-compass">
                    <nav aria-label="{{ __('home.accessibility.discovery_navigation') }}" class="space-y-2">
                        <a href="{{ route('titles.index') }}" class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100">
                            <x-ui.icon name="fa-solid fa-list" />
                            <span>{{ __('home.navigation.all_titles') }}</span>
                        </a>
                        <a href="{{ $topRatedUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-ranking-star" />
                            <span>{{ __('home.navigation.top_titles') }}</span>
                        </a>
                        <a href="{{ $recentlyAddedUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-star" />
                            <span>{{ __('home.navigation.new_titles') }}</span>
                        </a>
                        <a href="{{ $continueWatchingUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-circle-play" />
                            <span>{{ __('home.navigation.continue_watching') }}</span>
                        </a>
                        <a href="{{ $upcomingUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-calendar-check" />
                            <span>{{ __('home.navigation.upcoming') }}</span>
                        </a>
                        <a href="{{ $randomUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-shuffle" />
                            <span>{{ __('home.navigation.random') }}</span>
                        </a>
                        <a href="{{ $discoveryUrl }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-compass" />
                            <span>{{ __('home.navigation.recommendations') }}</span>
                        </a>
                        @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                            <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                <span class="inline-flex items-center gap-2">
                                    <x-ui.icon name="fa-solid fa-closed-captioning" />
                                    <span>{{ __('home.navigation.subtitles') }}</span>
                                </span>
                                <x-localized-number :value="$subtitleTag->catalog_titles_count" class="text-xs text-slate-400" />
                            </a>
                        @else
                            <x-ui.taxonomy-chip muted count="0" icon="fa-solid fa-closed-captioning">{{ __('home.navigation.subtitles') }}</x-ui.taxonomy-chip>
                        @endif
                        <a href="{{ route('titles.index', ['genre' => 'otecestvennye']) }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-flag" />
                            <span>{{ __('home.navigation.domestic') }}</span>
                        </a>
                    </nav>
                </x-ui.panel>

                <x-ui.panel :title="__('home.sections.countries')" icon="fa-solid fa-earth-europe">
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                        @forelse ($countries->take(12) as $country)
                            <a href="{{ $country->detail_url }}" class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <x-ui.icon name="fa-solid fa-earth-europe text-slate-400" />
                                    <span class="min-w-0 break-words">{{ $country->name }}</span>
                                </span>
                                <x-localized-number :value="$country->catalog_titles_count" class="shrink-0 text-xs text-slate-500" />
                            </a>
                        @empty
                            <span class="text-sm text-slate-500">{{ __('home.empty_states.countries') }}</span>
                        @endforelse
                    </div>
                    @if ($countries->count() > 12)
                        <details class="group mt-3 rounded-control border border-slate-200 bg-slate-50">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 font-bold text-slate-700">
                                <span>{{ __('home.actions.show_all_countries') }}</span>
                                <x-ui.icon name="fa-solid fa-chevron-down transition group-open:rotate-180" />
                            </summary>
                            <div class="grid gap-2 border-t border-slate-200 p-3 sm:grid-cols-2 xl:grid-cols-1">
                                @foreach ($countries->skip(12) as $country)
                                    <a href="{{ $country->detail_url }}" class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <x-ui.icon name="fa-solid fa-earth-europe text-slate-400" />
                                            <span class="min-w-0 break-words">{{ $country->name }}</span>
                                        </span>
                                        <x-localized-number :value="$country->catalog_titles_count" class="shrink-0 text-xs text-slate-500" />
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </x-ui.panel>

                <x-ui.panel :title="__('home.sections.genres')" icon="fa-solid fa-masks-theater">
                    <div class="flex flex-wrap gap-2">
                        @forelse ($genres->take(14) as $genre)
                            <x-ui.taxonomy-chip :taxonomy="$genre" :count="$genre->catalog_titles_count" />
                        @empty
                            <span class="text-sm text-slate-500">{{ __('home.empty_states.genres') }}</span>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel :title="__('home.sections.years')" icon="fa-solid fa-calendar-days">
                    <div class="flex flex-wrap gap-2">
                        @forelse ($yearBuckets as $bucket)
                            <a href="{{ route('titles.year', ['year' => $bucket->year]) }}" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                <x-ui.icon name="fa-solid fa-calendar-days text-[0.8em] text-slate-400" />
                                <span>{{ $bucket->year }}</span>
                                <x-localized-number :value="$bucket->titles_count" class="text-slate-400" />
                            </a>
                        @empty
                            <span class="text-sm text-slate-500">{{ __('home.empty_states.years') }}</span>
                        @endforelse
                    </div>
                </x-ui.panel>
            </aside>
        </section>
</div>
