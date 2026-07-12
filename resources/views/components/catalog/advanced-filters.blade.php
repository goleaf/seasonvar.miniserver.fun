@props([
    'filterView',
    'titleContext',
    'search',
    'sort',
    'view',
    'perPage',
])

<details {{ $attributes->merge(['class' => 'group rounded-control border border-slate-200 bg-slate-50 p-3']) }}>
    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-1 text-sm font-bold text-slate-700">
        <span class="inline-flex min-w-0 items-center gap-2">
            <i class="fa-solid fa-sliders shrink-0 text-slate-400" aria-hidden="true"></i>
            <span class="min-w-0 break-words">Расширенные фильтры</span>
        </span>
        <i class="fa-solid fa-chevron-down shrink-0 text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
    </summary>

    <form method="GET" action="{{ route('titles.index') }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
            <input type="number" name="year_from" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->catalogQueryState['year_from'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Год до</span>
            <input type="number" name="year_to" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->catalogQueryState['year_to'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Сезонов от</span>
            <input type="number" name="seasons_min" min="0" value="{{ $filterView->catalogQueryState['seasons_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Сезонов до</span>
            <input type="number" name="seasons_max" min="0" value="{{ $filterView->catalogQueryState['seasons_max'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Серий от</span>
            <input type="number" name="episodes_min" min="0" value="{{ $filterView->catalogQueryState['episodes_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Серий до</span>
            <input type="number" name="episodes_max" min="0" value="{{ $filterView->catalogQueryState['episodes_max'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Видео</span>
            <select name="video" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                <option value="">Любое</option>
                <option value="available" @selected(($filterView->catalogQueryState['video'] ?? '') === 'available')>Есть видео</option>
                <option value="missing" @selected(($filterView->catalogQueryState['video'] ?? '') === 'missing')>Нет видео</option>
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Субтитры</span>
            <select name="subtitles" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                <option value="">Любые</option>
                <option value="available" @selected(($filterView->catalogQueryState['subtitles'] ?? '') === 'available')>Есть субтитры</option>
                <option value="missing" @selected(($filterView->catalogQueryState['subtitles'] ?? '') === 'missing')>Нет субтитров</option>
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Источник рейтинга</span>
            <select name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                <option value="">Любой</option>
                <option value="kinopoisk" @selected(($filterView->catalogQueryState['rating_source'] ?? '') === 'kinopoisk')>КиноПоиск</option>
                <option value="imdb" @selected(($filterView->catalogQueryState['rating_source'] ?? '') === 'imdb')>IMDb</option>
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Рейтинг от</span>
            <input type="number" name="rating_min" min="0" max="10" step="0.1" value="{{ $filterView->catalogQueryState['rating_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Голосов от</span>
            <input type="number" name="votes_min" min="0" value="{{ $filterView->catalogQueryState['votes_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
        </label>
        <label class="text-sm font-semibold text-slate-600">
            <span class="mb-1 block">Обновлено</span>
            <select name="updated" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                <option value="">За всё время</option>
                <option value="day" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'day')>За день</option>
                <option value="week" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'week')>За неделю</option>
                <option value="month" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'month')>За месяц</option>
                <option value="year" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'year')>За год</option>
            </select>
        </label>

        <fieldset class="sm:col-span-2 xl:col-span-4">
            <legend class="mb-2 text-sm font-semibold text-slate-600">Качество видео</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'] as $quality)
                    <label class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">
                        <input type="checkbox" name="quality[]" value="{{ $quality }}" @checked(in_array($quality, $filterView->listState('quality'), true))>
                        <span>{{ $quality }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex items-end sm:col-span-2 xl:col-span-4">
            <button type="submit" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                <i class="fa-solid fa-filter" aria-hidden="true"></i>
                <span>Применить фильтры</span>
            </button>
        </div>
    </form>
</details>
