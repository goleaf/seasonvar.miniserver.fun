<section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
        @if ($errors->any())
            <div role="alert" class="col-span-full rounded-panel border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <div class="flex items-start gap-3">
                    <x-ui.icon name="fa-solid fa-triangle-exclamation" align="start" />
                    <div>
                        <div class="font-bold">Проверьте параметры каталога.</div>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
        <dialog
            id="catalog-filters"
            aria-labelledby="catalog-filter-dialog-title"
            data-catalog-filter-dialog
            wire:ignore.self
            class="fixed inset-0 m-0 h-dvh max-h-dvh w-full max-w-none overflow-y-auto border-0 bg-slate-50 p-3 backdrop:bg-slate-900/40 lg:sticky lg:inset-auto lg:top-24 lg:order-1 lg:block lg:h-auto lg:max-h-[calc(100vh-7rem)] lg:w-auto lg:self-start lg:bg-transparent lg:p-0 lg:pr-1"
        >
            <div class="sticky top-0 z-20 mb-3 flex items-center justify-between gap-3 rounded-control bg-white p-2 shadow-panel lg:hidden">
                <h2 id="catalog-filter-dialog-title" class="flex min-w-0 items-center gap-2 break-words text-base font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-sliders" class="text-emerald-700" />
                    <span>Фильтры каталога</span>
                </h2>
                <button type="button" data-catalog-filter-dialog-close class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700" aria-label="Закрыть фильтры">
                    <x-ui.icon name="fa-solid fa-xmark" />
                </button>
            </div>
            <x-ui.panel title="Фильтры каталога" icon="fa-solid fa-sliders">
                <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" class="space-y-5">
                    @foreach ($filterView->filterFormState() as $stateKey => $stateValue)
                        @if (is_array($stateValue))
                            @foreach ($stateValue as $stateItem)
                                <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
                        @endif
                    @endforeach
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <x-ui.icon name="fa-solid fa-calendar-days text-slate-400" />
                                <span>Годы</span>
                            </div>
                            @if ($filterView->selectedYears() !== [])
                                <a href="{{ route('titles.index', $filterView->yearQuery(null)) }}" wire:click.prevent="resetGroup('year')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <x-ui.icon name="fa-solid fa-rotate-left" />
                                    <span>Сбросить</span>
                                </a>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @forelse ($yearBuckets as $bucket)
                                <label @class([
                                    'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                                    'bg-emerald-50 font-bold text-emerald-700' => $filterView->isActiveYear($bucket),
                                    'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveYear($bucket),
                                ])>
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <input type="checkbox" wire:model="filters.years" name="year[]" value="{{ $filterView->bucketYear($bucket) }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked($filterView->isActiveYear($bucket))>
                                        <x-ui.icon name="fa-solid fa-calendar-days text-[0.85em] text-slate-400" />
                                        <span class="min-w-0 break-words">{{ $filterView->bucketYear($bucket) }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-bold tabular-nums">{{ $bucket->context_titles_count }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">Годы не указаны.</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <x-ui.icon name="fa-solid fa-clapperboard text-slate-400" />
                                <span>Тип публикации</span>
                            </div>
                            @if ($filterView->listState('publication_type') !== [])
                                <a href="{{ route('titles.index', $filterView->withoutCatalogState('publication_type')) }}" wire:click.prevent="resetGroup('publication_type')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <x-ui.icon name="fa-solid fa-rotate-left" />
                                    <span>Сбросить</span>
                                </a>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @forelse ($publicationTypeOptions as $option)
                                <label @class([
                                    'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                                    'bg-emerald-50 font-bold text-emerald-700' => in_array($option->value, $filterView->listState('publication_type'), true),
                                    'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! in_array($option->value, $filterView->listState('publication_type'), true),
                                ])>
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <input type="checkbox" wire:model="filters.publicationTypes" name="publication_type[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked(in_array($option->value, $filterView->listState('publication_type'), true))>
                                        <span class="min-w-0 break-words">{{ $option->label }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-bold tabular-nums">{{ $option->context_titles_count }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">Типы не указаны.</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <x-ui.icon name="fa-solid fa-closed-captioning text-slate-400" />
                                <span>Субтитры</span>
                            </div>
                            @if ($filterView->listState('subtitles') !== [])
                                <a href="{{ route('titles.index', $filterView->withoutCatalogState('subtitles')) }}" wire:click.prevent="resetGroup('subtitles')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <x-ui.icon name="fa-solid fa-rotate-left" />
                                    <span>Сбросить</span>
                                </a>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @foreach ($subtitleOptions as $option)
                                <label @class([
                                    'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                                    'bg-emerald-50 font-bold text-emerald-700' => in_array($option->value, $filterView->listState('subtitles'), true),
                                    'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! in_array($option->value, $filterView->listState('subtitles'), true),
                                ])>
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <input type="checkbox" wire:model="filters.subtitles" name="subtitles[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked(in_array($option->value, $filterView->listState('subtitles'), true))>
                                        <span class="min-w-0 break-words">{{ $option->label }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-bold tabular-nums">{{ $option->context_titles_count }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @foreach ($filterView->typeLabels as $filterType => $label)
                        <div data-catalog-filter-group>
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <div class="inline-flex min-w-0 items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                    <x-ui.icon name="{{ $filterView->icon($filterType) }} text-slate-400" />
                                    <span>{{ $label }}</span>
                                </div>
                                @if ($selectedTaxonomies->get($filterType, collect())->isNotEmpty())
                                    <a href="{{ route('titles.index', $filterView->filterQuery($filterType, null)) }}" wire:click.prevent="resetGroup('{{ $filterType }}')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                        <x-ui.icon name="fa-solid fa-rotate-left" />
                                        <span>Сбросить</span>
                                    </a>
                                @endif
                            </div>
                            @if (in_array($filterType, ['actor', 'director'], true))
                                <div data-catalog-people-combobox data-people-type="{{ $filterType }}" data-people-endpoint="{{ url('/api/catalog/people') }}" class="relative mb-2">
                                    <label class="sr-only" for="catalog-filter-search-{{ $filterType }}">Найти в группе {{ $label }}</label>
                                    <div data-focus-frame class="flex min-h-11 items-center gap-2 rounded-control border border-transparent bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                        <input
                                            id="catalog-filter-search-{{ $filterType }}"
                                            type="search"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            aria-expanded="false"
                                            aria-controls="catalog-people-options-{{ $filterType }}"
                                            autocomplete="off"
                                            maxlength="80"
                                            placeholder="Введите имя (от 2 знаков)"
                                            data-catalog-people-input
                                            class="min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-700 placeholder:text-slate-400 focus:outline-none"
                                        >
                                        <x-ui.icon name="fa-solid fa-spinner fa-spin hidden text-emerald-700" data-catalog-people-loading />
                                    </div>
                                    <div id="catalog-people-options-{{ $filterType }}" role="listbox" data-catalog-people-options class="absolute inset-x-0 top-full z-30 mt-1 hidden max-h-72 overflow-y-auto rounded-control border border-slate-200 bg-white p-1 shadow-panel"></div>
                                    <p data-catalog-people-status class="sr-only" aria-live="polite"></p>
                                </div>
                            @elseif ($filterTaxonomies->get($filterType, collect())->count() > 8)
                                <label class="sr-only" for="catalog-filter-search-{{ $filterType }}">Найти в группе {{ $label }}</label>
                                <div data-focus-frame class="mb-2 flex min-h-11 items-center gap-2 rounded-control border border-transparent bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                    <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                    <input
                                        id="catalog-filter-search-{{ $filterType }}"
                                        type="search"
                                        autocomplete="off"
                                        placeholder="Найти в группе"
                                        class="min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-700 placeholder:text-slate-400 focus:outline-none"
                                        data-catalog-filter-search
                                    >
                                </div>
                            @endif
                            <div class="space-y-1" data-catalog-filter-options>
                                @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                                    <label data-catalog-filter-option data-catalog-filter-text="{{ mb_strtolower($taxonomy->name.' '.$taxonomy->slug, 'UTF-8') }}" @class([
                                        'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition',
                                        'bg-emerald-50 font-bold text-emerald-700' => $filterView->isActiveTaxonomy($filterType, $taxonomy),
                                        'bg-transparent text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveTaxonomy($filterType, $taxonomy),
                                    ])>
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <input type="checkbox" wire:model="filters.{{ $filterType }}" name="{{ $filterType }}[]" value="{{ $taxonomy->slug }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked($filterView->isActiveTaxonomy($filterType, $taxonomy))>
                                            <x-ui.icon name="{{ $filterView->icon($filterType) }} text-[0.85em] text-slate-400" />
                                            <span class="min-w-0 break-words">{{ $taxonomy->name }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs font-bold tabular-nums">{{ $taxonomy->context_titles_count }}</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">{{ in_array($filterType, ['actor', 'director'], true) && mb_strlen($optionSearch[$filterType] ?? '') >= 2 ? 'Ничего не найдено.' : 'Нет данных.' }}</p>
                                @endforelse
                            </div>
                            <p class="hidden px-3 py-2 text-sm text-slate-500" data-catalog-filter-empty>В этой группе ничего не найдено.</p>
                        </div>
                    @endforeach

                    <div class="sticky bottom-0 -mx-1 space-y-2 bg-white px-1 pb-1 pt-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-filter" />
                            <span>Применить выбранное</span>
                        </button>
                        <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-filter-circle-xmark" />
                            <span>Сбросить фильтры</span>
                        </a>
                    </div>
                </form>
            </x-ui.panel>
        </dialog>

        <div class="order-1 min-w-0 space-y-5 lg:order-2">
            <x-ui.panel>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="inline-flex items-center gap-2 text-3xl font-bold text-slate-700">
                            <x-ui.icon name="fa-solid fa-clapperboard text-emerald-700" />
                            <span>{{ $seo['h1'] ?? 'Сериалы' }}</span>
                        </h1>

                        <a href="#catalog-filters" data-catalog-filter-dialog-open aria-controls="catalog-filters" aria-haspopup="dialog" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 lg:hidden">
                            <x-ui.icon name="fa-solid fa-sliders" />
                            <span>Фильтры · {{ $filterView->activeFilterCount() }}</span>
                        </a>

                        @if ($search !== '' || $filterView->hasActiveFilters() || $excludedTaxonomies->isNotEmpty() || $titleContext !== null || $invalidYear)
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="hidden flex-wrap items-center gap-2 sm:flex">
                                    @if ($search !== '')
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutSearchQuery)" wire:click.prevent="clearSearch" active icon="fa-solid fa-magnifying-glass">Поиск: {{ $search }} · очистить</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($titleContext !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" wire:click.prevent="clearTitleContext" active icon="fa-solid fa-clapperboard">Сериал: {{ $titleContext->title }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($filterView->selectedYears() as $selectedYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->yearQuery($selectedYear))" wire:click.prevent="removeYear({{ $selectedYear }})" active icon="fa-solid fa-calendar-days">Год: {{ $selectedYear }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @if ($invalidYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" wire:click.prevent="resetGroup('year')" active icon="fa-solid fa-calendar-days">Год: {{ $requestedYear }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($selectedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, $taxonomy->slug))" wire:click.prevent="removeTaxonomy('{{ $filterType }}', '{{ $taxonomy->slug }}')" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($excludedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->exclusionQuery($filterType, $taxonomy->slug))" wire:click.prevent="removeExcluded('{{ $filterType }}', '{{ $taxonomy->slug }}')" active icon="fa-solid fa-minus">Без {{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($filterView->listState('publication_type') as $publicationType)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('publication_type', $publicationType))" wire:click.prevent="removeChoice('publication_type', '{{ $publicationType }}')" active icon="fa-solid fa-clapperboard">Тип: {{ $filterView->publicationTypeLabel($publicationType) }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->listState('subtitles') as $subtitleValue)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('subtitles', $subtitleValue))" wire:click.prevent="removeChoice('subtitles', '{{ $subtitleValue }}')" active icon="fa-solid fa-closed-captioning">Субтитры: {{ $filterView->subtitleLabel($subtitleValue) }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->listState('quality') as $quality)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->choiceQuery('quality', $quality))" wire:click.prevent="removeChoice('quality', '{{ $quality }}')" active icon="fa-solid fa-display">Качество: {{ $quality }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($filterView->advancedFilterChips() as $chip)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogState($chip['key']))" wire:click.prevent="resetAdvanced('{{ $chip['key'] }}')" active icon="fa-solid fa-sliders">
                                            {{ $chip['label'] }}: {{ $chip['value'] }} · убрать
                                        </x-ui.taxonomy-chip>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-3 text-slate-500">
                                    <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-diagram-project text-slate-400" /> Активных фильтров: {{ $filterView->activeFilterCount() }}</span>
                                    @if ($invalidYear)
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-calendar-xmark text-amber-600" /> Ошибочный год: {{ $requestedYear }}</span>
                                    @endif
                                    @if ($filterView->selectedYears() !== [])
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-calendar-days text-slate-400" /> Годы: {{ implode(', ', $filterView->selectedYears()) }}</span>
                                    @endif
                                    @if ($titleContext !== null)
                                        <span class="hidden sm:inline"><x-ui.icon name="fa-solid fa-clapperboard text-slate-400" /> Сериал: {{ $titleContext->title }}</span>
                                    @endif
                                    <span><x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" /> {{ __('catalog.catalog.found_now', ['results' => trans_choice('catalog.counts.results', $titles->total())]) }}</span>
                                    <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="hidden items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-600 sm:inline-flex">
                                        <x-ui.icon name="fa-solid fa-rotate-left" />
                                        <span>Сбросить все</span>
                                    </a>
                                </div>
                            </div>
                        @else
                            <div class="mt-3 text-sm text-slate-500">
                                <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                {{ __('catalog.catalog.found', ['results' => trans_choice('catalog.counts.results', $titles->total())]) }}
                            </div>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applySearch" role="search" aria-label="{{ $filterView->hasActiveFilters() ? 'Искать в выбранной подборке' : 'Поиск по каталогу' }}" class="flex w-full max-w-md min-w-0 gap-2">
                        @foreach ($filterView->searchFormState() as $stateKey => $stateValue)
                            @if (is_array($stateValue))
                                @foreach ($stateValue as $stateItem)
                                    <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
                            @endif
                        @endforeach
                        <x-form.search-field
                            id="catalog-search"
                            name="q"
                            :value="$search"
                            label="Поиск по каталогу"
                            placeholder="Название, описание или тег"
                            container-class="min-w-0 flex-1"
                            wire:model.live.debounce.650ms="filters.search"
                        />
                        <button type="submit" wire:loading.attr="disabled" wire:target="filters.search,applySearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-arrow-right" />
                            <span>Найти</span>
                        </button>
                    </form>
                </div>

                <div class="mt-5 hidden gap-3 sm:grid sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('catalog.catalog.found_label') }}</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <x-ui.icon name="fa-solid fa-magnifying-glass text-emerald-700" />
                            <span class="tabular-nums">{{ $titles->total() }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('catalog.catalog.page_label') }}</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <x-ui.icon name="fa-solid fa-table-cells-large text-sky-700" />
                            <span class="tabular-nums">{{ $titles->count() }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('catalog.catalog.sort_label') }}</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <x-ui.icon name="{{ $filterView->sortIcon($sort) }} text-amber-700" />
                            <span>{{ $filterView->sortLabel($sort) }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 hidden flex-wrap gap-2 lg:flex">
                    @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
                        <a data-catalog-sort-option href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" wire:click.prevent="sortBy('{{ $sortKey }}')" @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif @class([
                            'inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold',
                            'bg-emerald-50 text-emerald-700' => $filterView->isActiveSort($sortKey),
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
                        ])>
                            <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                            <span>{{ $sortLabel }}</span>
                        </a>
                    @endforeach
                </div>

                <div class="mt-3 hidden flex-wrap items-center gap-2 text-xs font-bold lg:flex">
                    <span class="text-slate-400">Вид:</span>
                    @foreach (['grid' => 'Сетка', 'list' => 'Список'] as $viewKey => $viewLabel)
                        <a data-catalog-view-option href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" wire:click.prevent="setView('{{ $viewKey }}')" @class([
                            'inline-flex min-h-11 items-center rounded-full px-3 py-2',
                            'bg-emerald-50 text-emerald-700' => $view === $viewKey,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
                        ])>{{ $viewLabel }}</a>
                    @endforeach
                    <span class="ml-2 text-slate-400">На странице:</span>
                    @foreach ([24, 48, 96] as $pageSize)
                        <a data-catalog-page-size-option href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" wire:click.prevent="setPerPage({{ $pageSize }})" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-3 py-2 tabular-nums',
                            'bg-emerald-50 text-emerald-700' => $perPage === $pageSize,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
                        ])>{{ $pageSize }}</a>
                    @endforeach
                </div>

                <nav class="mt-4 hidden flex-wrap items-center gap-1.5 lg:flex" aria-label="Алфавитный переход по названиям">
                    <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-400">Алфавит:</span>
                    @foreach ($filterView->alphabet as $letter)
                        <a data-catalog-alphabet-option href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" wire:click.prevent="setLetter('{{ $letter }}')" @class([
                            'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-2 text-xs font-bold transition',
                            'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter($letter),
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
                        ])>{{ $letter === 'latin' ? 'A–Z' : $letter }}</a>
                    @endforeach
                </nav>

                <details data-catalog-mobile-output-controls class="group mt-4 rounded-lg bg-slate-50 p-2 lg:hidden">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-2 text-sm font-bold text-slate-700">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <x-ui.icon name="{{ $filterView->sortIcon($sort) }} text-amber-700" />
                            <span class="min-w-0 break-words">Сортировка: {{ $filterView->sortLabel($sort) }}</span>
                        </span>
                        <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
                            <a href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" wire:click.prevent="sortBy('{{ $sortKey }}')" @if ($filterView->isActiveSort($sortKey)) aria-current="true" @endif @class([
                                'inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold',
                                'bg-emerald-50 text-emerald-700' => $filterView->isActiveSort($sortKey),
                                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
                            ])>
                                <x-ui.icon name="{{ $filterView->sortIcon($sortKey) }}" />
                                <span>{{ $sortLabel }}</span>
                            </a>
                        @endforeach
                    </div>
                    <nav class="mt-3 flex flex-wrap items-center gap-1.5" aria-label="Мобильный алфавитный переход по названиям">
                        @foreach ($filterView->alphabet as $letter)
                            <a href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" wire:click.prevent="setLetter('{{ $letter }}')" @class([
                                'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-2 text-xs font-bold',
                                'bg-emerald-50 text-emerald-700' => $filterView->isActiveLetter($letter),
                                'bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
                            ])>{{ $letter === 'latin' ? 'A–Z' : $letter }}</a>
                        @endforeach
                    </nav>
                </details>

                <details class="group mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3" @if ($filterView->hasAdvancedFilters()) open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-1 text-sm font-bold text-slate-700">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <x-ui.icon name="fa-solid fa-sliders text-slate-400" />
                            <span class="min-w-0 break-words">Расширенные фильтры</span>
                        </span>
                        <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                    </summary>
                    <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @if ($titleContext !== null)
                            <input type="hidden" name="title" value="{{ $titleContext->slug }}">
                        @endif
                        @if ($search !== '')
                            <input type="hidden" name="q" value="{{ $search }}">
                        @endif
                        @foreach ($filterView->selectedFilterSlugs as $filterType => $slugs)
                            @foreach ($slugs as $slug)
                                <input type="hidden" name="{{ $filterType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach (['exclude_country', 'exclude_genre'] as $excludedType)
                            @foreach ($filterView->listState($excludedType) as $slug)
                                <input type="hidden" name="{{ $excludedType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach ($filterView->listState('year') as $selectedYear)
                            <input type="hidden" name="year[]" value="{{ $selectedYear }}">
                        @endforeach
                        @foreach (['publication_type', 'subtitles'] as $fixedGroup)
                            @foreach ($filterView->listState($fixedGroup) as $fixedValue)
                                <input type="hidden" name="{{ $fixedGroup }}[]" value="{{ $fixedValue }}">
                            @endforeach
                        @endforeach
                        @if ($sort !== 'updated')
                            <input type="hidden" name="sort" value="{{ $sort }}">
                        @endif
                        @if ($view !== 'grid')
                            <input type="hidden" name="view" value="{{ $view }}">
                        @endif
                        @if ($perPage !== 24)
                            <input type="hidden" name="per_page" value="{{ $perPage }}">
                        @endif
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Год от</span>
                            <input type="number" wire:model="filters.yearFrom" name="year_from" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->scalarState('year_from') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Год до</span>
                            <input type="number" wire:model="filters.yearTo" name="year_to" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->scalarState('year_to') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Сезонов от</span>
                            <input type="number" wire:model="filters.seasonsMin" name="seasons_min" min="0" value="{{ $filterView->scalarState('seasons_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Сезонов до</span>
                            <input type="number" wire:model="filters.seasonsMax" name="seasons_max" min="0" value="{{ $filterView->scalarState('seasons_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Серий от</span>
                            <input type="number" wire:model="filters.episodesMin" name="episodes_min" min="0" value="{{ $filterView->scalarState('episodes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Серий до</span>
                            <input type="number" wire:model="filters.episodesMax" name="episodes_max" min="0" value="{{ $filterView->scalarState('episodes_max') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Видео</span>
                            <select wire:model="filters.video" name="video" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">Любое</option>
                                <option value="available" @selected($filterView->scalarState('video') === 'available')>Есть видео</option>
                                <option value="missing" @selected($filterView->scalarState('video') === 'missing')>Нет видео</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Источник рейтинга</span>
                            <select wire:model="filters.ratingSource" name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">Любой</option>
                                <option value="kinopoisk" @selected($filterView->scalarState('rating_source') === 'kinopoisk')>КиноПоиск</option>
                                <option value="imdb" @selected($filterView->scalarState('rating_source') === 'imdb')>IMDb</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Рейтинг от</span>
                            <input type="number" wire:model="filters.ratingMin" name="rating_min" min="0" max="10" step="0.1" value="{{ $filterView->scalarState('rating_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Голосов от</span>
                            <input type="number" wire:model="filters.votesMin" name="votes_min" min="0" value="{{ $filterView->scalarState('votes_min') }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Обновлено</span>
                            <select wire:model="filters.updated" name="updated" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">За всё время</option>
                                <option value="day" @selected($filterView->scalarState('updated') === 'day')>За день</option>
                                <option value="week" @selected($filterView->scalarState('updated') === 'week')>За неделю</option>
                                <option value="month" @selected($filterView->scalarState('updated') === 'month')>За месяц</option>
                                <option value="year" @selected($filterView->scalarState('updated') === 'year')>За год</option>
                            </select>
                        </label>
                        <fieldset class="sm:col-span-2 xl:col-span-4">
                            <legend class="mb-2 text-sm font-semibold text-slate-600">Качество видео</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach (['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'] as $quality)
                                    <label class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">
                                        <input type="checkbox" wire:model="filters.qualities" name="quality[]" value="{{ $quality }}" class="h-5 w-5 accent-emerald-700" @checked(in_array($quality, $filterView->listState('quality'), true))>
                                        <span>{{ $quality }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="flex items-end sm:col-span-2 xl:col-span-4">
                            <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">
                                <x-ui.icon name="fa-solid fa-filter" />
                                <span>Применить фильтры</span>
                            </button>
                        </div>
                    </form>
                </details>
            </x-ui.panel>

            <div class="relative">
                <div wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="absolute inset-x-0 top-0 z-20 rounded-panel bg-white text-sm font-bold text-emerald-700" role="status" aria-live="polite">
                    <div class="flex min-h-24 items-center justify-center">
                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                        <span class="ml-2">Обновляем каталог…</span>
                    </div>
                </div>
                <div wire:loading.class="opacity-50" wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,clearSearch,resetAll,previousPage,nextPage,gotoPage" @class([
                'divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white' => $view === 'list',
                'grid auto-rows-fr gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-4' => $view !== 'list',
            ])>
                @forelse ($titles as $catalogTitle)
                    @if ($view === 'list')
                        <x-title-list-row wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" readable />
                    @else
                        <x-title-card wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" />
                    @endif
                @empty
                    <x-ui.panel class="col-span-full border-dashed">
                        <div class="flex flex-col gap-4">
                            <div>
                                <div class="inline-flex items-center gap-2 text-base font-bold text-slate-700">
                                    <x-ui.icon name="fa-solid fa-magnifying-glass text-slate-400" />
                                    @if ($insufficientSearch)
                                        <span>Запрос «{{ $search }}» слишком общий.</span>
                                    @elseif ($search !== '')
                                        <span>По запросу «{{ $search }}» ничего не найдено.</span>
                                    @else
                                        <span>Ничего не найдено.</span>
                                    @endif
                                </div>
                                @if ($insufficientSearch)
                                    <p class="mt-1 text-sm text-slate-500">Добавьте название, имя актера, режиссера или жанр.</p>
                                @elseif ($search !== '')
                                    <p class="mt-1 text-sm text-slate-500">Проверьте написание или измените фильтры.</p>
                                @else
                                    <p class="mt-1 text-sm text-slate-500">Измените или сбросьте выбранные фильтры.</p>
                                @endif
                            </div>
                            @if ($searchSuggestions->isNotEmpty())
                                <div aria-labelledby="catalog-search-suggestions-title" class="rounded-control bg-emerald-50 p-3">
                                    <div id="catalog-search-suggestions-title" class="text-sm font-bold text-emerald-800">Возможно, подойдет</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($searchSuggestions as $suggestion)
                                            <a
                                                href="{{ route('titles.index', array_merge($filterView->withoutSearchQuery, ['q' => $suggestion->suggestion_name])) }}"
                                                class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"
                                            >
                                                <x-ui.icon name="fa-solid fa-wand-magic-sparkles" />
                                                <span class="min-w-0 break-words">{{ $suggestion->suggestion_name }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                @if ($search !== '')
                                    <a href="{{ route('titles.index', $filterView->withoutSearchQuery) }}" wire:click.prevent="clearSearch" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-magnifying-glass-minus" />
                                        <span>Очистить поиск</span>
                                    </a>
                                @endif
                                @if ($filterView->hasActiveFilters() || $titleContext !== null || $filterView->selectedYears() !== [] || $invalidYear)
                                    <a href="{{ route('titles.index', $filterView->withoutFiltersQuery) }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-filter-circle-xmark" />
                                        <span>Убрать фильтры</span>
                                    </a>
                                @endif
                                <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100">
                                    <x-ui.icon name="fa-solid fa-table-cells-large" />
                                    <span>Показать весь каталог</span>
                                </a>
                            </div>
                        </div>
                    </x-ui.panel>
                @endforelse
                </div>
            </div>

            <div>
                {{ $titles->links() }}
            </div>
        </div>
</section>
