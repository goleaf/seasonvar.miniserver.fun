<div
    id="player"
    class="scroll-mt-40 space-y-5 sm:scroll-mt-44 lg:scroll-mt-48"
    data-active-player-session="{{ $playerSessionKey }}"
    x-on:catalog-progress="
        if ($event.detail.sessionKey === $el.dataset.activePlayerSession) {
            $wire.recordProgress(
                $event.detail.episodeId,
                $event.detail.playbackSessionToken,
                $event.detail.eventSequence,
                $event.detail.positionSeconds,
                $event.detail.durationSeconds,
                $event.detail.completed
            );
        }
    "
    x-on:click.capture="if ($event.target.closest('[data-catalog-history]')) window.history.pushState({}, '', window.location.href)"
    x-on:popstate.window="
        const targetUrl = window.location.href;
        const query = new URLSearchParams(window.location.search);
        $wire.$set('season', query.get('season') ?? '', false);
        $wire.$set('episode', query.get('episode') ?? '', false);
        $wire.$set('media', query.get('media') ?? '', false);
        $wire.$set('variant', query.get('variant') ?? '', false);
        $wire.$set('quality', query.get('quality') ?? '', false);
        $wire.$set('format', query.get('format') ?? '', false);
        await $wire.$refresh();
        window.history.replaceState({}, '', targetUrl);
    "
