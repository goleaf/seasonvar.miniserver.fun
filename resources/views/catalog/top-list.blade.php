@extends('layouts.app', ['title' => $seo['title'] ?? $category->title(), 'seo' => $seo ?? []])

@section('content')
    <div class="space-y-5 sm:space-y-6" data-top-list-page="{{ $category->value }}">
        <header class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
            <div class="grid min-w-0 gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,28rem)] lg:items-end lg:px-8 lg:py-8">
                <div class="min-w-0 max-w-4xl">
                    <p class="inline-flex items-center gap-2 text-sm font-black uppercase tracking-[0.14em] text-emerald-700">
                        <x-ui.icon name="fa-solid fa-trophy" />
                        <span>{{ __('top_lists.eyebrow') }}</span>
                    </p>
                    <h1 class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl lg:text-5xl">
                        {{ $category->title() }}
                    </h1>
                    <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600 sm:text-lg">
                        {{ $category->description() }}
                    </p>
                    <p class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">
                        <x-ui.icon name="fa-solid fa-ranking-star" />
                        <span>{{ trans_choice('top_lists.count', $items->count(), ['count' => $formattedCount]) }}</span>
                    </p>
                </div>

                <aside aria-labelledby="top-list-method" class="rounded-panel border border-slate-200 bg-slate-50 p-4 sm:p-5">
                    <h2 id="top-list-method" class="flex items-center gap-2 text-base font-black text-slate-900">
                        <x-ui.icon name="fa-solid fa-scale-balanced text-emerald-700" />
                        <span>{{ __('top_lists.method_title') }}</span>
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('top_lists.method_description') }}</p>
                    <ul class="mt-3 space-y-2 text-sm font-semibold text-slate-700">
                        <li class="flex gap-2">
                            <x-ui.icon name="fa-solid fa-circle-check shrink-0 text-emerald-600" align="start" />
                            <span>{{ __('top_lists.method_public') }}</span>
                        </li>
                        <li class="flex gap-2">
                            <x-ui.icon name="fa-solid fa-list-ol shrink-0 text-emerald-600" align="start" />
                            <span>{{ __('top_lists.method_limit') }}</span>
                        </li>
                        <li class="flex gap-2">
                            <x-ui.icon name="fa-solid fa-arrows-rotate shrink-0 text-emerald-600" align="start" />
                            <span>{{ __('top_lists.method_updates') }}</span>
                        </li>
                    </ul>
                </aside>
            </div>
        </header>

        <nav aria-label="{{ __('top_lists.all_categories') }}" class="rounded-panel border border-slate-200 bg-white p-2 shadow-sm shadow-slate-200/70">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($categoryLinks as $categoryLink)
                    <a
                        href="{{ $categoryLink['url'] }}"
                        @class([
                            'group flex min-h-11 min-w-0 items-center gap-3 rounded-control px-3 py-3 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                            'bg-emerald-700 text-white' => $categoryLink['active'],
                            'bg-slate-50 text-slate-700 hover:bg-emerald-50 hover:text-emerald-800' => ! $categoryLink['active'],
                        ])
                        @if ($categoryLink['active']) aria-current="page" @endif
                    >
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-control',
                            'bg-white/15 text-white' => $categoryLink['active'],
                            'bg-white text-emerald-700' => ! $categoryLink['active'],
                        ])>
                            <x-ui.icon :name="$categoryLink['icon']" />
                        </span>
                        <span class="min-w-0 break-words">{{ $categoryLink['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        @if ($items->isEmpty())
            <section class="rounded-panel border border-slate-200 bg-white px-5 py-14 text-center shadow-sm shadow-slate-200/70" role="status">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-slate-100 text-2xl text-slate-400">
                    <x-ui.icon name="fa-solid fa-trophy" />
                </span>
                <h2 class="mt-4 text-xl font-black text-slate-900">{{ __('top_lists.empty_title') }}</h2>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ __('top_lists.empty_description') }}</p>
                <a href="{{ route('titles.index') }}" class="mt-5 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                    <x-ui.icon name="fa-solid fa-list-ul" />
                    <span>{{ __('top_lists.empty_action') }}</span>
                </a>
            </section>
        @else
            <section aria-labelledby="top-list-leaders">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-sm font-black uppercase tracking-[0.12em] text-emerald-700">{{ __('top_lists.navigation') }}</p>
                        <h2 id="top-list-leaders" class="mt-1 text-2xl font-black tracking-tight text-slate-950">{{ __('top_lists.leaders') }}</h2>
                    </div>
                </div>

                <ol class="mt-4 grid min-w-0 gap-4 lg:grid-cols-3" aria-label="{{ $category->accessibilityLabel() }}" data-top-list-podium>
                    @foreach ($podiumItems as $item)
                        <li
                            data-top-list-row
                            data-top-list-rank="{{ $item->rank }}"
                            @class([
                                'min-w-0 overflow-hidden rounded-panel border bg-white shadow-sm',
                                'border-amber-300 shadow-amber-100' => $item->rank === 1,
                                'border-slate-300 shadow-slate-200/80' => $item->rank === 2,
                                'border-orange-200 shadow-orange-100' => $item->rank === 3,
                            ])
                        >
                            <x-catalog.title-card
                                :title="$item->title"
                                layout="recommendation"
                                :show-description="false"
                                :rank="$item->rank"
                                :reason-labels="$item->reasonLabels"
                            />
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($rankedItems->isNotEmpty())
                <section aria-labelledby="top-list-remaining" data-top-list-main>
                    <h2 id="top-list-remaining" class="text-xl font-black tracking-tight text-slate-950">{{ __('top_lists.remaining') }}</h2>
                    <ol start="4" class="mt-4 grid min-w-0 gap-4 xl:grid-cols-2" aria-label="{{ $category->accessibilityLabel() }}">
                        @foreach ($rankedItems as $item)
                            <li data-top-list-row data-top-list-rank="{{ $item->rank }}" class="min-w-0 overflow-hidden rounded-panel border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                                <x-catalog.title-card
                                    :title="$item->title"
                                    layout="recommendation"
                                    :show-description="false"
                                    :rank="$item->rank"
                                    :reason-labels="$item->reasonLabels"
                                />
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        @endif
    </div>
@endsection
