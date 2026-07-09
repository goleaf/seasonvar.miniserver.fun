<div wire:poll.1s="refreshStats" class="space-y-5" data-livewire-stats-dashboard>
    <section class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm shadow-slate-200/60">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <h1 class="inline-flex items-center gap-2 text-2xl font-black text-slate-700">
                    <i class="fa-solid fa-chart-simple text-emerald-700" aria-hidden="true"></i>
                    <span>Сводка каталога</span>
                </h1>
                <p class="mt-2 max-w-5xl text-sm leading-6 text-slate-500">
                    Сериалы, сезоны, серии, видео, источники, обновления, качество данных и разделы базы.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                <span @class([
                    'inline-flex items-center gap-2 rounded-lg px-3 py-2 ring-1',
                    'bg-amber-50 text-amber-700 ring-amber-100' => $snapshotMeta['is_stale'] ?? false,
                    'bg-emerald-50 text-emerald-700 ring-emerald-100' => ! ($snapshotMeta['is_stale'] ?? false),
                ])>
                    <i @class([
                        'fa-solid',
                        'fa-triangle-exclamation' => $snapshotMeta['is_stale'] ?? false,
                        'fa-arrows-rotate' => ! ($snapshotMeta['is_stale'] ?? false),
                    ]) aria-hidden="true"></i>
                    <span>{{ $snapshotMeta['message'] ?? 'Данные обновляются каждую секунду.' }}</span>
                </span>
                <span class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-500 ring-1 ring-slate-200">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                    <span>Показано: {{ $snapshotMeta['served_at_display'] ?? '' }}</span>
                </span>
                <span class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-500 ring-1 ring-slate-200">
                    <i class="fa-solid fa-database" aria-hidden="true"></i>
                    <span>Снимок: {{ $snapshotMeta['built_at_display'] ?? '' }}</span>
                </span>
            </div>
        </div>
    </section>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (($stats['headlineStats'] ?? []) as $stat)
            <x-stat wire:key="headline-{{ $loop->index }}" :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" />
        @endforeach
    </div>

    <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse (($stats['statsHealthCards'] ?? []) as $card)
            <article wire:key="health-{{ $loop->index }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs font-bold uppercase text-slate-400">{{ $card['label'] }}</div>
                        <div class="mt-2 break-words text-2xl font-black text-slate-700">{{ $card['value'] }}</div>
                        <div class="mt-1 text-sm leading-5 text-slate-500">{{ $card['meta'] }}</div>
                    </div>
                    <span @class([
                        'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ring-1',
                        'bg-emerald-50 text-emerald-700 ring-emerald-100' => $card['tone'] === 'success',
                        'bg-rose-50 text-rose-700 ring-rose-100' => $card['tone'] === 'danger',
                        'bg-amber-50 text-amber-700 ring-amber-100' => $card['tone'] === 'warning',
                        'bg-sky-50 text-sky-700 ring-sky-100' => $card['tone'] === 'sky',
                        'bg-slate-50 text-slate-600 ring-slate-200' => in_array($card['tone'], ['slate', 'muted'], true),
                    ])>
                        <i class="{{ $card['icon'] }}" aria-hidden="true"></i>
                    </span>
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                Сводка состояния пока не собрана.
            </div>
        @endforelse
    </section>

    <section class="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,0.75fr)]">
        <x-ui.panel title="Последние обновленные сериалы" subtitle="Сериалы с постерами, которые недавно попали в каталог." icon="fa-regular fa-images">
            <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                @forelse (($stats['statsPosterRows'] ?? []) as $title)
                    <a wire:key="poster-{{ $title['id'] }}" href="{{ $title['href'] }}" class="group overflow-hidden rounded-lg border border-slate-200 bg-slate-50 transition hover:border-emerald-300 hover:bg-emerald-50">
                        <div class="aspect-[2/3] bg-white">
                            @if ($title['poster_src'])
                                <img src="{{ $title['poster_src'] }}" alt="Постер {{ $title['title'] }}" loading="lazy" decoding="async" class="h-full w-full object-contain">
                            @else
                                <div class="grid h-full place-items-center px-3 text-center text-xs font-semibold text-slate-400">
                                    <span class="inline-flex flex-col items-center gap-2">
                                        <i class="fa-regular fa-image text-xl text-slate-300" aria-hidden="true"></i>
                                        <span>Нет постера</span>
                                    </span>
                                </div>
                            @endif
                        </div>
                        <div class="p-3">
                            <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                <i class="{{ $title['icon'] }} text-slate-400" aria-hidden="true"></i>
                                <span>{{ $title['year'] }}</span>
                            </div>
                            <div class="mt-1 text-sm font-bold leading-5 text-slate-700 group-hover:text-emerald-700">{{ $title['title'] }}</div>
                            <div class="mt-2 text-xs font-semibold text-slate-400">{{ $title['meta'] }}</div>
                        </div>
                    </a>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 sm:col-span-2 2xl:col-span-4">
                        Сериалы с постерами пока не найдены.
                    </div>
                @endforelse
            </div>
        </x-ui.panel>

        <x-ui.panel title="Требуют внимания" subtitle="Сериалы, которые полезно проверить после обновления." icon="fa-solid fa-triangle-exclamation">
            <div class="space-y-2">
                @forelse (($stats['statsIssueRows'] ?? []) as $title)
                    <a wire:key="issue-{{ $title['label'] }}-{{ $title['id'] }}" href="{{ $title['href'] }}" class="flex min-w-0 gap-3 rounded-lg border border-slate-200 bg-white p-2 transition hover:border-emerald-300 hover:bg-emerald-50">
                        <div class="h-20 w-14 shrink-0 overflow-hidden rounded-md bg-slate-100 ring-1 ring-slate-200">
                            @if ($title['poster_src'])
                                <img src="{{ $title['poster_src'] }}" alt="Постер {{ $title['title'] }}" loading="lazy" decoding="async" class="h-full w-full object-contain">
                            @else
                                <div class="grid h-full place-items-center text-slate-300">
                                    <i class="fa-regular fa-image" aria-hidden="true"></i>
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                                <span @class([
                                    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md ring-1',
                                    'bg-rose-50 text-rose-700 ring-rose-100' => $title['tone'] === 'danger',
                                    'bg-amber-50 text-amber-700 ring-amber-100' => $title['tone'] === 'warning',
                                    'bg-slate-50 text-slate-600 ring-slate-200' => ! in_array($title['tone'], ['danger', 'warning'], true),
                                ])>
                                    <i class="{{ $title['icon'] }}" aria-hidden="true"></i>
                                </span>
                                <span @class([
                                    'text-rose-700' => $title['tone'] === 'danger',
                                    'text-amber-700' => $title['tone'] === 'warning',
                                    'text-slate-600' => ! in_array($title['tone'], ['danger', 'warning'], true),
                                ])>{{ $title['label'] }}</span>
                            </div>
                            <div class="mt-1 text-sm font-bold leading-5 text-slate-700">{{ $title['title'] }}</div>
                            <div class="mt-1 text-xs font-semibold text-slate-400">{{ $title['year'] }} · {{ $title['meta'] }}</div>
                        </div>
                    </a>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                        Сериалов, требующих проверки, сейчас нет.
                    </div>
                @endforelse
            </div>
        </x-ui.panel>
    </section>

    <x-ui.panel title="Готовность по данным" icon="fa-solid fa-chart-simple">
        <div class="grid gap-3 md:grid-cols-2">
            @forelse (($stats['qualityProgressRows'] ?? []) as $row)
                <article wire:key="quality-progress-{{ $loop->index }}" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-700">{{ $row['label'] }}</div>
                            <div class="mt-1 text-xs font-semibold text-slate-500">{{ $row['severity_label'] }}</div>
                        </div>
                        <div class="sm:text-right">
                            <div @class([
                                'font-bold',
                                'text-rose-700' => $row['severity'] === 'critical',
                                'text-amber-700' => $row['severity'] === 'warning',
                                'text-slate-700' => ! in_array($row['severity'], ['critical', 'warning'], true),
                            ])>{{ $row['display'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $row['meta'] }}</div>
                        </div>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white ring-1 ring-slate-200">
                        <div @class([
                            'h-full rounded-full',
                            'bg-rose-500' => $row['severity'] === 'critical',
                            'bg-amber-500' => $row['severity'] === 'warning',
                            'bg-slate-400' => ! in_array($row['severity'], ['critical', 'warning'], true),
                        ]) style="width: {{ $row['percent_value'] }}%"></div>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 md:col-span-2">
                    Нет заметных проблем качества данных.
                </div>
            @endforelse
        </div>
    </x-ui.panel>

    <section class="grid gap-5 xl:grid-cols-2">
        @foreach (($stats['summarySections'] ?? []) as $section)
            <x-ui.panel wire:key="summary-{{ $section['title'] }}" :title="$section['title']" :icon="$section['icon']">
                <div class="space-y-2">
                    @foreach ($section['rows'] as $row)
                        <div wire:key="summary-{{ $section['title'] }}-{{ $loop->index }}" class="grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="font-semibold text-slate-600">{{ $row['label'] }}</div>
                            <div class="font-bold text-slate-800 sm:text-right">{{ $row['display'] }}</div>
                            @if (! empty($row['meta']))
                                <div class="text-xs font-semibold text-slate-400 sm:col-span-2 sm:text-right">{{ $row['meta'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach

        @foreach (($stats['pageStatsSections'] ?? []) as $section)
            <x-ui.panel wire:key="page-stats-{{ $section['title'] }}" :title="$section['title']" :icon="$section['icon']">
                <div class="space-y-2">
                    @foreach ($section['rows'] as $row)
                        <div wire:key="page-stats-{{ $section['title'] }}-{{ $loop->index }}" class="grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="font-semibold text-slate-600">{{ $row['label'] }}</div>
                            <div class="font-bold text-slate-800 sm:text-right">{{ $row['display'] }}</div>
                            @if (! empty($row['meta']))
                                <div class="text-xs font-semibold text-slate-400 sm:col-span-2 sm:text-right">{{ $row['meta'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach

        @foreach (($stats['databaseOptimizationSections'] ?? []) as $section)
            <x-ui.panel wire:key="database-optimization-{{ $section['title'] }}" :title="$section['title']" :icon="$section['icon']">
                <div class="space-y-2">
                    @foreach ($section['rows'] as $row)
                        <div wire:key="database-optimization-{{ $section['title'] }}-{{ $loop->index }}" class="grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="font-semibold text-slate-600">{{ $row['label'] }}</div>
                            <div class="font-bold text-slate-800 sm:text-right">{{ $row['display'] }}</div>
                            @if (! empty($row['meta']))
                                <div class="text-xs font-semibold text-slate-400 sm:col-span-2 sm:text-right">{{ $row['meta'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
        <x-ui.panel title="Проверка важных индексов" icon="fa-solid fa-magnifying-glass-chart">
            <div class="space-y-2">
                @foreach (($stats['databaseExpectedIndexRows'] ?? []) as $index)
                    <div wire:key="expected-index-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-700">{{ $index['label'] }}</div>
                                <div class="mt-1 text-xs font-semibold text-slate-500">{{ $index['table'] }} · {{ $index['columns'] }}</div>
                            </div>
                            <div class="text-xs font-bold {{ $index['present'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $index['status'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>

        <x-ui.panel title="Что нужно добавить" icon="fa-solid fa-triangle-exclamation">
            <div class="space-y-2">
                @forelse (($stats['databaseOptimizationIssueRows'] ?? []) as $issue)
                    <div wire:key="index-issue-{{ $loop->index }}" class="rounded-lg bg-rose-50/70 p-3 text-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-700">{{ $issue['label'] }}</div>
                                <div class="mt-1 text-xs font-semibold text-slate-500">{{ $issue['table'] }} · {{ $issue['columns'] }}</div>
                            </div>
                            <div class="text-xs font-bold text-rose-700">{{ $issue['status'] }}</div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                        Важные индексы на месте.
                    </div>
                @endforelse
            </div>
        </x-ui.panel>
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
        <x-ui.panel title="Индексы разделов" icon="fa-solid fa-database">
            <div class="grid gap-2">
                @foreach (($stats['databaseIndexRows'] ?? []) as $table)
                    <div wire:key="database-index-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="font-semibold text-slate-700">{{ $table['label'] }}</div>
                        <div class="mt-2 grid gap-2 text-xs font-semibold text-slate-500 sm:grid-cols-2">
                            <div>Записей: <span class="font-bold text-slate-800">{{ $table['records_display'] }}</span></div>
                            <div>Индексов: <span class="font-bold text-slate-800">{{ $table['indexes_display'] }}</span></div>
                            <div>Вторичных: <span class="font-bold text-slate-800">{{ $table['secondary_display'] }}</span></div>
                            <div>Уникальных: <span class="font-bold text-slate-800">{{ $table['unique_display'] }}</span></div>
                        </div>
                        <div class="mt-2 text-xs leading-5 text-slate-500">{{ $table['coverage'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>

        <x-ui.panel title="Маршруты системы" icon="fa-solid fa-route">
            <div class="space-y-2">
                @foreach (($stats['routeRows'] ?? []) as $route)
                    <div wire:key="route-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-700">{{ $route['label'] }}</div>
                                <div class="mt-1 text-xs font-semibold text-slate-500">{{ $route['address'] }} · {{ $route['kind'] }} · {{ $route['scope'] }}</div>
                            </div>
                            <div class="font-bold text-slate-800 sm:text-right">{{ $route['generated_display'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
        <x-ui.panel title="Внутренние ссылки" icon="fa-solid fa-link">
            <div class="space-y-2">
                @foreach (($stats['internalLinkRows'] ?? []) as $link)
                    <div wire:key="internal-link-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="font-semibold text-slate-700">{{ $link['label'] }}</div>
                        <div class="mt-1 text-xs font-semibold text-slate-500">{{ $link['place'] }} · {{ $link['route'] }}</div>
                        <div class="mt-2 font-bold text-slate-800">{{ $link['count_display'] }}</div>
                        <div class="text-xs font-semibold text-slate-400">{{ $link['meta'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>

        <x-ui.panel title="Поля со ссылками" icon="fa-solid fa-table-list">
            <div class="space-y-2">
                @foreach (($stats['externalUrlFieldRows'] ?? []) as $field)
                    <div wire:key="external-field-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                        <div class="font-semibold text-slate-700">{{ $field['label'] }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $field['field'] }}</div>
                        <div class="mt-2 grid gap-2 text-xs font-semibold text-slate-500 sm:grid-cols-2">
                            <div>Заполнено: <span class="font-bold text-slate-800">{{ $field['filled_display'] }}</span> · {{ $field['coverage'] }}</div>
                            <div>Уникальных: <span class="font-bold text-slate-800">{{ $field['unique_display'] }}</span></div>
                            <div>Ссылки: <span class="font-bold text-slate-800">{{ $field['absolute_display'] }}</span></div>
                            <div>Пусто: <span class="font-bold text-slate-800">{{ $field['empty_display'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
        @foreach (($stats['qualitySections'] ?? []) as $section)
            <x-ui.panel wire:key="quality-section-{{ $section['title'] }}" :title="$section['title']" :icon="$section['icon']">
                <div class="space-y-2">
                    @foreach ($section['rows'] as $row)
                        <div wire:key="quality-section-{{ $section['title'] }}-{{ $loop->index }}" class="rounded-lg p-3 text-sm {{ $row['row_class'] ?: 'bg-slate-50' }}">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="font-semibold text-slate-700">{{ $row['label'] }}</div>
                                <div class="font-bold {{ $row['value_class'] }}">{{ $row['display'] }}</div>
                            </div>
                            <div class="mt-1 text-xs font-semibold text-slate-500">{{ $row['meta'] }} · {{ $row['severity_label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
    </section>

    <x-ui.panel title="Временные срезы" icon="fa-solid fa-chart-line">
        <div class="grid gap-2 lg:grid-cols-5">
            @foreach (($stats['timeWindowRows'] ?? []) as $row)
                <article wire:key="time-window-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="font-bold text-slate-700">{{ $row['label'] }}</div>
                    <div class="mt-3 grid gap-2 text-xs font-semibold text-slate-500">
                        <div>Сериалы: <span class="font-bold text-slate-800">{{ $row['catalog_titles'] }}</span></div>
                        <div>Серии: <span class="font-bold text-slate-800">{{ $row['episodes'] }}</span></div>
                        <div>Видео: <span class="font-bold text-slate-800">{{ $row['media'] }}</span></div>
                        <div>Сбор: <span class="font-bold text-slate-800">{{ $row['crawled'] }}</span></div>
                        <div>Обновление: <span class="font-bold text-slate-800">{{ $row['imported'] }}</span></div>
                        <div>Ошибки: <span class="font-bold text-rose-700">{{ $row['import_errors'] }}</span></div>
                    </div>
                </article>
            @endforeach
        </div>
    </x-ui.panel>

    <x-ui.panel title="Последние запуски обновления" icon="fa-solid fa-clock-rotate-left">
        @if (! empty($stats['recentImportRuns']))
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach (array_slice($stats['recentImportRuns'], 0, 4) as $run)
                    <article wire:key="run-card-{{ $run['id'] }}" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs font-bold uppercase text-slate-400">{{ $run['id'] }}</div>
                                <div class="mt-1 font-bold text-slate-700">{{ $run['mode'] }}</div>
                                <div class="mt-1 text-xs font-semibold text-slate-500">{{ $run['duration'] }}</div>
                            </div>
                            <span @class([
                                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1',
                                'bg-emerald-50 text-emerald-700 ring-emerald-100' => $run['status_tone'] === 'success',
                                'bg-rose-50 text-rose-700 ring-rose-100' => $run['status_tone'] === 'danger',
                                'bg-amber-50 text-amber-700 ring-amber-100' => $run['status_tone'] === 'warning',
                                'bg-slate-50 text-slate-600 ring-slate-200' => ! in_array($run['status_tone'], ['success', 'danger', 'warning'], true),
                            ])>
                                <i @class([
                                    'fa-solid',
                                    'fa-circle-check' => $run['status_tone'] === 'success',
                                    'fa-circle-exclamation' => $run['status_tone'] === 'danger',
                                    'fa-spinner' => $run['status_tone'] === 'warning',
                                    'fa-clock' => ! in_array($run['status_tone'], ['success', 'danger', 'warning'], true),
                                ]) aria-hidden="true"></i>
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-semibold text-slate-500">
                            <div>
                                <div class="text-slate-400">Страницы</div>
                                <div class="mt-1 text-sm font-bold text-slate-700">{{ $run['pages'] }}</div>
                            </div>
                            <div>
                                <div class="text-slate-400">Видео</div>
                                <div class="mt-1 text-sm font-bold text-slate-700">{{ $run['media'] }}</div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="mt-3 space-y-2">
            @forelse (($stats['recentImportRuns'] ?? []) as $run)
                <article wire:key="run-row-{{ $run['id'] }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="grid gap-3 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,0.8fr)_repeat(4,minmax(0,0.55fr))_minmax(0,1.2fr)_minmax(0,0.9fr)]">
                        <div>
                            <div class="font-semibold text-slate-700">{{ $run['id'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $run['mode'] }}</div>
                        </div>
                        <div>
                            <div class="font-bold {{ $run['status_class'] }}">{{ $run['status'] }}</div>
                            @foreach ($run['options'] as $option)
                                <div class="text-xs font-semibold text-slate-400">{{ $option }}</div>
                            @endforeach
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Циклы</div>
                            <div class="font-bold text-slate-800">{{ $run['cycles'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Поиск</div>
                            <div class="font-bold text-slate-800">{{ $run['discovery'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $run['discovery_meta'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Страницы</div>
                            <div class="font-bold text-slate-800">{{ $run['pages'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $run['pages_meta'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Ошибок</div>
                            <div class="font-bold text-rose-700">{{ $run['failed'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Видео</div>
                            <div class="font-bold text-slate-800">{{ $run['media'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $run['media_meta'] }}</div>
                            <div class="text-xs font-semibold text-slate-400">{{ $run['media_extra'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-400">Обслуживание</div>
                            <div class="mt-1 space-y-1 text-slate-600">
                                @foreach ($run['maintenance'] as $item)
                                    <div>{{ $item }}</div>
                                @endforeach
                            </div>
                        </div>
                        <div class="text-xs font-semibold text-slate-500">
                            <div>Старт: {{ $run['started_at'] }}</div>
                            <div>Финиш: {{ $run['finished_at'] }}</div>
                            <div>Длительность: {{ $run['duration'] }}</div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                    Нет данных.
                </div>
            @endforelse
        </div>
    </x-ui.panel>

    <x-ui.panel title="Справочники и связи" icon="fa-solid fa-diagram-project">
        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @foreach (($stats['taxonomyRows'] ?? []) as $row)
                <article wire:key="taxonomy-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="font-semibold text-slate-700">{{ $row['label'] }}</div>
                    <div class="mt-2 grid gap-2 text-xs font-semibold text-slate-500">
                        <div>Записей: <span class="font-bold text-slate-800">{{ $row['records_display'] }}</span></div>
                        <div>Связей: <span class="font-bold text-slate-800">{{ $row['links_display'] }}</span></div>
                        <div>Сериалов: <span class="font-bold text-slate-800">{{ $row['linked_titles_display'] }}</span></div>
                    </div>
                </article>
            @endforeach
        </div>
    </x-ui.panel>

    <section class="grid gap-5 xl:grid-cols-2">
        @foreach (($stats['groupSections'] ?? []) as $section)
            <x-ui.panel wire:key="group-section-{{ $section['title'] }}" :title="$section['title']" :icon="$section['icon']">
                <div class="space-y-2">
                    @forelse ($section['rows'] as $row)
                        <div wire:key="group-section-{{ $section['title'] }}-{{ $loop->index }}" class="grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="font-semibold text-slate-600">{{ $row['label'] }}</div>
                            <div class="font-bold text-slate-800 sm:text-right">{{ $row['total_display'] }}</div>
                            @if (! empty($row['meta']))
                                <div class="text-xs font-semibold text-slate-400 sm:col-span-2 sm:text-right">{{ $row['meta'] }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                            Нет данных.
                        </div>
                    @endforelse
                </div>
            </x-ui.panel>
        @endforeach
    </section>

    <x-ui.panel title="Разделы базы" icon="fa-solid fa-table">
        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
            @foreach (($stats['databaseTables'] ?? []) as $table)
                <article wire:key="database-table-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="text-xs font-semibold text-slate-400">{{ $table['group'] }}</div>
                    <div class="mt-1 font-semibold text-slate-700">{{ $table['label'] }}</div>
                    <div class="mt-2 text-lg font-black text-slate-800">{{ $table['total_display'] }}</div>
                </article>
            @endforeach
        </div>
    </x-ui.panel>
</div>
