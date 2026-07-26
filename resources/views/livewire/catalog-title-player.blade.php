<div
    id="player"
    class="scroll-mt-40 space-y-5 sm:scroll-mt-44 lg:scroll-mt-48"
    data-active-player-session="{{ $playerSessionKey }}"
    data-player-workspace
    data-player-runtime-state="loading"
>
    <x-ui.panel :title="__('catalog.player.watch')" icon="fa-solid fa-circle-play">
        <div data-player-context-bar class="-mx-4 -mt-4 mb-4 border-b border-slate-200 bg-slate-100 px-4 py-3 sm:-mx-5 sm:-mt-5 sm:px-5">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-700">
                    @if ($activeSeason)
                        <span data-player-context-season>{{ $this->seasonDisplayLabel($activeSeason) }}</span>
                    @endif
                    @if ($activeSeason && $selectedEpisode)
                        <span aria-hidden="true">·</span>
                    @endif
                    @if ($selectedEpisode)
                        <span data-player-context-episode>{{ $this->episodeDisplayLabel($selectedEpisode) }}</span>
                    @endif
                    @if ($selectedMedia)
                        <span aria-hidden="true">·</span>
                        <span data-player-context-translation>{{ $showView->selectedTranslationLabel }}</span>
                        <span aria-hidden="true">·</span>
                        <span data-player-context-quality>{{ $showView->selectedQualityLabel }}</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($showView->playerContextControls as $contextControl)
                        <details
                            wire:key="player-context-{{ $contextControl['key'] }}"
                            data-player-context-control="{{ $contextControl['key'] }}"
                            @if ($contextControl['options'] === []) data-player-context-empty @endif
                            class="group relative"
                        >
                            <summary
                                @if ($contextControl['options'] === []) tabindex="-1" aria-disabled="true" @endif
                                class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-300 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                            >
                                <x-ui.icon name="{{ $contextControl['icon'] }}" />
                                <span class="sr-only">{{ $contextControl['label'] }}:</span>
                                <span @if ($contextControl['key'] === 'subtitles') data-player-context-subtitles @endif>{{ $contextControl['current'] }}</span>
                                @if ($contextControl['options'] !== [])
                                    <span data-player-context-chevron>
                                        <x-ui.icon name="fa-solid fa-chevron-down text-xs" />
                                    </span>
                                @endif
                            </summary>
                            @if ($contextControl['options'] !== [])
                                <div data-player-context-options class="absolute right-0 z-30 mt-2 grid min-w-56 max-w-[min(22rem,calc(100vw-2rem))] gap-1 rounded-control border border-slate-200 bg-white p-2 shadow-elevated">
                                    @foreach ($contextControl['options'] as $option)
                                        <a
                                            href="{{ $option['url'] }}"
                                            wire:key="player-context-option-{{ $contextControl['key'] }}-{{ $option['mediaId'] }}"
                                            wire:click.prevent="selectMedia({{ $option['mediaId'] }})"
                                            data-catalog-history
                                            data-player-media-option="{{ $option['mediaId'] }}"
                                            data-player-media-format="{{ $option['format'] }}"
                                            data-player-transition-episode="{{ $selectedEpisode?->id }}"
                                            data-player-transition-media="{{ $option['mediaId'] }}"
                                            data-player-context-option="{{ $contextControl['key'] }}"
                                            @if ($option['active']) aria-current="true" @endif
                                            @class([
                                                'flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700',
                                                'bg-emerald-700 text-white' => $option['active'],
                                                'text-slate-700 hover:bg-emerald-50 hover:text-emerald-800' => ! $option['active'],
                                            ])
                                        >
                                            <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </details>
                    @endforeach

                    <button
                        type="button"
                        data-player-theatre-toggle
                        aria-pressed="false"
                        data-label-expand="{{ __('catalog.player.theatre_expand') }}"
                        data-label-collapse="{{ __('catalog.player.theatre_collapse') }}"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-300 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                    >
                        <x-ui.icon name="fa-solid fa-expand" />
                        <span data-player-theatre-label>{{ __('catalog.player.theatre_expand') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="text-xs font-semibold text-emerald-700">{{ __('catalog.player.continue') }}</div>
                <div class="mt-1 text-lg font-semibold text-slate-900">{{ $primaryAction->label }}</div>
            </div>
            <button
                type="button"
                wire:click="playPrimary"
                data-catalog-history
                @disabled(! $primaryActionIsPlayable)
                class="inline-flex min-h-11 w-full shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-300 sm:w-auto"
            >
                <x-ui.icon name="fa-solid fa-play" />
                <span>{{ $primaryAction->label }}</span>
            </button>
        </div>

        <div class="mt-4 space-y-4">
            <div data-player-primary class="min-w-0">
                <div class="relative">
                    <div
                        wire:loading.delay.flex
                        wire:target="selectMedia"
                        class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/80 p-4 text-center text-sm font-bold text-emerald-700 backdrop-blur-sm"
                        role="status"
                    >
                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-4 py-2">
                            <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                            <span>{{ __('catalog.player.switching_variant') }}</span>
                        </span>
                    </div>

                    @if ($selectedMedia && $playbackSourceIsPlayable && $showView->selectedMediaUrl)
                        <div
                            wire:key="catalog-player-media-shell-{{ $selectedMedia->id }}-{{ $authorizationVersion }}"
                            wire:ignore
                            data-player-shell
                            data-player-state="loading"
                            data-player-copy="{{ \Illuminate\Support\Js::encode($playerCopy) }}"
                            data-player-menu-bootstrap="{{ \Illuminate\Support\Js::encode($playerMenuBootstrap) }}"
                            @if ($episodeNavigation->next) data-player-next-title="{{ $this->episodeDisplayLabel($episodeNavigation->next) }}" @endif
                            class="relative mt-3 overflow-hidden rounded-lg border border-slate-800 bg-black"
                        >
                            <div data-player-loading-skeleton aria-hidden="true" class="absolute inset-0 z-10 grid animate-pulse place-items-center bg-slate-950 text-slate-200">
                                <x-ui.icon name="fa-solid fa-circle-play text-4xl" />
                            </div>
                            <div
                                id="catalog-player-status-{{ $selectedMedia->id }}"
                                data-player-status
                                hidden
                                aria-live="polite"
                                class="flex min-h-11 flex-wrap items-center justify-between gap-2 bg-slate-950 px-3 py-2 text-sm font-semibold text-white"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <x-ui.icon name="fa-solid fa-circle-notch fa-spin text-sky-400" data-player-status-icon />
                                    <span data-player-status-text>{{ __('catalog.player.preparing') }}</span>
                                </span>
                                <button
                                    type="button"
                                    data-player-retry
                                    hidden
                                    class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-100"
                                >
                                    <x-ui.icon name="fa-solid fa-rotate-right" />
                                    <span>{{ __('catalog.player.retry') }}</span>
                                </button>
                            </div>
                            <video
                                controls
                                playsinline
                                preload="metadata"
                                poster="{{ $title->poster_url }}"
                                aria-describedby="catalog-player-status-{{ $selectedMedia->id }}"
                                class="js-catalog-player aspect-video w-full bg-black"
                                data-player-session="{{ $playerSessionKey }}"
                                data-player-media-id="{{ $selectedMedia->id }}"
                                data-player-authorization-version="{{ $authorizationVersion }}"
                                data-progress-episode="{{ $selectedEpisode?->id }}"
                                data-progress-session="{{ $progressSessionToken }}"
                                data-progress-position="{{ $progressResumePosition }}"
                                data-progress-enabled="{{ $canInteract ? '1' : '0' }}"
                                data-account-autoplay="{{ $accountPlaybackPreferences['autoplay'] ? '1' : '0' }}"
                                data-account-remember-volume="{{ $accountPlaybackPreferences['rememberVolume'] ? '1' : '0' }}"
                                data-account-volume="{{ $accountPlaybackPreferences['volume'] }}"
                                data-account-muted="{{ $accountPlaybackPreferences['muted'] ? '1' : '0' }}"
                                data-account-speed="{{ $accountPlaybackPreferences['speed'] }}"
                                data-account-subtitles="{{ $accountPlaybackPreferences['subtitlesEnabled'] ? '1' : '0' }}"
                                data-account-keyboard="{{ $accountPlaybackPreferences['keyboardShortcutsEnabled'] ? '1' : '0' }}"
                                data-account-reduced-motion="{{ $accountPlaybackPreferences['reducedMotion'] ? '1' : '0' }}"
                                data-account-authenticated="{{ $isAuthenticated ? '1' : '0' }}"
                                @if ($playbackQualityContext)
                                    data-playback-quality-context="{{ $playbackQualityContext }}"
                                    data-playback-quality-url="{{ $playbackQualityUrl }}"
                                    data-playback-network-test-url="{{ $playbackNetworkTestUrl }}"
                                @endif
                                data-media-title="{{ $mediaSession['title'] }}"
                                data-media-artist="{{ $mediaSession['artist'] }}"
                                data-media-album="{{ $mediaSession['album'] }}"
                                data-media-artwork="{{ $mediaSession['artwork'] }}"
                                @if ($showView->selectedMediaFormat === 'm3u8') data-hls-src="{{ $showView->selectedMediaUrl }}" @endif
                            >
                                @if ($showView->selectedMediaFormat !== 'm3u8')
                                    <source src="{{ $showView->selectedMediaUrl }}" @if ($showView->selectedMediaType) type="{{ $showView->selectedMediaType }}" @endif>
                                @endif
                                {{ __('catalog.player.unsupported_browser') }}
                            </video>
                            <p
                                data-player-caption-status
                                hidden
                                aria-live="polite"
                                class="bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800"
                            ></p>
                            <p data-player-data-saver hidden class="bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-900">
                                {{ __('mobile.player.data_saver') }}
                            </p>
                            <p
                                data-player-notice
                                hidden
                                aria-live="polite"
                                class="bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-900"
                            ></p>
                            <dialog data-player-shortcuts-dialog class="m-auto w-[min(34rem,calc(100%-2rem))] rounded-panel border-0 bg-white p-0 text-slate-800 shadow-elevated backdrop:bg-slate-950/50">
                                <div class="p-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h4 class="text-lg font-black">{{ __('catalog.player.keyboard_shortcuts') }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('catalog.player.keyboard_shortcuts_hint') }}</p>
                                        </div>
                                        <button type="button" data-player-shortcuts-close class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('catalog.player.close_shortcuts') }}">
                                            <x-ui.icon name="fa-solid fa-xmark" />
                                        </button>
                                    </div>
                                    <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                                        @foreach (['play_pause', 'seek', 'volume', 'mute', 'fullscreen', 'captions', 'pip', 'episodes', 'cancel'] as $shortcut)
                                            <div class="rounded-control bg-slate-50 px-3 py-2">
                                                <dt class="font-black text-slate-800">{{ __('catalog.player.shortcuts.'.$shortcut.'.keys') }}</dt>
                                                <dd class="mt-1 text-slate-600">{{ __('catalog.player.shortcuts.'.$shortcut.'.action') }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            </dialog>
                        </div>
                    @else
                        <div wire:key="catalog-player-empty" class="mt-3 overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                            <div class="grid aspect-video place-items-center p-6 text-center text-amber-700">
                                <div>
                                    <x-ui.icon name="fa-solid fa-circle-play text-3xl text-amber-600" />
                                    <div class="mt-3 text-lg font-bold text-amber-800">{{ $playbackSource->message }}</div>
                                    <p class="mt-1 text-sm">{{ __('catalog.player.choose_another') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div
                    data-player-recovery
                    hidden
                    class="mt-3 flex flex-wrap gap-2 rounded-control border border-red-200 bg-red-50 p-3"
                    role="alert"
                    aria-live="assertive"
                >
                    <button type="button" data-player-retry class="inline-flex min-h-11 items-center gap-2 rounded-control bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800">
                        <x-ui.icon name="fa-solid fa-rotate-right" />
                        <span>{{ __('catalog.player.retry') }}</span>
                    </button>
                    <button type="button" data-player-choose-source class="inline-flex min-h-11 items-center gap-2 rounded-control border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-800 hover:bg-red-100">
                        <x-ui.icon name="fa-solid fa-list" />
                        <span>{{ __('catalog.player.choose_source') }}</span>
                    </button>
                    @if ($technicalIssueUrl)
                        <a
                            href="{{ $technicalIssueUrl }}"
                            data-player-issue-link
                            @if ($playbackQualityContext) data-player-quality-report @endif
                            class="inline-flex min-h-11 items-center gap-2 rounded-control border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-800 hover:bg-red-100"
                        >
                            <x-ui.icon name="fa-solid fa-triangle-exclamation" />
                            <span>{{ __('issues.video_not_working') }}</span>
                        </a>
                    @endif
                </div>

                @error('playback')
                    <p class="mt-3 rounded-control bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</p>
                @enderror

                @if ($selectedMedia && $playbackSourceIsPlayable)
                    <div class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('catalog.player.portal_controls') }}">
                        <button
                            type="button"
                            data-player-restart-episode
                            class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                        >
                            <x-ui.icon name="fa-solid fa-rotate-left" />
                            <span>{{ __('catalog.player.restart_episode') }}</span>
                        </button>
                        @if ($episodeNavigation->next)
                            <button
                                type="button"
                                data-player-autoplay-toggle
                                data-player-autoplay-on="{{ __('catalog.player.autoplay_enabled') }}"
                                data-player-autoplay-off="{{ __('catalog.player.autoplay_disabled') }}"
                                aria-pressed="{{ $accountPlaybackPreferences['autoplay'] ? 'true' : 'false' }}"
                                class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                            >
                                <x-ui.icon name="fa-solid fa-forward-step" />
                                <span data-player-autoplay-label>{{ $accountPlaybackPreferences['autoplay'] ? __('catalog.player.autoplay_enabled') : __('catalog.player.autoplay_disabled') }}</span>
                            </button>
                        @endif
                        <button
                            type="button"
                            data-player-shortcuts-open
                            class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                        >
                            <x-ui.icon name="fa-regular fa-keyboard" />
                            <span>{{ __('catalog.player.hotkeys_compact') }}</span>
                        </button>
                    </div>
                @endif

                <div data-player-theatre-secondary class="mt-3 flex flex-wrap gap-2">
                    @if ($selectedEpisode)
                        <x-content-requests.correction-link
                            :url="$episodeCorrectionUrls[$selectedEpisode->id]"
                            field="episode"
                        />
                        @if ($subtitleCorrectionUrl)
                            <x-content-requests.correction-link
                                :url="$subtitleCorrectionUrl"
                                field="subtitles"
                            />
                        @endif
                    @endif
                    <livewire:help-center.contextual-help-link
                        :feature="$playerHelpFeature"
                        :context="$playerHelpContext"
                        :route-locale="$playerHelpRouteLocale"
                        lazy="on-load"
                    />
                    @if ($technicalIssueUrl)
                        <a
                            href="{{ $technicalIssueUrl }}"
                            data-player-issue-link
                            @if ($playbackQualityContext) data-player-quality-report @endif
                            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 sm:w-auto"
                        >
                            <x-ui.icon name="fa-solid fa-triangle-exclamation" />
                            <span>{{ __('issues.video_not_working') }}</span>
                        </a>
                    @endif
                </div>

                @if ($selectedMedia)
                    <div data-player-theatre-secondary class="mt-3 flex flex-wrap gap-2">
                        <x-ui.status-pill icon="fa-solid fa-file-video" variant="success" size="md">
                            {{ $showView->mediaDetailsLabel($selectedMedia) }}
                        </x-ui.status-pill>
                        @if ($showView->selectedMediaFileSizeLabel)
                            <x-ui.status-pill icon="fa-solid fa-hard-drive" variant="muted" size="md">
                                {{ __('catalog.download.file_size') }}: {{ $showView->selectedMediaFileSizeLabel }}
                            </x-ui.status-pill>
                        @endif
                    </div>

                    @if ($showView->selectedMediaIsDirectFile)
                        <div class="mt-3">
                            @if ($showView->selectedMediaDownloadUrl)
                                <a
                                    href="{{ $showView->selectedMediaDownloadUrl }}"
                                    class="inline-flex min-h-11 w-full items-center justify-center gap-3 rounded-control bg-emerald-700 px-4 py-3 text-left text-sm font-black text-white transition hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 sm:w-auto sm:justify-start"
                                    aria-label="{{ __('catalog.download.download_video') }}"
                                    title="{{ $showView->selectedMediaDownloadFilename }}"
                                >
                                    <x-ui.icon name="fa-solid fa-file-arrow-down text-lg" />
                                    <span>
                                        <span class="block">{{ __('catalog.download.download_video') }}</span>
                                        @if ($showView->selectedMediaDownloadDetail)
                                            <span class="block text-xs font-bold text-emerald-100">{{ $showView->selectedMediaDownloadDetail }}</span>
                                        @endif
                                    </span>
                                </a>
                            @elseif ($showView->selectedMediaLoginUrl)
                                <a
                                    href="{{ $showView->selectedMediaLoginUrl }}"
                                    class="inline-flex min-h-11 w-full items-center justify-center gap-3 rounded-control bg-slate-100 px-4 py-3 text-left text-sm font-black text-slate-700 transition hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 sm:w-auto sm:justify-start"
                                >
                                    <x-ui.icon name="fa-solid fa-lock" />
                                    <span>
                                        <span class="block">{{ __('catalog.download.login_to_download') }}</span>
                                        <span class="block text-xs font-bold text-slate-600">{{ __('catalog.download.registration_required') }}</span>
                                    </span>
                                </a>
                            @elseif ($showView->selectedMediaDownloadUnavailableReason)
                                <div class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-4 py-3 text-sm font-bold text-slate-600">
                                    <x-ui.icon name="fa-solid fa-ban" />
                                    <span>{{ $showView->selectedMediaDownloadUnavailableReason }}</span>
                                </div>
                            @endif
                        </div>
                    @elseif ($showView->selectedMediaDownloadUnavailableReason)
                        <div class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-4 py-3 text-sm font-bold text-slate-600">
                            <x-ui.icon name="fa-solid fa-tower-broadcast" />
                            <span>{{ $showView->selectedMediaDownloadUnavailableReason }}</span>
                        </div>
                    @endif
                @endif

                @island(name: 'catalog-player-navigation', always: true, with: $this->playerNavigationIslandPage)
                @if ($selectedEpisode && ($episodeNavigation->previous || $episodeNavigation->next))
                    <nav class="mt-3 grid items-stretch gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]" aria-label="{{ __('catalog.player.episode_navigation') }}">
                        @if ($episodeNavigation->previous)
                            <a
                                href="{{ $previousUrl }}"
                                wire:key="episode-navigation-previous-{{ $episodeNavigation->previous->id }}"
                                data-catalog-history
                                data-player-previous-episode
                                data-player-transition-episode="{{ $episodeNavigation->previous->id }}"
                                class="flex min-h-11 items-center gap-3 rounded-control bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                            >
                                <span class="min-w-0 break-words">
                                    {{ __(
                                        $episodeNavigation->previous->title
                                            ? 'catalog.player.previous_episode_context_titled'
                                            : 'catalog.player.previous_episode_context',
                                        [
                                            'episode' => $this->episodeDisplayLabel($episodeNavigation->previous),
                                            'title' => $episodeNavigation->previous->title,
                                        ],
                                    ) }}
                                </span>
                            </a>
                        @endif

                        <div class="flex min-h-11 items-center justify-center px-3 py-2 text-center text-sm font-semibold text-slate-600 sm:col-start-2" data-player-current-episode>
                            @if ($selectedEpisode)
                                {{ __('catalog.player.current_episode_context', [
                                    'season' => $activeSeason ? $this->seasonDisplayLabel($activeSeason) : '',
                                    'episode' => $this->episodeDisplayLabel($selectedEpisode),
                                ]) }}
                            @endif
                        </div>

                        @if ($episodeNavigation->next)
                            <a
                                href="{{ $nextUrl }}"
                                wire:key="episode-navigation-next-{{ $episodeNavigation->next->id }}"
                                data-catalog-history
                                data-player-next-episode
                                data-player-transition-episode="{{ $episodeNavigation->next->id }}"
                                class="flex min-h-11 items-center justify-end gap-3 rounded-control bg-emerald-700 px-3 py-2 text-right text-sm font-semibold text-white hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 sm:col-start-3"
                            >
                                <span class="min-w-0 break-words">
                                    {{ __(
                                        $episodeNavigation->next->title
                                            ? 'catalog.player.next_episode_context_titled'
                                            : 'catalog.player.next_episode_context',
                                        [
                                            'episode' => $this->episodeDisplayLabel($episodeNavigation->next),
                                            'title' => $episodeNavigation->next->title,
                                        ],
                                    ) }}
                                </span>
                            </a>
                        @endif
                    </nav>
                @endif
                @endisland
            </div>

            <section data-player-personal data-player-theatre-secondary class="border-t border-slate-200 pt-4" aria-label="{{ __('catalog.player.personal_state') }}">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <x-ui.icon name="fa-solid fa-user-check text-emerald-700" />
                    <span>{{ __('catalog.player.your_series') }}</span>
                </div>

                @if ($canInteract)
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 sm:items-end lg:grid-cols-3">
                        <button
                            type="button"
                            wire:click="setWatchlist({{ $inWatchlist ? 'false' : 'true' }})"
                            wire:loading.attr="disabled"
                            wire:target="setWatchlist"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800"
                        >
                            <x-ui.icon name="{{ $inWatchlist ? 'fa-solid' : 'fa-regular' }} fa-bookmark" wire:loading.remove wire:target="setWatchlist" />
                            <x-ui.icon name="fa-solid fa-spinner fa-spin" wire:loading wire:target="setWatchlist" />
                            <span wire:loading.remove wire:target="setWatchlist">{{ $inWatchlist ? __('catalog.player.in_watchlist') : __('catalog.player.add_watchlist') }}</span>
                            <span wire:loading wire:target="setWatchlist">{{ __('catalog.player.saving') }}</span>
                        </button>

                        <label class="grid gap-1 text-sm font-semibold text-slate-600">
                            <span>{{ $userRating ? __('catalog.player.your_rating_value', ['rating' => $userRating, 'maximum' => $ratingMaximum]) : __('catalog.player.your_rating') }}</span>
                            <select wire:change="setRating($event.target.value)" wire:loading.attr="disabled" wire:target="setRating" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700">
                                <option value="">{{ __('catalog.player.rating_missing') }}</option>
                                @foreach ($ratingOptions as $rating)
                                    <option value="{{ $rating }}" @selected($userRating === $rating)>{{ __('catalog.player.rating_value', ['rating' => $rating, 'maximum' => $ratingMaximum]) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if ($recommendationStateAvailable)
                            <label class="grid gap-1 text-sm font-semibold text-slate-600">
                                <span>{{ __('recommendations.watch_status.label') }}</span>
                                <select wire:change="setWatchStatus($event.target.value)" wire:loading.attr="disabled" wire:target="setWatchStatus" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700">
                                    <option value="">{{ __('recommendations.watch_status.none') }}</option>
                                    @foreach ($watchStatusOptions as $statusOption)
                                        <option value="{{ $statusOption->value }}" @selected($watchStatus === $statusOption)>{{ __('recommendations.watch_status.'.$statusOption->value) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        @error('rating')
                            <p class="text-sm font-semibold text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($selectedEpisode)
                        <div class="mt-3 grid gap-3 border-t border-slate-200 pt-3 sm:grid-cols-2 lg:grid-cols-3">
                            @if ($selectedEpisodeManualWatched || ! $selectedEpisodeCompleted)
                                <button
                                    type="button"
                                    wire:click="setEpisodeWatched({{ $selectedEpisode->id }}, {{ $selectedEpisodeManualWatched ? 'false' : 'true' }})"
                                    wire:loading.attr="disabled"
                                    wire:target="setEpisodeWatched"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 disabled:opacity-60"
                                >
                                    <x-ui.icon name="{{ $selectedEpisodeManualWatched ? 'fa-solid fa-rotate-left' : 'fa-solid fa-circle-check' }}" />
                                    <span>{{ $selectedEpisodeManualWatched ? __('catalog.player.remove_watched') : __('catalog.player.mark_watched') }}</span>
                                </button>
                            @else
                                <div class="flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800" role="status">
                                    <x-ui.icon name="fa-solid fa-circle-check" />
                                    <span>{{ __('catalog.player.watched') }}</span>
                                </div>
                            @endif

                            @if ($playbackSourceIsPlayable)
                                <button
                                    type="button"
                                    data-player-save-marker
                                    wire:loading.attr="disabled"
                                    wire:target="savePlaybackMarker"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800 disabled:opacity-60"
                                >
                                    <x-ui.icon name="fa-solid fa-location-dot" />
                                    <span>{{ __('catalog.player.save_moment') }}</span>
                                </button>
                            @endif

                            @if ($playbackMarker)
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-white px-3 py-2 text-sm text-slate-600 sm:col-span-2 lg:col-span-1">
                                    <span class="font-bold">{{ __('catalog.player.saved_moment', ['position' => $playbackMarkerPositionLabel]) }}</span>
                                    <button type="button" wire:click="deletePlaybackMarker('{{ $playbackMarker->public_id }}')" wire:confirm="{{ __('catalog.player.delete_moment_confirm') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 font-bold text-rose-700 hover:bg-rose-50">
                                        <x-ui.icon name="fa-solid fa-trash-can" />
                                        <span>{{ __('catalog.player.delete_moment') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($personalPlaybackNotice)
                        <p class="mt-3 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800" role="status" aria-live="polite">{{ $personalPlaybackNotice }}</p>
                    @endif
                @elseif ($isAuthenticated)
                    <div class="mt-3 rounded-control border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                        <p class="font-bold">{{ __('catalog.player.verification_title') }}</p>
                        <p class="mt-1">{{ __('catalog.player.verification_description') }}</p>
                        <a href="{{ route('verification.notice') }}" class="mt-2 inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 font-bold text-amber-800 hover:bg-amber-100">
                            <x-ui.icon name="fa-solid fa-envelope-circle-check" />
                            <span>{{ __('catalog.player.verification_action') }}</span>
                        </a>
                    </div>
                @else
                    <p class="mt-3 text-sm leading-6 text-slate-500">{{ __('catalog.player.auth_hint') }}</p>
                @endif

                <dl class="mt-3 grid gap-2 text-sm text-slate-500 sm:grid-cols-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 rounded-control bg-white px-3 py-2">
                        <dt>{{ __('catalog.player.watchlist_total') }}</dt>
                        <dd class="font-bold text-slate-700">{{ $userStateSummary->watchlistCount }}</dd>
                    </div>
                    <div class="flex flex-wrap items-baseline justify-between gap-2 rounded-control bg-white px-3 py-2">
                        <dt>{{ __('catalog.player.audience_rating') }}</dt>
                        <dd class="font-bold text-slate-700">
                            @if ($userStateSummary->ratingAverage !== null)
                                {{ __('catalog.player.of_maximum', ['rating' => number_format($userStateSummary->ratingAverage, 1, ',', ''), 'maximum' => $ratingMaximum]) }} ({{ trans_choice('catalog.counts.ratings', $userStateSummary->ratingCount) }})
                            @else
                                {{ __('catalog.player.no_ratings') }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

    </x-ui.panel>

    <x-ui.panel id="seasons" :title="__('catalog.player.seasons_and_episodes')" icon="fa-solid fa-layer-group" :pad="false" class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
        <div class="relative">
            <div
                wire:loading.delay.flex
                wire:target="selectMedia"
                class="absolute inset-0 z-10 hidden items-start justify-center rounded-b-lg bg-white/80 p-4 text-center text-sm font-bold text-emerald-700 backdrop-blur-sm"
                role="status"
            >
                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-4 py-2">
                    <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                    <span>{{ __('catalog.player.updating_variant_episodes') }}</span>
                </span>
            </div>

            @if ($seasons->isNotEmpty())
                <div class="border-b border-slate-200 p-3">
                    <nav class="flex flex-wrap gap-2 pb-1" aria-label="{{ __('catalog.player.available_seasons') }}">
                        @foreach ($seasons as $seasonOption)
                            <a
                                href="{{ route('titles.show', ['catalogTitle' => $title, 'season' => $seasonOption->id]).'#seasons' }}"
                                wire:key="season-option-{{ $seasonOption->id }}"
                                wire:click.prevent="selectSeason({{ $seasonOption->id }})"
                                data-catalog-history
                                @if ($activeSeason?->id === $seasonOption->id) aria-current="true" @endif
                                @class([
                                    'inline-flex min-h-11 max-w-full items-center rounded-control px-3 py-2 text-left text-sm font-bold leading-5',
                                    'bg-emerald-700 text-white' => $activeSeason?->id === $seasonOption->id,
                                    'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' => $activeSeason?->id !== $seasonOption->id,
                                ])
                            >
                                {{ $this->seasonDisplayLabel($seasonOption) }} · {{ $this->episodeCountLabel((int) $seasonOption->available_episodes_count) }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div
                    @if ($activeSeason) id="season-{{ $activeSeason->id }}" @endif
                    class="scroll-mt-40 p-4 sm:scroll-mt-44 lg:scroll-mt-48"
                >
                    @if ($activeSeason)
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-center gap-2 font-bold text-slate-700">
                                <x-ui.icon name="fa-solid fa-layer-group" class="text-emerald-700" />
                                <span>{{ $this->seasonDisplayLabel($activeSeason) }}</span>
                            </h3>
                            <x-ui.status-pill variant="muted">{{ $this->episodeCountLabel($episodes->count()) }}</x-ui.status-pill>
                        </div>
                    @endif

                    @forelse ($episodes as $episodeOption)
                        @if ($loop->first)
                            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        @endif
                        <div
                            wire:key="season-episode-{{ $episodeOption->id }}"
                            @if ($selectedEpisode?->id === $episodeOption->id) aria-current="true" @endif
                            @class([
                                'grid content-between gap-2 rounded-lg p-2 text-left text-sm leading-5 transition',
                                'bg-emerald-50 text-emerald-800' => $selectedEpisode?->id === $episodeOption->id,
                                'bg-slate-50 text-slate-600' => $selectedEpisode?->id !== $episodeOption->id,
                            ])
                        >
                            <a
                                href="{{ route('titles.show', $showView->episodeQuery($episodeOption)).'#player' }}"
                                wire:click.prevent="selectEpisode({{ $episodeOption->id }})"
                                data-catalog-history
                                data-player-transition-episode="{{ $episodeOption->id }}"
                                class="grid min-h-20 content-center gap-1 rounded-lg px-2 py-2 hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                            >
                                <span class="flex items-center gap-2 font-bold">
                                    <x-ui.icon name="fa-solid fa-circle-play text-emerald-700" />
                                    <span>{{ $this->episodeDisplayLabel($episodeOption) }}</span>
                                </span>
                                @if ($episodeOption->title && $episodeOption->title !== $this->episodeDisplayLabel($episodeOption))
                                    <span class="block text-xs font-semibold text-slate-500">{{ $episodeOption->title }}</span>
                                @endif
                                @if ($showView->episodeSelectedProfileLabel($episodeOption))
                                    <span class="flex items-center gap-1 text-xs font-bold text-emerald-700">
                                        <x-ui.icon name="fa-solid fa-sliders" />
                                        <span>{{ $showView->episodeSelectedProfileLabel($episodeOption) }}</span>
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                                    <x-ui.icon name="fa-solid fa-file-video" />
                                    <span class="tabular-nums">{{ trans_choice('catalog.counts.videos', (int) $episodeOption->getAttribute('available_media_count')) }}</span>
                                </span>
                            </a>
                            <x-content-requests.correction-link
                                :url="$episodeCorrectionUrls[$episodeOption->id]"
                                field="episode"
                                class="w-full justify-center"
                            />
                        </div>
                        @if ($loop->last)
                            </div>
                        @endif
                    @empty
                        <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">{{ __('catalog.player.season_empty') }}</div>
                    @endforelse
                </div>
            @else
                <p class="p-4 text-sm text-slate-500">{{ __('catalog.player.seasons_empty') }}</p>
            @endif
        </div>
    </x-ui.panel>

    <div wire:loading.delay wire:target="playPrimary,selectSeason,selectEpisode,selectMedia" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700" role="status">
        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
        <span>{{ __('catalog.player.updating_episodes') }}</span>
    </div>
</div>
