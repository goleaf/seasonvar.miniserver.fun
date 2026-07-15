<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-bookmark text-emerald-700" />
                    <span>Моя библиотека</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Сохранённые тайтлы, оценки, продолжение просмотра и личная история.</p>
            </div>

            @if ($summary->lastWatchedAt)
                <p class="text-xs font-semibold text-slate-500">Последний просмотр {{ $summary->lastWatchedAt->format('d.m.Y H:i') }}</p>
            @endif
        </div>

        <div class="mt-5 grid grid-cols-2 gap-2 lg:grid-cols-4">
            @foreach ([
                ['section' => 'watchlist', 'label' => 'В списке', 'count' => $summary->watchlistCount, 'icon' => 'fa-solid fa-bookmark'],
                ['section' => 'ratings', 'label' => 'Оценено', 'count' => $summary->ratingsCount, 'icon' => 'fa-solid fa-star'],
                ['section' => 'continue-watching', 'label' => 'Продолжить', 'count' => $summary->continueWatchingCount, 'icon' => 'fa-solid fa-circle-play'],
                ['section' => 'history', 'label' => 'В истории', 'count' => $summary->historyCount, 'icon' => 'fa-solid fa-clock-rotate-left'],
            ] as $item)
                <a href="{{ route('library.section', $item['section']) }}" @class([
                    'flex min-h-20 items-center gap-3 rounded-control border p-3 transition',
                    'border-emerald-200 bg-emerald-50 text-emerald-800' => $section === $item['section'],
                    'border-slate-200 bg-slate-50 text-slate-700 hover:border-emerald-200 hover:bg-emerald-50' => $section !== $item['section'],
                ]) @if ($section === $item['section']) aria-current="page" @endif>
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-white text-emerald-700">
                        <x-ui.icon :name="$item['icon']" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xl font-black">{{ $item['count'] }}</span>
                        <span class="block text-xs font-bold uppercase tracking-wide">{{ $item['label'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </header>

    @if ($status)
        <x-form.status-message :message="$status" />
    @endif

    @unless ($canInteract)
        <div class="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 shadow-panel">
            <p class="font-bold">Подтвердите электронную почту</p>
            <p class="mt-1">Просматривать личные данные можно сейчас. Для изменения списка, оценок и истории подтвердите адрес.</p>
            <a href="{{ route('verification.notice') }}" class="mt-2 inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 font-bold text-amber-800 hover:bg-amber-100">
                <x-ui.icon name="fa-solid fa-envelope-circle-check" />
                <span>Перейти к подтверждению</span>
            </a>
        </div>
    @endunless

    @if ($errors->has('rating'))
        <div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            {{ $errors->first('rating') }}
        </div>
    @endif

    @if (in_array($section, ['watchlist', 'ratings'], true))
        <form wire:submit="applyFilters" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" novalidate>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(240px,2fr)_minmax(150px,1fr)_minmax(120px,.7fr)_minmax(160px,1fr)_minmax(130px,.8fr)_auto] xl:items-end">
                <label class="block min-w-0">
                    <span class="mb-1.5 block text-sm font-bold text-slate-700">Поиск</span>
                    <input type="search" wire:model="filters.query" placeholder="Название тайтла" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    @error('filters.query') <span class="mt-1 block text-xs font-semibold text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="block min-w-0">
                    <span class="mb-1.5 block text-sm font-bold text-slate-700">Тип</span>
                    <select wire:model="filters.type" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="">Все типы</option>
                        @foreach ($publicationTypes as $publicationType)
                            <option value="{{ $publicationType->value }}">{{ $publicationType->label() }}</option>
                        @endforeach
                    </select>
                    @error('filters.type') <span class="mt-1 block text-xs font-semibold text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="block min-w-0">
                    <span class="mb-1.5 block text-sm font-bold text-slate-700">Год</span>
                    <input type="number" min="1900" max="{{ now()->year + 1 }}" wire:model="filters.year" placeholder="Любой" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    @error('filters.year') <span class="mt-1 block text-xs font-semibold text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="block min-w-0">
                    <span class="mb-1.5 block text-sm font-bold text-slate-700">Сортировка</span>
                    <select wire:model="filters.sort" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="updated">По обновлению</option>
                        @if ($section === 'ratings')
                            <option value="rating">По оценке</option>
                        @endif
                        <option value="title">По названию</option>
                        <option value="year">По году</option>
                    </select>
                </label>

                <label class="block min-w-0">
                    <span class="mb-1.5 block text-sm font-bold text-slate-700">Порядок</span>
                    <select wire:model="filters.direction" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="desc">Сначала новые</option>
                        <option value="asc">Сначала старые</option>
                    </select>
                </label>

                <div class="flex gap-2 sm:col-span-2 xl:col-span-1">
                    <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60 xl:flex-none">
                        <x-ui.icon name="fa-solid fa-filter" />
                        <span>Применить</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-100 px-3 text-slate-600 hover:bg-slate-200" aria-label="Сбросить фильтры">
                        <x-ui.icon name="fa-solid fa-rotate-left" />
                    </button>
                </div>
            </div>
        </form>
    @endif

    <div wire:loading.flex wire:target="applyFilters,resetFilters,setWatchlist,setRating,removeHistoryItem,clearHistory,setPage" class="min-h-28 items-center justify-center gap-2 rounded-panel border border-slate-200 bg-white p-6 text-sm font-semibold text-slate-500 shadow-panel">
        <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
        <span>Обновляем библиотеку…</span>
    </div>

    <div wire:loading.remove wire:target="applyFilters,resetFilters,setWatchlist,setRating,removeHistoryItem,clearHistory,setPage">
        @if ($section === 'watchlist')
            <x-ui.panel title="Список просмотра" :subtitle="'Сохранено: '.$watchlist->total()" icon="fa-solid fa-bookmark" :pad="false">
                @if ($watchlist->isEmpty())
                    <div class="px-4 py-10 text-center">
                        <x-ui.icon name="fa-regular fa-bookmark text-3xl text-slate-400" />
                        <p class="mt-3 text-sm font-bold text-slate-700">В списке ничего не найдено</p>
                        <p class="mt-1 text-sm text-slate-500">Измените фильтры или добавьте тайтл из каталога.</p>
                    </div>
                @else
                    <div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($watchlist as $state)
                            <article wire:key="watchlist-{{ $state->id }}" class="flex min-w-0 flex-col gap-3">
                                <x-catalog.title-card
                                    :title="$state->catalogTitle"
                                    :show-description="false"
                                    :user-in-watchlist="(bool) $state->in_watchlist"
                                    :user-rating="$state->rating"
                                />
                                @if ($canInteract)
                                    <button type="button" wire:click="setWatchlist({{ $state->catalog_title_id }}, false)" wire:confirm="Удалить тайтл из списка?" class="relative z-10 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">
                                        <x-ui.icon name="fa-solid fa-bookmark-slash" />
                                        <span>Удалить из списка</span>
                                    </button>
                                @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($watchlist->hasPages())
                        <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $watchlist->links() }}</div>
                    @endif
                @endif
            </x-ui.panel>
        @elseif ($section === 'ratings')
            <x-ui.panel title="Мои оценки" :subtitle="'Оценено: '.$ratings->total()" icon="fa-solid fa-star" :pad="false">
                @if ($ratings->isEmpty())
                    <div class="px-4 py-10 text-center">
                        <x-ui.icon name="fa-regular fa-star text-3xl text-slate-400" />
                        <p class="mt-3 text-sm font-bold text-slate-700">Оценок не найдено</p>
                        <p class="mt-1 text-sm text-slate-500">Оценить тайтл можно на его странице.</p>
                    </div>
                @else
                    <div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($ratings as $state)
                            <article wire:key="rating-{{ $state->id }}" class="flex min-w-0 flex-col gap-3">
                                <x-catalog.title-card
                                    :title="$state->catalogTitle"
                                    :show-description="false"
                                    :user-in-watchlist="(bool) $state->in_watchlist"
                                    :user-rating="$state->rating"
                                />
                                @if ($canInteract)
                                    <label class="relative z-10 block">
                                        <span class="mb-1.5 block text-sm font-bold text-slate-700">Ваша оценка</span>
                                        <select wire:change="setRating({{ $state->catalog_title_id }}, $event.target.value)" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                                            <option value="">Удалить оценку</option>
                                            @foreach ($ratingOptions as $rating)
                                                <option value="{{ $rating }}" @selected($state->rating === $rating)>{{ $rating }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($ratings->hasPages())
                        <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $ratings->links() }}</div>
                    @endif
                @endif
            </x-ui.panel>
        @elseif ($section === 'continue-watching')
            <x-ui.panel title="Продолжить просмотр" subtitle="Последние доступные эпизоды с сохранённой позицией." icon="fa-solid fa-circle-play" :pad="false">
                @if ($continueWatching->isEmpty())
                    <div class="px-4 py-10 text-center">
                        <x-ui.icon name="fa-regular fa-circle-check text-3xl text-emerald-700" />
                        <p class="mt-3 text-sm font-bold text-slate-700">Нет незавершённых просмотров</p>
                        <p class="mt-1 text-sm text-slate-500">Откройте доступный эпизод, и он появится здесь.</p>
                    </div>
                @else
                    <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($continueWatching as $item)
                            <x-ui.poster-card :src="$item->title->poster_url" alt="Постер {{ $item->title->display_title }}" layout="horizontal" wire:key="continue-watching-{{ $item->title->id }}">
                                <p class="text-xs font-semibold text-slate-500">
                                    {{ $item->episode->season?->number !== null ? 'Сезон '.$item->episode->season->number : 'Специальный сезон' }}
                                    <span aria-hidden="true"> · </span>
                                    {{ $item->episode->number !== null ? 'Серия '.$item->episode->number : 'Серия без номера' }}
                                </p>
                                <h2 class="mt-1 break-words text-base font-black text-slate-800">{{ $item->title->display_title }}</h2>
                                @if ($item->progressPercent !== null)
                                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-label="Просмотрено {{ $item->progressPercent }}%">
                                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $item->progressPercent }}%"></div>
                                    </div>
                                @endif
                                <a href="{{ route('titles.show', ['catalogTitle' => $item->title, 'season' => $item->episode->season_id, 'episode' => $item->episode->id]) }}" class="relative z-10 mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                                    <x-ui.icon name="fa-solid fa-play" />
                                    <span>{{ $item->actionLabel }}</span>
                                </a>
                            </x-ui.poster-card>
                        @endforeach
                    </div>
                @endif
            </x-ui.panel>
        @else
            <x-ui.panel title="История просмотров" :subtitle="'Записей: '.$history->total()" icon="fa-solid fa-clock-rotate-left" :pad="false">
                @if ($history->isEmpty())
                    <div class="px-4 py-10 text-center">
                        <x-ui.icon name="fa-regular fa-clock text-3xl text-slate-400" />
                        <p class="mt-3 text-sm font-bold text-slate-700">История просмотров пока пуста</p>
                        <p class="mt-1 text-sm text-slate-500">Здесь появятся реально начатые эпизоды.</p>
                    </div>
                @else
                    @if ($canInteract)
                        <div class="flex justify-end border-b border-slate-200 p-4">
                            <button type="button" wire:click="clearHistory" wire:confirm.prompt="Очистить всю историю просмотров? Введите ОЧИСТИТЬ для подтверждения.|ОЧИСТИТЬ" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100">
                                <x-ui.icon name="fa-solid fa-trash-can" />
                                <span>Очистить историю</span>
                            </button>
                        </div>
                    @endif
                    <div class="space-y-3 p-4">
                        @foreach ($history as $progress)
                            <x-ui.poster-card :src="$progress->is_accessible && $progress->catalogTitle ? $progress->catalogTitle->poster_url : null" :alt="$progress->is_accessible && $progress->catalogTitle ? 'Постер '.$progress->catalogTitle->display_title : 'Недоступный эпизод'" layout="compact" wire:key="history-{{ $progress->id }}">
                                <div class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                    <div class="min-w-0">
                                        @if ($progress->is_accessible && $progress->catalogTitle && $progress->episode)
                                            <a href="{{ route('titles.show', ['catalogTitle' => $progress->catalogTitle, 'season' => $progress->episode->season_id, 'episode' => $progress->episode->id]) }}" class="relative z-10 break-words text-base font-black text-slate-800 hover:text-emerald-700">{{ $progress->catalogTitle->display_title }}</a>
                                            <p class="mt-1 text-sm font-semibold text-slate-600">
                                                @if ($progress->episode->season?->number !== null) Сезон {{ $progress->episode->season->number }}, @endif
                                                {{ $progress->episode->number !== null ? 'серия '.$progress->episode->number : 'серия без номера' }}
                                            </p>
                                        @else
                                            <p class="text-base font-black text-slate-700">Недоступный эпизод</p>
                                        @endif
                                        <p class="mt-2 text-xs font-semibold text-slate-500">{{ $progress->last_watched_at->format('d.m.Y H:i') }}</p>
                                    </div>
                                    @if ($canInteract)
                                        <button type="button" wire:click="removeHistoryItem({{ $progress->id }})" wire:confirm="Удалить этот просмотр из истории?" class="relative z-10 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-rose-50 hover:text-rose-700">
                                            <x-ui.icon name="fa-solid fa-xmark" />
                                            <span>Удалить</span>
                                        </button>
                                    @endif
                                </div>
                            </x-ui.poster-card>
                        @endforeach
                    </div>
                    @if ($history->hasPages())
                        <div class="border-t border-slate-200 bg-slate-50 p-4">{{ $history->links() }}</div>
                    @endif
                @endif
            </x-ui.panel>
        @endif
    </div>
</div>