>
    <x-ui.panel title="Просмотр" icon="fa-solid fa-circle-play">
        <div class="flex flex-col gap-3 rounded-lg bg-emerald-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="text-xs font-bold uppercase tracking-wide text-emerald-700">Продолжить просмотр</div>
                <div class="mt-1 text-lg font-black text-slate-800">{{ $primaryAction->label }}</div>
            </div>
            <button
                type="button"
                wire:click="playPrimary"
                data-catalog-history
                @disabled(! $primaryAction->isPlayable())
                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
                <i class="fa-solid fa-play" aria-hidden="true"></i>
                <span>{{ $primaryAction->label }}</span>
            </button>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(240px,0.45fr)]">
            <div class="min-w-0">
                <h3 class="mb-2 text-sm font-bold text-slate-700">Сейчас открыто</h3>
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                    @if ($selectedEpisode)
                        <x-ui.status-pill icon="fa-solid fa-circle-play" variant="success">
                            {{ $this->selectedEpisodeLabel($selectedEpisode) }}
                        </x-ui.status-pill>
                        @if ($selectedEpisode->title)
                            <x-ui.status-pill icon="fa-solid fa-file-lines">{{ $selectedEpisode->title }}</x-ui.status-pill>
                        @endif
                    @else
                        <x-ui.status-pill icon="fa-solid fa-circle-info">Серия не выбрана</x-ui.status-pill>
                    @endif
                </div>

                @if ($selectedMedia && $playbackSource->isPlayable() && $showView->selectedMediaUrl)
                    <div
                        wire:key="catalog-player-media-shell-{{ $selectedMedia->id }}"
                        wire:ignore
                        data-player-shell
                        data-player-state="loading"
                        class="mt-3 overflow-hidden rounded-lg border border-emerald-200 bg-emerald-50"
                    >
                        <div
                            id="catalog-player-status-{{ $selectedMedia->id }}"
                            data-player-status
                            hidden
                            aria-live="polite"
                            class="flex min-h-11 flex-wrap items-center justify-between gap-2 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800"
                        >
                            <span class="inline-flex items-center gap-2">
                                <i data-player-status-icon class="fa-solid fa-circle-notch fa-spin text-emerald-700" aria-hidden="true"></i>
                                <span data-player-status-text>Подготавливаем видео…</span>
                            </span>
                            <button
                                type="button"
                                data-player-retry
                                hidden
                                class="min-h-11 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"
                            >
                                Повторить
                            </button>
                        </div>
                        <video
                            controls
                            playsinline
                            preload="metadata"
                            poster="{{ $title->poster_url }}"
                            aria-describedby="catalog-player-status-{{ $selectedMedia->id }}"
                            class="js-catalog-player aspect-video w-full bg-slate-100"
                            data-player-session="{{ $playerSessionKey }}"
                            data-progress-episode="{{ $selectedEpisode?->id }}"
                            data-progress-session="{{ $progressSessionToken }}"
                            data-progress-position="{{ $primaryAction->episodeId === $selectedEpisode?->id ? $primaryAction->positionSeconds : 0 }}"
                            data-progress-enabled="{{ $isAuthenticated ? '1' : '0' }}"
                            @if ($showView->selectedMediaFormat === 'm3u8') data-hls-src="{{ $showView->selectedMediaUrl }}" @endif
                        >
                            <source src="{{ $showView->selectedMediaUrl }}" @if ($showView->selectedMediaType) type="{{ $showView->selectedMediaType }}" @endif>
                            Ваш браузер не поддерживает воспроизведение видео.
                        </video>
                    </div>
                @else
                    <div wire:key="catalog-player-empty" class="mt-3 overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                        <div class="grid aspect-video place-items-center p-6 text-center text-amber-700">
                            <div>
                                <i class="fa-solid fa-circle-play text-3xl text-amber-600" aria-hidden="true"></i>
                                <div class="mt-3 text-lg font-bold text-amber-800">{{ $playbackSource->message }}</div>
                                <p class="mt-1 text-sm">Выберите другой доступный сезон или серию.</p>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($selectedMedia)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.status-pill icon="fa-solid fa-file-video" variant="success" size="md">
                            {{ $showView->mediaDetailsLabel($selectedMedia) }}
                        </x-ui.status-pill>
                    </div>
                @endif

                @if ($selectedEpisode && ($episodeNavigation->previous || $episodeNavigation->next))
                    <nav class="mt-3 grid gap-2 sm:grid-cols-2" aria-label="Навигация по доступным сериям">
                        @if ($episodeNavigation->previous)
                            <a
                                href="{{ route('titles.show', $showView->episodeQuery($episodeNavigation->previous)).'#player' }}"
                            wire:key="episode-navigation-previous-{{ $episodeNavigation->previous->id }}"
                            wire:click.prevent="selectEpisode({{ $episodeNavigation->previous->id }})"
                            data-catalog-history
                                class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700"
                            >
                                <i class="fa-solid fa-arrow-left shrink-0" aria-hidden="true"></i>
                                <span class="min-w-0">
                                    <span class="block text-xs uppercase tracking-wide text-slate-400">Предыдущая</span>
                                    <span class="block break-words">{{ $this->episodeDisplayLabel($episodeNavigation->previous) }}</span>
                                </span>
                            </a>
                        @endif

                        @if ($episodeNavigation->next)
                            <a
                                href="{{ route('titles.show', $showView->episodeQuery($episodeNavigation->next)).'#player' }}"
                            wire:key="episode-navigation-next-{{ $episodeNavigation->next->id }}"
                            wire:click.prevent="selectEpisode({{ $episodeNavigation->next->id }})"
                            data-catalog-history
                                class="flex min-h-11 items-center justify-end gap-3 rounded-control bg-slate-50 px-3 py-2 text-right text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 sm:col-start-2"
                            >
                                <span class="min-w-0">
                                    <span class="block text-xs uppercase tracking-wide text-slate-400">Следующая</span>
                                    <span class="block break-words">{{ $this->episodeDisplayLabel($episodeNavigation->next) }}</span>
                                </span>
                                <i class="fa-solid fa-arrow-right shrink-0" aria-hidden="true"></i>
                            </a>
                        @endif
                    </nav>
                @endif
            </div>

            <section class="rounded-lg bg-slate-50 p-4" aria-label="Личное состояние просмотра">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <i class="fa-solid fa-user-check text-emerald-700" aria-hidden="true"></i>
                    <span>Ваш сериал</span>
                </div>

                @if ($isAuthenticated)
                    <div class="mt-3 grid gap-3">
                        <button
                            type="button"
                            wire:click="toggleWatchlist"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700"
                        >
                            <i class="{{ $inWatchlist ? 'fa-solid' : 'fa-regular' }} fa-bookmark" aria-hidden="true"></i>
                            <span>{{ $inWatchlist ? 'В списке просмотра' : 'Добавить в список' }}</span>
                        </button>

                        <label class="grid gap-1 text-sm font-semibold text-slate-600">
                            <span>{{ $userRating ? 'Ваша оценка: '.$userRating.' из 10' : 'Ваша оценка' }}</span>
                            <select wire:change="setRating($event.target.value)" class="min-h-11 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700">
                                <option value="">Не выбрана</option>
                                @foreach (range(1, 10) as $rating)
                                    <option value="{{ $rating }}" @selected($userRating === $rating)>{{ $rating }} из 10</option>
                                @endforeach
                            </select>
                        </label>
                        @error('rating')
                            <p class="text-sm font-semibold text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <p class="mt-3 text-sm leading-6 text-slate-500">После входа здесь появятся список просмотра, личная оценка и сохранение позиции.</p>
                @endif
            </section>
        </div>

        @if ($selectedEpisode && $selectedEpisode->licensedMedia->count() > 1)
            <div class="mt-4">
                <div class="text-sm font-bold text-slate-700">Настройки просмотра</div>
                <div class="mt-2 text-xs font-bold uppercase tracking-wide text-slate-500">Вариант</div>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($selectedEpisode->licensedMedia as $episodeMedia)
                        <a
                            href="{{ route('titles.show', $showView->variantQuery($episodeMedia)).'#player' }}"
                            wire:key="episode-media-{{ $episodeMedia->id }}"
                            wire:click.prevent="selectMedia({{ $episodeMedia->id }})"
                            data-catalog-history
                            @if ($selectedMedia?->id === $episodeMedia->id) aria-current="true" @endif
                            @class([
                                'min-h-11 rounded-control px-3 py-2 text-left text-sm font-bold',
                                'bg-emerald-50 text-emerald-700' => $selectedMedia?->id === $episodeMedia->id,
                                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $selectedMedia?->id !== $episodeMedia->id,
                            ])
                        >
                            {{ $showView->variantLabel($episodeMedia) }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </x-ui.panel>

    <x-ui.panel id="seasons" title="Сезоны и серии" icon="fa-solid fa-layer-group" :pad="false" class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
        @if ($seasons->isNotEmpty())
            <div class="border-b border-slate-200 p-3">
                <div class="flex gap-2 overflow-x-auto pb-1" aria-label="Доступные сезоны">
                    @foreach ($seasons as $seasonOption)
                        <a
                            href="{{ route('titles.show', ['catalogTitle' => $title, 'season' => $seasonOption->id]).'#seasons' }}"
                            wire:key="season-option-{{ $seasonOption->id }}"
                            wire:click.prevent="selectSeason({{ $seasonOption->id }})"
                            data-catalog-history
                            @if ($activeSeason?->id === $seasonOption->id) aria-current="true" @endif
                            @class([
                                'min-h-11 shrink-0 rounded-control px-3 py-2 text-sm font-bold',
                                'bg-emerald-700 text-white' => $activeSeason?->id === $seasonOption->id,
                                'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $activeSeason?->id !== $seasonOption->id,
                            ])
                        >
                            {{ $this->seasonDisplayLabel($seasonOption) }} · {{ $this->episodeCountLabel((int) $seasonOption->available_episodes_count) }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div
                @if ($activeSeason) id="season-{{ $activeSeason->id }}" @endif
                class="scroll-mt-40 p-4 sm:scroll-mt-44 lg:scroll-mt-48"
            >
                @if ($activeSeason)
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-bold text-slate-700">{{ $this->seasonDisplayLabel($activeSeason) }}</h3>
                        <x-ui.status-pill variant="muted">{{ $this->episodeCountLabel($episodes->count()) }}</x-ui.status-pill>
                    </div>
                @endif

                @forelse ($episodes as $episodeOption)
                    @if ($loop->first)
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @endif
                    <a
                        href="{{ route('titles.show', $showView->episodeQuery($episodeOption)).'#player' }}"
                        wire:key="season-episode-{{ $episodeOption->id }}"
                        wire:click.prevent="selectEpisode({{ $episodeOption->id }})"
                        data-catalog-history
                        @if ($selectedEpisode?->id === $episodeOption->id) aria-current="true" @endif
                        @class([
                            'min-h-16 rounded-lg px-3 py-3 text-left text-sm transition',
                            'bg-emerald-50 text-emerald-800' => $selectedEpisode?->id === $episodeOption->id,
                            'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => $selectedEpisode?->id !== $episodeOption->id,
                        ])
                    >
                        <span class="flex items-start gap-2 font-bold">
                            <i class="fa-solid fa-circle-play mt-0.5 text-emerald-700" aria-hidden="true"></i>
                            <span>{{ $this->episodeDisplayLabel($episodeOption) }}</span>
                        </span>
                        @if ($episodeOption->title)
                            <span class="mt-1 block text-xs font-semibold text-slate-500">{{ $episodeOption->title }}</span>
                        @endif
                        <span class="mt-1 block text-xs font-semibold text-slate-400">
                            {{ $showView->episodeMediaItems($episodeOption)->count() }} видео
                        </span>
                    </a>
                    @if ($loop->last)
                        </div>
                    @endif
                @empty
                    <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">В этом сезоне пока нет доступных серий с рабочим источником.</div>
                @endforelse
            </div>
        @else
            <p class="p-4 text-sm text-slate-500">Доступные сезоны пока не опубликованы.</p>
        @endif
    </x-ui.panel>

    <div wire:loading.delay class="rounded-lg bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700" role="status">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
        <span>Обновляем доступные серии…</span>
    </div>
</div>
