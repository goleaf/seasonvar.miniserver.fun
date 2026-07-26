<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex min-w-0 flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">{{ __('calendar.eyebrow') }}</p>
                <h1 class="mt-2 break-words text-2xl font-black text-slate-900 sm:text-3xl">{{ __('calendar.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('calendar.description') }}</p>
                <p class="mt-2 text-xs font-semibold text-slate-500">{{ __('calendar.timezone', ['timezone' => $timezone]) }}</p>
            </div>
            @if ($settingsUrl)
                <a href="{{ $settingsUrl }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-bell" />{{ __('calendar.notification_settings') }}</a>
            @endif
        </div>

        <nav class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('calendar.navigation') }}">
            @foreach ($viewUrls as $viewCode => $viewUrl)
                <a href="{{ $viewUrl }}" wire:navigate @class(['inline-flex min-h-11 min-w-0 items-center rounded-control px-4 py-2 text-sm font-bold', 'bg-emerald-700 text-white' => $calendarView->value === $viewCode, 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $calendarView->value !== $viewCode]) @if ($calendarView->value === $viewCode) aria-current="page" @endif>{{ __('calendar.views.'.$viewCode) }}</a>
            @endforeach
        </nav>
    </header>

    @if ($notice !== '')
        <div class="rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800" role="status" aria-live="polite">{{ $notice }}</div>
    @endif

    @if ($calendarView->value === 'personal')
        <livewire:release-calendar.release-calendar-feed-manager :locale="$interfaceLocale" />
    @endif

    <section aria-labelledby="calendar-period-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                @if ($previousUrl)<a href="{{ $previousUrl }}" wire:navigate class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('calendar.previous_period') }}"><x-ui.icon name="fa-solid fa-chevron-left" /></a>@endif
                <h2 id="calendar-period-title" class="text-lg font-black text-slate-900">{{ $periodLabel }}</h2>
                @if ($nextUrl)<a href="{{ $nextUrl }}" wire:navigate class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('calendar.next_period') }}"><x-ui.icon name="fa-solid fa-chevron-right" /></a>@endif
            </div>
            <a href="{{ $todayUrl }}" wire:navigate class="inline-flex min-h-11 items-center rounded-control bg-emerald-50 px-4 text-sm font-bold text-emerald-800 hover:bg-emerald-100">{{ __('calendar.today') }}</a>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <label class="text-sm font-bold text-slate-700"><span>{{ __('calendar.filters.type') }}</span><select wire:model.live="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">{{ __('calendar.filters.all_types') }}</option>@foreach ($typeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700"><span>{{ __('calendar.filters.status') }}</span><select wire:model.live="status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">{{ __('calendar.filters.all_statuses') }}</option>@foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700"><span>{{ __('calendar.filters.sort') }}</span><select wire:change="changeSort($event.target.value)" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($sortOptions as $option)<option value="{{ $option['value'] }}" @selected($effectiveSort === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
        </div>
        @if ($type !== '' || $status !== '' || $sort !== '' || $catalogTitle !== '')
            <button type="button" wire:click="clearFilters" wire:loading.attr="disabled" class="mt-3 min-h-11 rounded-control bg-slate-100 px-4 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('calendar.filters.clear') }}</button>
        @endif
        <div wire:loading.flex wire:target="type,status,changeSort,clearFilters" class="mt-3 items-center gap-2 text-sm font-bold text-emerald-700" role="status"><x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('calendar.loading') }}</div>
    </section>

    @if ($calendarView->value === 'month' && $monthGrid !== null)
        <section class="hidden overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel md:block" aria-labelledby="calendar-month-grid-title">
            <h2 id="calendar-month-grid-title" class="sr-only">{{ __('calendar.month_grid') }}</h2>
            <table class="w-full table-fixed border-collapse">
                <thead><tr>@foreach ($monthGrid['weekdays'] as $weekday)<th scope="col" class="border-b border-slate-200 bg-slate-50 p-3 text-center text-xs font-black uppercase text-slate-500">{{ $weekday }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($monthGrid['weeks'] as $week)<tr>@foreach ($week as $day)<td @class(['h-24 border border-slate-100 p-2 align-top', 'bg-slate-50 text-slate-600' => ! $day['current'], 'bg-emerald-50' => $day['today']])><a href="{{ $day['url'] }}" wire:navigate class="flex h-full min-h-20 flex-col rounded-control p-2 hover:bg-emerald-100" @if ($day['today']) aria-current="date" @endif aria-label="{{ __('calendar.month_day_label', ['date' => $day['label'], 'count' => $day['count']]) }}"><span class="text-sm font-black">{{ $day['number'] }}</span>@if ($day['count'] > 0)<span class="mt-auto text-xs font-bold text-emerald-700">{{ trans_choice('calendar.release_count', $day['count'], ['count' => $day['count']]) }}</span>@endif</a></td>@endforeach</tr>@endforeach</tbody>
            </table>
        </section>
    @endif

    @island(name: 'release-calendar-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="release-calendar-results">
    @if (! $schemaReady)
        <section class="rounded-panel border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900" role="status">{{ __('calendar.unavailable') }}</section>
    @elseif ($queryFailed)
        <section class="rounded-panel border border-rose-200 bg-rose-50 p-5 text-sm leading-6 text-rose-800" role="alert">{{ __('calendar.errors.query_failed') }}</section>
    @elseif ($entries->isEmpty())
        <section class="rounded-panel border border-slate-200 bg-white p-8 text-center shadow-panel" role="status">
            <x-ui.icon name="fa-regular fa-calendar-xmark text-3xl text-slate-300" />
            <h2 class="mt-3 text-lg font-black text-slate-800">{{ __('calendar.empty.title') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('calendar.empty.description') }}</p>
            <a href="{{ route('titles.index') }}" wire:navigate class="mt-4 inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-4 text-sm font-bold text-white">{{ __('calendar.empty.catalog') }}</a>
        </section>
    @else
        <section aria-label="{{ __('calendar.schedule') }}">
            <div class="space-y-6">
            @foreach ($entryGroups as $groupLabel => $groupEntries)
                <section aria-labelledby="release-group-{{ $loop->index }}">
                    <h2 id="release-group-{{ $loop->index }}" class="mb-3 text-lg font-black text-slate-900">{{ $groupLabel }}</h2>
                    <ul class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($groupEntries as $releaseGroup)
                    <li wire:key="release-group-{{ $releaseGroup->key }}" data-release-card @if ($releaseGroup->isBatch) data-release-batch-card @endif @class(['overflow-hidden rounded-panel border bg-white shadow-panel', 'border-rose-200' => $releaseGroup->primary->isCancelled, 'border-amber-200' => $releaseGroup->primary->isDelayed, 'border-slate-200' => ! $releaseGroup->primary->isCancelled && ! $releaseGroup->primary->isDelayed])>
                        <article class="flex h-full min-w-0 gap-4 p-4">
                            @if ($releaseGroup->primary->posterUrl)<img src="{{ $releaseGroup->primary->posterUrl }}" alt="{{ __('calendar.poster_alt', ['title' => $releaseGroup->primary->title]) }}" loading="lazy" class="h-32 w-24 shrink-0 rounded-control object-cover" />@endif
                            <div class="flex min-w-0 flex-1 flex-col">
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.status-pill>{{ $releaseGroup->primary->typeLabel }}</x-ui.status-pill>
                                    @if ($releaseGroup->primary->isCancelled)
                                        <x-ui.status-pill variant="danger">{{ $releaseGroup->primary->statusLabel }}</x-ui.status-pill>
                                    @elseif ($releaseGroup->primary->isDelayed)
                                        <x-ui.status-pill variant="warning">{{ $releaseGroup->primary->statusLabel }}</x-ui.status-pill>
                                    @else
                                        <x-ui.status-pill>{{ $releaseGroup->primary->statusLabel }}</x-ui.status-pill>
                                    @endif
                                </div>
                                <h3 class="mt-3 break-words text-base font-black text-slate-900">
                                    <a href="{{ $releaseGroup->primary->url }}" wire:navigate class="hover:text-emerald-700">{{ $releaseGroup->primary->title }}</a>
                                    @if ($releaseGroup->batchLabel)<span class="font-bold text-slate-700"> — {{ $releaseGroup->batchLabel }}</span>@endif
                                </h3>
                                @if ($releaseGroup->primary->originalTitle)<p class="mt-1 break-words text-xs text-slate-500">{{ $releaseGroup->primary->originalTitle }}</p>@endif
                                <p class="mt-2 text-sm font-bold text-slate-700"><time @if ($releaseGroup->primary->dateTimeIso) datetime="{{ $releaseGroup->primary->dateTimeIso }}" @endif>{{ $releaseGroup->primary->dateLabel }}</time></p>
                                <p class="mt-1 text-xs text-slate-500">{{ $releaseGroup->primary->precisionLabel }}</p>
                                @if ($releaseGroup->isBatch)
                                    @if ($releaseGroup->primary->seasonLabel)<p class="mt-2 text-sm text-slate-600">{{ $releaseGroup->primary->seasonLabel }}</p>@endif
                                    <details class="group mt-3 rounded-control border border-slate-200 bg-slate-50">
                                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-sm font-bold text-slate-700 marker:hidden">
                                            <span>{{ $releaseGroup->detailLabel }}</span>
                                            <x-ui.icon name="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" />
                                        </summary>
                                        <ul class="border-t border-slate-200 px-3 py-2">
                                            @foreach ($releaseGroup->entries as $batchEntry)
                                                <li wire:key="release-batch-entry-{{ $batchEntry->publicId }}" data-release-batch-item class="flex min-w-0 flex-wrap gap-x-2 border-b border-slate-200 py-2 text-sm text-slate-600 last:border-b-0">
                                                    <span class="font-bold text-slate-800">{{ $batchEntry->episodeLabel }}</span>
                                                    @if ($batchEntry->episodeTitle)<span class="min-w-0 break-words">{{ $batchEntry->episodeTitle }}</span>@endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @elseif ($releaseGroup->primary->contextLabel)
                                    <p class="mt-2 text-sm text-slate-600">{{ $releaseGroup->primary->contextLabel }}</p>
                                @endif
                                @if ($releaseGroup->primary->availabilityLabel)<p class="mt-1 break-words text-sm text-slate-600">{{ $releaseGroup->primary->availabilityLabel }}</p>@endif
                                @if ($releaseGroup->primary->countdownIso)
                                    <p class="mt-2 text-sm font-black text-emerald-700" data-release-countdown="{{ $releaseGroup->primary->countdownIso }}" data-release-countdown-fallback="{{ __('calendar.countdown.awaiting') }}" data-release-countdown-days="{{ __('calendar.countdown.days_short') }}" data-release-countdown-hours="{{ __('calendar.countdown.hours_short') }}" data-release-countdown-minutes="{{ __('calendar.countdown.minutes_short') }}" aria-live="off"><span aria-hidden="true">{{ __('calendar.countdown.calculating') }}</span><span class="sr-only">{{ __('calendar.countdown.accessible', ['date' => $releaseGroup->primary->dateLabel]) }}</span></p>
                                @endif
                                <div class="mt-auto flex flex-wrap gap-2 pt-3">
                                    <a href="{{ $releaseGroup->primary->url }}" wire:navigate class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-3 text-sm font-bold text-white">{{ __('calendar.open_title') }}</a>
                                    @if ($releaseGroup->primary->canSubscribe)
                                        <button type="button" wire:click="toggleSubscription({{ $releaseGroup->primary->catalogTitleId }}, {{ $releaseGroup->primary->isSubscribed ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="toggleSubscription({{ $releaseGroup->primary->catalogTitleId }}, {{ $releaseGroup->primary->isSubscribed ? 'false' : 'true' }})" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ $releaseGroup->primary->isSubscribed ? __('calendar.follow.remove') : __('calendar.follow.add') }}</button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
                    </ul>
                </section>
            @endforeach
            </div>
            @if ($entries->hasPages())<nav class="mt-5" aria-label="{{ __('calendar.pagination') }}">{{ $entries->links(data: ['region' => 'release-calendar-results']) }}</nav>@endif
        </section>
    @endif
    </x-ui.pagination-region>
    @endisland
</div>
