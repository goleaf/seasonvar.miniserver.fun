<div class="space-y-5" data-livewire-catalog-administration-manager>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-screwdriver-wrench text-emerald-700" />
                    <span>Управление каталогом</span>
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Редакционные поля, публикация, связи, сезоны, серии и разрешённые видеоисточники.</p>
            </div>
            <a href="{{ route('admin.imports') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                <x-ui.icon name="fa-solid fa-cloud-arrow-down" />
                <span>Запуски импорта</span>
            </a>
        </div>
    </header>

    @if ($notice)
        <div role="status" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($errors->isNotEmpty())
        <div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
            <div class="flex items-start gap-2">
                <x-ui.icon name="fa-solid fa-circle-exclamation" align="start" />
                <div class="space-y-1">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <x-ui.panel title="Сериалы" subtitle="Поиск по ID, точному внешнему ID, началу slug или названию." icon="fa-solid fa-film" :pad="false">
        <div class="border-b border-slate-200 p-4">
            <label class="block text-sm font-bold text-slate-700" for="catalog-admin-search">Поиск сериала</label>
            <div class="relative mt-2">
                <x-ui.icon name="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-3.5 text-slate-400" />
                <input id="catalog-admin-search" type="search" wire:model.live.debounce.500ms="search" maxlength="80" class="min-h-11 w-full rounded-control border border-slate-300 bg-white py-2 pl-10 pr-3 text-sm text-slate-700 focus:border-emerald-600 focus:outline-none" placeholder="Название, slug, внешний ID или внутренний ID">
            </div>
        </div>

        <div wire:loading.flex wire:target="search,selectTitle" class="min-h-24 items-center justify-center gap-2 p-6 text-sm font-semibold text-slate-500">
            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
            <span>Загружаем каталог…</span>
        </div>

        <div wire:loading.remove wire:target="search,selectTitle">
            @forelse ($titles as $catalogTitle)
                <button type="button" wire:key="admin-title-{{ $catalogTitle->id }}" wire:click="selectTitle({{ $catalogTitle->id }})" @class([
                    'grid w-full gap-2 border-b border-slate-200 px-4 py-3 text-left last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center',
                    'bg-emerald-50' => $selectedTitle?->id === $catalogTitle->id,
                    'bg-white hover:bg-slate-50' => $selectedTitle?->id !== $catalogTitle->id,
                ])>
                    <span class="min-w-0">
                        <span class="block text-sm font-black text-slate-800">{{ $catalogTitle->title }}</span>
                        <span class="mt-1 block text-xs font-semibold text-slate-500">#{{ $catalogTitle->id }} · {{ $catalogTitle->slug }} · внешний ID {{ $catalogTitle->external_id ?: '—' }}</span>
                    </span>
                    <span class="text-xs font-bold text-slate-500">{{ $catalogTitle->seasons_count }} сез. · {{ $catalogTitle->episodes_count }} сер. · {{ $catalogTitle->licensed_media_count }} видео</span>
                </button>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">Сериалы по этому запросу не найдены.</div>
            @endforelse
        </div>
    </x-ui.panel>

    {{ $titles->links() }}

    @if ($selectedTitle)
        <x-ui.panel title="Карточка сериала" subtitle="Редакторские title/description/artwork не перезаписываются пустыми provider-значениями при следующем импорте." icon="fa-solid fa-pen-to-square">
            <form wire:submit="saveTitle" class="space-y-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="text-sm font-bold text-slate-700">Название
                        <input type="text" wire:model.blur="titleForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.title" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">Оригинальное название
                        <input type="text" wire:model.blur="titleForm.original_title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Slug
                        <input type="text" wire:model.blur="titleForm.slug" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.slug" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">Внешний ID источника
                        <input type="text" wire:model.blur="titleForm.external_id" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.external_id" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">Год
                        <input type="number" wire:model.blur="titleForm.year" min="1900" max="{{ $maxCatalogYear }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Постер
                        <input type="url" wire:model.blur="titleForm.poster_url" maxlength="2048" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                </div>

                <label class="block text-sm font-bold text-slate-700">Описание
                    <textarea wire:model.blur="titleForm.description" rows="6" maxlength="20000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2 font-normal leading-6 focus:border-emerald-600 focus:outline-none"></textarea>
                </label>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold text-slate-700">Публикация
                        <select wire:model="titleForm.publication_status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                            @foreach ($publicationStatuses as $status)
                                <option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">Аудитория
                        <select wire:model="titleForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                            @foreach ($audiences as $audience)
                                <option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">Доступно с, UTC
                        <input type="text" wire:model.blur="titleForm.available_from" placeholder="2026-08-01 10:00" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Доступно до, UTC
                        <input type="text" wire:model.blur="titleForm.available_until" placeholder="2026-09-01 10:00" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                    </label>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveTitle" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-floppy-disk" /><span>Сохранить сериал</span>
                    </button>
                    <button type="button" wire:click="archiveTitle" wire:confirm="Скрыть сериал? Сезоны, серии, оценки, список просмотра и история останутся в базе." wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-eye-slash" /><span>Скрыть без удаления</span>
                    </button>
                </div>
            </form>
        </x-ui.panel>

        <x-ui.panel title="Люди и справочники" subtitle="Поиск ограничен двадцатью результатами; Translation используется как существующая модель языка и перевода." icon="fa-solid fa-tags">
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($relationGroups as $type => $group)
                    <section wire:key="admin-relation-group-{{ $type }}" class="rounded-control border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-black text-slate-700">{{ $group['label'] }}</h3>
                            <button type="button" wire:click="newLookup('{{ $type }}')" class="min-h-11 px-2 text-xs font-bold text-emerald-700 hover:text-emerald-600">Создать</button>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($group['selected'] as $relation)
                                <button type="button" wire:key="admin-relation-{{ $type }}-{{ $relation->id }}" wire:click="detachRelation('{{ $type }}', {{ $relation->id }})" wire:confirm="Убрать связь «{{ $relation->name }}»?" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-700">
                                    <span>{{ $relation->name }}</span><x-ui.icon name="fa-solid fa-xmark" />
                                </button>
                            @empty
                                <span class="text-xs font-semibold text-slate-500">Связей пока нет.</span>
                            @endforelse
                        </div>
                        <input type="search" wire:model.live.debounce.350ms="relationSearch.{{ $type }}" maxlength="80" class="mt-3 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Найти для добавления">
                        @if (mb_strlen($relationSearch[$type]) >= 2)
                            <div class="mt-2 grid gap-1">
                                @forelse ($group['options'] as $option)
                                    <button type="button" wire:key="admin-relation-option-{{ $type }}-{{ $option->id }}" wire:click="attachRelation('{{ $type }}', {{ $option->id }})" class="min-h-11 rounded-control bg-white px-3 py-2 text-left text-xs font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">{{ $option->name }}</button>
                                @empty
                                    <span class="px-2 py-2 text-xs text-slate-500">Совпадений нет.</span>
                                @endforelse
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>

            @if ($lookupType)
                <form wire:submit="saveLookup" class="mt-4 grid gap-3 rounded-control border border-emerald-200 bg-emerald-50 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                    <label class="text-sm font-bold text-slate-700">Название
                        <input type="text" wire:model="lookupForm.name" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                    </label>
                    <label class="text-sm font-bold text-slate-700">Slug
                        <input type="text" wire:model="lookupForm.slug" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                    </label>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600"><x-ui.icon name="fa-solid fa-plus" /><span>Создать</span></button>
                </form>
            @endif
        </x-ui.panel>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <x-ui.panel title="Сезоны" subtitle="Обычные сезоны и спецсезоны имеют независимые номера." icon="fa-solid fa-layer-group" :pad="false">
                <div class="border-b border-slate-200 p-3">
                    <button type="button" wire:click="newSeason" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>Новый сезон</span></button>
                </div>
                @forelse ($seasons as $season)
                    <button type="button" wire:key="admin-season-{{ $season->id }}" wire:click="editSeason({{ $season->id }})" @class([
                        'grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0',
                        'bg-emerald-50' => $activeSeason?->id === $season->id,
                        'hover:bg-slate-50' => $activeSeason?->id !== $season->id,
                    ])>
                        <span class="text-sm font-black text-slate-700">{{ $releaseKindLabels[$season->kind->value] }} {{ $season->number }} · {{ $season->title ?: 'Без названия' }}</span>
                        <span class="text-xs font-semibold text-slate-500">{{ $publicationLabels[$season->publication_status->value] }} · серий {{ $season->episodes_count }}</span>
                    </button>
                @empty
                    <div class="p-6 text-sm text-slate-500">Сезонов пока нет.</div>
                @endforelse
            </x-ui.panel>

            @if ($seasonForm)
                <x-ui.panel title="Форма сезона" icon="fa-solid fa-pen">
                    <form wire:submit="saveSeason" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <label class="text-sm font-bold text-slate-700">Номер<input type="number" min="0" wire:model="seasonForm.number" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="seasonForm.number" /></label>
                            <label class="text-sm font-bold text-slate-700">Тип<select wire:model="seasonForm.kind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($releaseKinds as $kind)<option value="{{ $kind->value }}">{{ $releaseKindLabels[$kind->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">Порядок<input type="number" min="0" wire:model="seasonForm.sort_order" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        </div>
                        <label class="block text-sm font-bold text-slate-700">Название<input type="text" wire:model="seasonForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-sm font-bold text-slate-700">Публикация<select wire:model="seasonForm.publication_status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($publicationStatuses as $status)<option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">Аудитория<select wire:model="seasonForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">Доступно с, UTC<input type="text" wire:model="seasonForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            <label class="text-sm font-bold text-slate-700">Доступно до, UTC<input type="text" wire:model="seasonForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">Сохранить сезон</button>
                            @if ($editingSeasonId)<button type="button" wire:click="archiveSeason" wire:confirm="Скрыть сезон без удаления серий?" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">Скрыть сезон</button>@endif
                        </div>
                    </form>
                </x-ui.panel>
            @endif
        </div>

        @if ($activeSeason)
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <x-ui.panel title="Серии выбранного сезона" icon="fa-solid fa-list-ol" :pad="false">
                    <div class="border-b border-slate-200 p-3"><button type="button" wire:click="newEpisode" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>Новая серия</span></button></div>
                    @forelse ($episodes as $episode)
                        <button type="button" wire:key="admin-episode-{{ $episode->id }}" wire:click="editEpisode({{ $episode->id }})" @class(['grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0', 'bg-emerald-50' => $activeEpisode?->id === $episode->id, 'hover:bg-slate-50' => $activeEpisode?->id !== $episode->id])>
                            <span class="text-sm font-black text-slate-700">{{ $releaseKindLabels[$episode->kind->value] }} {{ $episode->number }} · {{ $episode->title ?: 'Без названия' }}</span>
                            <span class="text-xs font-semibold text-slate-500">{{ $publicationLabels[$episode->publication_status->value] }} · видео {{ $episode->licensed_media_count }}</span>
                        </button>
                    @empty
                        <div class="p-6 text-sm text-slate-500">Серий в сезоне пока нет.</div>
                    @endforelse
                </x-ui.panel>

                @if ($episodeForm)
                    <x-ui.panel title="Форма серии" icon="fa-solid fa-pen">
                        <form wire:submit="saveEpisode" class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <label class="text-sm font-bold text-slate-700">Номер<input type="number" min="0" wire:model="episodeForm.number" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="episodeForm.number" /></label>
                                <label class="text-sm font-bold text-slate-700">Тип<select wire:model="episodeForm.kind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($releaseKinds as $kind)<option value="{{ $kind->value }}">{{ $releaseKindLabels[$kind->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Порядок<input type="number" min="0" wire:model="episodeForm.sort_order" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-bold text-slate-700">Название<input type="text" wire:model="episodeForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label><label class="text-sm font-bold text-slate-700">Дата выхода<input type="date" wire:model="episodeForm.released_at" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label></div>
                            <label class="block text-sm font-bold text-slate-700">Краткое описание<textarea rows="4" wire:model="episodeForm.summary" maxlength="20000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2 font-normal leading-6"></textarea></label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm font-bold text-slate-700">Публикация<select wire:model="episodeForm.publication_status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($publicationStatuses as $status)<option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Аудитория<select wire:model="episodeForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Доступно с, UTC<input type="text" wire:model="episodeForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">Доступно до, UTC<input type="text" wire:model="episodeForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            </div>
                            <div class="flex flex-wrap gap-2"><button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">Сохранить серию</button>@if ($editingEpisodeId)<button type="button" wire:click="archiveEpisode" wire:confirm="Скрыть серию без удаления прогресса и истории?" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">Скрыть серию</button>@endif</div>
                        </form>
                    </x-ui.panel>
                @endif
            </div>
        @endif

        @if ($activeEpisode)
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <x-ui.panel title="Видеоисточники серии" subtitle="Сохранённые provider URL не выводятся; для нового источника разрешён только HTTPS host из playback allowlist." icon="fa-solid fa-circle-play" :pad="false">
                    <div class="border-b border-slate-200 p-3"><button type="button" wire:click="newMedia" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>Новый источник</span></button></div>
                    @forelse ($mediaItems as $media)
                        <button type="button" wire:key="admin-media-{{ $media->id }}" wire:click="editMedia({{ $media->id }})" @class(['grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0', 'bg-emerald-50' => $editingMediaId === $media->id, 'hover:bg-slate-50' => $editingMediaId !== $media->id])>
                            <span class="text-sm font-black text-slate-700">{{ $media->title }}</span>
                            <span class="text-xs font-semibold text-slate-500">{{ $mediaStatuses[$media->status] ?? $media->status }} · {{ $media->quality ?: 'без качества' }} · {{ $media->format ?: 'без формата' }} · {{ $media->storage_disk }}</span>
                        </button>
                    @empty
                        <div class="p-6 text-sm text-slate-500">Видеоисточников пока нет.</div>
                    @endforelse
                </x-ui.panel>

                @if ($mediaForm)
                    <x-ui.panel title="Форма видеоисточника" icon="fa-solid fa-pen">
                        <form wire:submit="saveMedia" class="space-y-4">
                            <label class="block text-sm font-bold text-slate-700">Название<input type="text" wire:model="mediaForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            @if (! $editingMediaId)
                                <label class="block text-sm font-bold text-slate-700">HTTPS-ссылка<input type="url" wire:model="mediaForm.playback_url" maxlength="2048" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="mediaForm.playback_url" /></label>
                            @else
                                <p class="rounded-control bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500">Адрес существующего источника защищён от редактирования. Можно изменить только публикацию и безопасные метаданные.</p>
                            @endif
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <label class="text-sm font-bold text-slate-700">Качество<select wire:model="mediaForm.quality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal"><option value="">Не указано</option>@foreach ($supportedQualities as $quality)<option value="{{ $quality }}">{{ $quality }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Формат<select wire:model="mediaForm.format" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal"><option value="">Выберите</option>@foreach ($allowedFormats as $format)<option value="{{ $format }}">{{ $format }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Длительность, сек.<input type="number" min="1" wire:model="mediaForm.duration_seconds" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">Перевод<input type="text" wire:model="mediaForm.translation_name" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">Статус<select wire:model="mediaForm.status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($mediaStatuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">Аудитория<select wire:model="mediaForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                            </div>
                            <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="mediaForm.has_subtitles" class="h-4 w-4 rounded border-slate-300 text-emerald-700"><span>Есть субтитры</span></label>
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-bold text-slate-700">Доступно с, UTC<input type="text" wire:model="mediaForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label><label class="text-sm font-bold text-slate-700">Доступно до, UTC<input type="text" wire:model="mediaForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label></div>
                            <div class="flex flex-wrap gap-2"><button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">Сохранить источник</button>@if ($editingMediaId)<button type="button" wire:click="archiveMedia" wire:confirm="Снять видеоисточник с публикации?" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">Снять с публикации</button>@endif</div>
                        </form>
                    </x-ui.panel>
                @endif
            </div>
        @endif
    @endif
</div>
