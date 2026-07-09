@extends('layouts.app', ['title' => $seo['title'] ?? 'Сводка каталога', 'seo' => $seo ?? []])

@section('content')
    <div class="space-y-5">
        <section class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm shadow-slate-200/60">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div class="min-w-0">
                    <h1 class="inline-flex items-center gap-2 text-2xl font-black text-slate-700">
                        <i class="fa-solid fa-chart-simple text-emerald-700" aria-hidden="true"></i>
                        <span>Сводка каталога</span>
                    </h1>
                    <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-500">
                        Карточки, сезоны, серии, видео, отзывы, оценки, справочники и обновления в одном месте.
                    </p>
                </div>
                <div class="inline-flex w-fit items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">
                    <i class="fa-solid fa-clock" aria-hidden="true"></i>
                    <span>{{ now()->format('d.m.Y H:i') }}</span>
                </div>
            </div>
        </section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($headlineStats as $stat)
                <x-stat :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" />
            @endforeach
        </div>

        <section class="grid gap-5 xl:grid-cols-2">
            @foreach ($summarySections as $section)
                <x-ui.panel :title="$section['title']" :icon="$section['icon']" :pad="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($section['rows'] as $row)
                                    <tr>
                                        <th scope="row" class="w-3/5 px-4 py-3 text-left font-semibold text-slate-600">{{ $row['label'] }}</th>
                                        <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $row['display'] }}</td>
                                        <td class="px-4 py-3 text-right text-xs font-semibold text-slate-400">{{ $row['meta'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.panel>
            @endforeach
        </section>

        <x-ui.panel title="Справочники и связи" icon="fa-solid fa-diagram-project" :pad="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left">Раздел</th>
                            <th scope="col" class="px-4 py-3 text-right">Записей</th>
                            <th scope="col" class="px-4 py-3 text-right">Связей</th>
                            <th scope="col" class="px-4 py-3 text-right">Карточек</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($taxonomyRows as $row)
                            <tr>
                                <th scope="row" class="px-4 py-3 text-left font-semibold text-slate-700">{{ $row['label'] }}</th>
                                <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $row['records_display'] }}</td>
                                <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $row['links_display'] }}</td>
                                <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $row['linked_titles_display'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.panel>

        <section class="grid gap-5 xl:grid-cols-2">
            @foreach ($groupSections as $section)
                <x-ui.panel :title="$section['title']" :icon="$section['icon']" :pad="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($section['rows'] as $row)
                                    <tr>
                                        <th scope="row" class="px-4 py-3 text-left font-semibold text-slate-600">{{ $row['label'] }}</th>
                                        <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $row['total_display'] }}</td>
                                        <td class="px-4 py-3 text-right text-xs font-semibold text-slate-400">{{ $row['meta'] ?? '' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-4 py-4 text-sm text-slate-500" colspan="3">Нет данных.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.panel>
            @endforeach
        </section>

        <x-ui.panel title="Разделы базы" icon="fa-solid fa-table" :pad="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left">Группа</th>
                            <th scope="col" class="px-4 py-3 text-left">Название</th>
                            <th scope="col" class="px-4 py-3 text-right">Записей</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($databaseTables as $table)
                            <tr>
                                <td class="px-4 py-3 text-slate-500">{{ $table['group'] }}</td>
                                <th scope="row" class="px-4 py-3 text-left font-semibold text-slate-700">{{ $table['label'] }}</th>
                                <td class="px-4 py-3 text-right font-bold text-slate-800">{{ $table['total_display'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.panel>
    </div>
@endsection
