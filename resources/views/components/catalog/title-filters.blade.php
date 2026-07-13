                <form method="GET" action="{{ route('titles.index') }}" wire:submit="applyFilters" wire:loading.class="opacity-60" class="relative space-y-5 transition-opacity">
                    <div wire:loading.delay.flex role="status" aria-live="polite" class="sticky top-0 z-20 min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700">
                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                        <span>Обновляем подборку…</span>
                    </div>
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
                                <a href="{{ route('titles.index', $filterView->yearQuery(null)) }}" rel="nofollow" wire:click.prevent="resetGroup('year')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
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
                                        <input type="checkbox" wire:model.live="filters.years" name="year[]" value="{{ $filterView->bucketYear($bucket) }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked($filterView->isActiveYear($bucket))>
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
                                <a href="{{ route('titles.index', $filterView->withoutCatalogState('publication_type')) }}" rel="nofollow" wire:click.prevent="resetGroup('publication_type')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
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
                                        <input type="checkbox" wire:model.live="filters.publicationTypes" name="publication_type[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked(in_array($option->value, $filterView->listState('publication_type'), true))>
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
                                <a href="{{ route('titles.index', $filterView->withoutCatalogState('subtitles')) }}" rel="nofollow" wire:click.prevent="resetGroup('subtitles')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
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
                                        <input type="checkbox" wire:model.live="filters.subtitles" name="subtitles[]" value="{{ $option->value }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked(in_array($option->value, $filterView->listState('subtitles'), true))>
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
                                    <a href="{{ route('titles.index', $filterView->filterQuery($filterType, null)) }}" rel="nofollow" wire:click.prevent="resetGroup('{{ $filterType }}')" class="inline-flex min-h-11 shrink-0 items-center gap-1 px-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
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
                                    <div id="catalog-people-options-{{ $filterType }}" role="listbox" data-catalog-people-options class="absolute inset-x-0 top-full z-30 mt-1 hidden rounded-control border border-slate-200 bg-white p-1 shadow-panel"></div>
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
                                            <input type="checkbox" wire:model.live="filters.{{ $filterType }}" name="{{ $filterType }}[]" value="{{ $taxonomy->slug }}" class="h-5 w-5 shrink-0 accent-emerald-700" @checked($filterView->isActiveTaxonomy($filterType, $taxonomy))>
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
                        <noscript>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                                <x-ui.icon name="fa-solid fa-filter" />
                                <span>Применить выбранное</span>
                            </button>
                        </noscript>
                        <a href="{{ route('titles.index') }}" wire:click.prevent="resetAll" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-filter-circle-xmark" />
                            <span>Сбросить фильтры</span>
                        </a>
                    </div>
                </form>
