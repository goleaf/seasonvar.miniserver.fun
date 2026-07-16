<div class="relative space-y-6" data-catalog-discovery-page data-recommendation-type="{{ $type }}">
    <div
        wire:loading.flex
        wire:target="period,ratingSource,genre,country,tag,actor,director,translation,studio,yearFrom,yearTo,quality,subtitles,ratingMin,votesMin,clearFilters,previousPage,nextPage,refreshRecommendations,setFeedback,undoFeedback"
        class="fixed inset-x-3 bottom-4 z-40 mx-auto max-w-md items-center justify-center gap-3 rounded-panel bg-slate-900/95 px-5 py-4 text-sm font-bold text-white shadow-xl sm:inset-x-auto sm:right-6"
        role="status"
        aria-live="polite"
    >
        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
        <span>{{ __('recommendations.page.loading') }}</span>
    </div>

    <header class="overflow-hidden rounded-panel bg-white shadow-panel">
        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
            <nav aria-label="{{ __('recommendations.page.breadcrumbs') }}" class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-500">
                <a href="{{ route('home') }}" class="min-h-11 py-3 hover:text-emerald-700">{{ __('catalog.navigation.home') }}</a>
                <x-ui.icon name="fa-solid fa-chevron-right text-[10px] text-slate-300" />
                <span aria-current="page" class="py-3 text-slate-700">{{ __('recommendations.navigation.discover') }}</span>
            </nav>
        </div>
        <div class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:px-8 lg:py-8">
            <div class="max-w-4xl">
                <div class="inline-flex items-center gap-2 text-sm font-black uppercase tracking-[0.14em] text-emerald-700">
                    <x-ui.icon name="fa-solid fa-compass" />
                    <span>{{ __('recommendations.page.eyebrow') }}</span>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">{{ $presentation['title'] }}</h1>
                <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600 sm:text-lg">{{ $presentation['description'] }}</p>
            </div>
            <button
                type="button"
                wire:click="refreshRecommendations"
                wire:loading.attr="disabled"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="fa-solid fa-arrows-rotate" />
                <span>{{ $type === 'random' ? __('recommendations.page.show_another') : __('recommendations.page.refresh') }}</span>
            </button>
        </div>
    </header>

    <nav aria-label="{{ __('recommendations.navigation.all') }}" class="flex snap-x gap-2 overflow-x-auto rounded-panel bg-white p-3 shadow-sm shadow-slate-200/70 sm:flex-wrap sm:overflow-visible">
        @foreach ($typeLinks as $typeLink)
            <a
                href="{{ $typeLink['url'] }}"
                wire:navigate
                @class([
                    'inline-flex min-h-11 shrink-0 snap-start items-center rounded-control px-4 py-2 text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                    'bg-emerald-700 text-white' => $typeLink['active'],
                    'bg-slate-50 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700' => ! $typeLink['active'],
                ])
                @if ($typeLink['active']) aria-current="page" @endif
            >{{ $typeLink['label'] }}</a>
        @endforeach
    </nav>

    <section aria-labelledby="discovery-filters" class="rounded-panel bg-white p-4 shadow-sm shadow-slate-200/70 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="discovery-filters" class="flex items-center gap-2 text-lg font-black text-slate-900">
                    <x-ui.icon name="fa-solid fa-sliders text-emerald-700" />
                    <span>{{ __('recommendations.page.filters') }}</span>
                </h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('recommendations.page.controls') }}</p>
            </div>
            @if ($hasFilters)
                <button type="button" wire:click="clearFilters" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 hover:text-emerald-700">
                    <x-ui.icon name="fa-solid fa-rotate-left" />
                    <span>{{ __('recommendations.page.clear_filters') }}</span>
                </button>
            @endif
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.genre') }}
                <select wire:model.live="genre" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                    <option value="">{{ __('recommendations.page.any') }}</option>
                    @foreach ($genres as $genreOption)
                        <option value="{{ $genreOption->slug }}">{{ $genreOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.country') }}
                <select wire:model.live="country" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                    <option value="">{{ __('recommendations.page.any') }}</option>
                    @foreach ($countries as $countryOption)
                        <option value="{{ $countryOption->slug }}">{{ $countryOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.year_from') }}
                <input type="number" wire:model.blur="yearFrom" min="1900" max="{{ $maximumYear }}" inputmode="numeric" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
            </label>
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.year_to') }}
                <input type="number" wire:model.blur="yearTo" min="1900" max="{{ $maximumYear }}" inputmode="numeric" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
            </label>
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.quality') }}
                <select wire:model.live="quality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                    <option value="">{{ __('recommendations.page.any') }}</option>
                    @foreach ($qualityOptions as $qualityOption)
                        <option value="{{ $qualityOption }}">{{ $qualityOption }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.subtitles') }}
                <select wire:model.live="subtitles" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                    <option value="">{{ __('recommendations.page.any') }}</option>
                    <option value="available">{{ __('recommendations.page.subtitles_available') }}</option>
                </select>
            </label>
        </div>

        <details class="group mt-4 rounded-control border border-slate-200 bg-slate-50">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-700">
                <span>{{ __('recommendations.page.more_filters') }}</span>
                <x-ui.icon name="fa-solid fa-chevron-down transition group-open:rotate-180" />
            </summary>
            <div class="grid gap-4 border-t border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                @foreach ([
                    ['property' => 'tag', 'label' => __('recommendations.page.tag'), 'options' => $tags],
                    ['property' => 'actor', 'label' => __('recommendations.page.actor'), 'options' => $actors],
                    ['property' => 'director', 'label' => __('recommendations.page.director'), 'options' => $directors],
                    ['property' => 'translation', 'label' => __('recommendations.page.translation'), 'options' => $translations],
                    ['property' => 'studio', 'label' => __('recommendations.page.studio'), 'options' => $studios],
                ] as $taxonomyFilter)
                    <label class="min-w-0 text-sm font-bold text-slate-700">{{ $taxonomyFilter['label'] }}
                        <select wire:model.live="{{ $taxonomyFilter['property'] }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal text-slate-700 focus:border-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                            <option value="">{{ __('recommendations.page.any') }}</option>
                            @foreach ($taxonomyFilter['options'] as $taxonomyOption)
                                <option value="{{ $taxonomyOption->slug }}">{{ $taxonomyOption->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </details>

        <details class="group mt-4 rounded-control border border-slate-200 bg-slate-50">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-700">
                <span>{{ __('recommendations.types.top_rated.title') }}</span>
                <x-ui.icon name="fa-solid fa-chevron-down transition group-open:rotate-180" />
            </summary>
            <div class="grid gap-4 border-t border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.rating_source') }}
                    <select wire:model.live="ratingSource" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                        <option value="kinopoisk">{{ __('recommendations.page.rating_kinopoisk') }}</option>
                        <option value="imdb">{{ __('recommendations.page.rating_imdb') }}</option>
                        <option value="portal">{{ __('recommendations.page.portal_rating') }}</option>
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.rating_min') }}
                    <input type="number" wire:model.blur="ratingMin" min="0" max="10" step="0.1" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="text-sm font-bold text-slate-700">{{ __('recommendations.page.votes_min') }}
                    <input type="number" wire:model.blur="votesMin" min="0" max="100000000" inputmode="numeric" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                </label>
                @if ($type === 'trending')
                    <label class="text-sm font-bold text-slate-700">{{ __('recommendations.types.trending.title') }}
                        <select wire:model.live="period" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                            <option value="day">{{ __('recommendations.page.period_day') }}</option>
                            <option value="week">{{ __('recommendations.page.period_week') }}</option>
                            <option value="month">{{ __('recommendations.page.period_month') }}</option>
                        </select>
                    </label>
                @endif
            </div>
        </details>
    </section>

    @if ($notice)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status" aria-live="polite">
            <span>{{ $notice }}</span>
            @if ($lastFeedbackTitleId)
                <button type="button" wire:click="undoFeedback" class="min-h-11 rounded-control px-3 py-2 font-bold text-emerald-800 underline hover:bg-emerald-100">{{ __('recommendations.feedback.undo') }}</button>
            @endif
        </div>
    @endif

    @if ($errors->has('recommendations') || $errors->has('recommendationFeedback'))
        <div role="alert" aria-live="assertive" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
            {{ $errors->first('recommendations') ?: $errors->first('recommendationFeedback') }}
        </div>
    @endif

    @if ($type === 'personalized' && ! $isAuthenticated)
        <div class="flex flex-col gap-3 rounded-panel border border-sky-200 bg-sky-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm leading-6 text-sky-900">{{ __('recommendations.page.anonymous_personalized') }}</p>
            <a href="{{ route('login') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-sky-700 px-4 py-2 text-sm font-bold text-white hover:bg-sky-600">
                <x-ui.icon name="fa-solid fa-right-to-bracket" />
                <span>{{ __('recommendations.page.sign_in') }}</span>
            </a>
        </div>
    @elseif ($result->coldStart)
        <div class="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900" role="status">{{ __('recommendations.page.cold_start') }}</div>
    @elseif ($result->personalized)
        <div class="rounded-panel border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">{{ __('recommendations.page.personalized_notice') }}</div>
    @endif

    @if ($type === 'upcoming')
        <div class="rounded-panel border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900">{{ __('recommendations.page.upcoming_notice') }}</div>
    @endif

    <section aria-labelledby="discovery-results" aria-busy="false" class="scroll-mt-40">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="discovery-results" class="text-xl font-black text-slate-900">{{ __('recommendations.page.results') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ trans_choice('recommendations.page.result_count', $viewItems->count()) }}</p>
            </div>
            <span class="text-sm font-semibold text-slate-500">{{ __('recommendations.page.page_number', ['page' => $result->page]) }}</span>
        </div>

        @if ($viewItems->isEmpty())
            <div class="mt-4 rounded-panel bg-white px-5 py-12 text-center shadow-sm shadow-slate-200/70" role="status" aria-live="polite">
                <x-ui.icon name="fa-solid fa-compass text-3xl text-slate-300" />
                <h3 class="mt-4 text-lg font-black text-slate-800">{{ __('recommendations.page.empty') }}</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ __('recommendations.page.empty_hint') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    @if ($hasFilters)
                        <button type="button" wire:click="clearFilters" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">{{ __('recommendations.page.clear_filters') }}</button>
                    @endif
                    <a href="{{ $popularUrl }}" wire:navigate class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('recommendations.page.browse_popular') }}</a>
                    <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center rounded-control px-4 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-50">{{ __('recommendations.page.open_catalog') }}</a>
                </div>
            </div>
        @else
            <ol class="mt-4 grid min-w-0 gap-4 xl:grid-cols-2" aria-label="{{ $presentation['accessibility'] }}" data-recommendation-list>
                @foreach ($viewItems as $recommendationItem)
                    <li wire:key="discovery-{{ $type }}-{{ $result->page }}-{{ $recommendationItem->title->id }}" class="min-w-0 overflow-hidden rounded-panel border border-slate-200 bg-white shadow-sm shadow-slate-200/60" data-recommendation-row>
                        <x-catalog.title-card
                            :title="$recommendationItem->title"
                            layout="recommendation"
                            :rank="$recommendationItem->rank"
                            :reason-labels="$recommendationItem->reasonLabels"
                        />
                        @if ($recommendationItem->canDismiss)
                            <details class="relative z-20 border-t border-slate-100 bg-slate-50 px-3 py-2">
                                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-bold text-slate-600 hover:text-emerald-700">
                                    <x-ui.icon name="fa-solid fa-sliders" />
                                    <span>{{ __('recommendations.feedback.menu') }}</span>
                                </summary>
                                <div class="flex flex-wrap gap-2 pb-2">
                                    <button type="button" wire:click="setFeedback({{ $recommendationItem->title->id }}, 'not_interested')" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-amber-50 hover:text-amber-800 disabled:opacity-60">{{ __('recommendations.feedback.not_interested') }}</button>
                                    <button type="button" wire:click="setFeedback({{ $recommendationItem->title->id }}, 'blacklisted')" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-800 disabled:opacity-60">{{ __('recommendations.feedback.blacklist') }}</button>
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($result->page > 1 || $result->hasMore)
            <nav class="mt-7 flex flex-wrap items-center justify-center gap-3" aria-label="{{ __('catalog.directories.pagination') }}">
                <button type="button" wire:click="previousPage" @disabled($result->page <= 1) class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-50">
                    <x-ui.icon name="fa-solid fa-arrow-left" />
                    <span>{{ __('recommendations.page.previous') }}</span>
                </button>
                <span class="text-sm font-bold text-slate-600">{{ __('recommendations.page.page_number', ['page' => $result->page]) }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $result->hasMore) class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-50">
                    <span>{{ __('recommendations.page.next') }}</span>
                    <x-ui.icon name="fa-solid fa-arrow-right" />
                </button>
            </nav>
        @endif
    </section>
</div>
