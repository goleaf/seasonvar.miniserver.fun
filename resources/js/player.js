import 'plyr/dist/plyr.css';
import plyrIconUrl from '../images/plyr.svg?url';
import { accountDevicePreferences, persistAccountDevicePreferences } from './settings.js';

const PROGRESS_HEARTBEAT_MS = 30_000;
const PROGRESS_HEARTBEAT_MIN_DELTA_SECONDS = 10;
const STABLE_SEEK_DELAY_MS = 750;
const HLS_RETRY_DELAY_MS = 1_000;
const MAX_PROGRESS_DURATION_SECONDS = 86_400;
const DEVICE_PREFERENCE_WRITE_DELAY_MS = 600;
const MEDIA_SESSION_POSITION_INTERVAL_MS = 5_000;
const BUFFERING_WARNING_DELAY_MS = 15_000;
const PLAYER_NOTICE_DURATION_MS = 8_000;
const ANONYMOUS_PROGRESS_STORAGE_KEY = 'seasonvar.playback-progress.v1';
const ANONYMOUS_PROGRESS_RETENTION_MS = 30 * 24 * 60 * 60 * 1_000;
const ANONYMOUS_PROGRESS_LIMIT = 50;
const TRANSIENT_RESUME_PREFIX = 'seasonvar.player-resume.v1:';
const SUPPORTED_PLAYBACK_SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const playerSessions = new WeakMap();

let initializationGeneration = 0;
let plyrPromise = null;
let hlsPromise = null;

const playerControls = [
    'play-large',
    'play',
    'progress',
    'current-time',
    'mute',
    'volume',
    'settings',
    'pip',
    'airplay',
    'fullscreen',
];

const playerCopyShape = {
    runtime: [
        'preparing', 'loading', 'ready', 'playing', 'paused', 'seeking',
        'buffering', 'retryingNetwork', 'retryingMedia', 'expired',
        'playbackError', 'fatal', 'ended', 'captionsUnavailable',
        'offline', 'stalled', 'sourceFallback', 'sourceChanged',
        'authorizationRefreshed', 'fallbackUnavailable', 'finalEpisode',
        'autoplayCancelled', 'restartFailed',
    ],
    controls: [
        'restart', 'rewind', 'play', 'pause', 'fastForward', 'seek',
        'seekLabel', 'played', 'buffered', 'currentTime', 'duration', 'volume',
        'mute', 'unmute', 'enableCaptions', 'disableCaptions',
        'download', 'enterFullscreen', 'exitFullscreen', 'frameTitle',
        'captions', 'settings', 'pip', 'menuBack', 'speed', 'normal',
        'quality', 'loop', 'start', 'end', 'all', 'reset', 'disabled',
        'enabled', 'advertisement',
    ],
};

const emptyPlayerCopy = () => Object.fromEntries(Object.entries(playerCopyShape).map(
    ([branch, keys]) => [branch, Object.fromEntries(keys.map((key) => [key, '']))],
));

const playerCopyFor = (video) => {
    const copy = emptyPlayerCopy();
    const raw = video.closest('[data-player-shell]')?.dataset.playerCopy;

    if (!raw) {
        return copy;
    }

    try {
        const parsed = JSON.parse(raw);

        Object.entries(playerCopyShape).forEach(([branch, keys]) => {
            keys.forEach((key) => {
                const value = parsed?.[branch]?.[key];
                copy[branch][key] = typeof value === 'string' && value.trim() !== '' ? value : '';
            });
        });
    } catch {
        return copy;
    }

    return copy;
};

const noInternalRetryPolicy = (maxLoadTimeMs) => ({
    default: {
        maxTimeToFirstByteMs: Math.min(10_000, maxLoadTimeMs),
        maxLoadTimeMs,
        timeoutRetry: null,
        errorRetry: null,
    },
});

const playerStatusIcons = {
    loading: 'fa-solid fa-circle-notch fa-spin text-emerald-700',
    buffering: 'fa-solid fa-circle-notch fa-spin text-emerald-700',
    retrying: 'fa-solid fa-rotate fa-spin text-amber-700',
    expired: 'fa-solid fa-clock-rotate-left text-amber-700',
    error: 'fa-solid fa-triangle-exclamation text-rose-700',
    fatal: 'fa-solid fa-triangle-exclamation text-rose-700',
    ended: 'fa-solid fa-circle-check text-emerald-700',
};

const loadPlyr = async () => {
    plyrPromise ??= import('plyr').then((module) => module.default);

    return plyrPromise;
};

const loadHls = async () => {
    hlsPromise ??= import('hls.js/light').then((module) => module.default);

    return hlsPromise;
};

const videosWithin = (root) => {
    if (root instanceof HTMLVideoElement && root.matches('video.js-catalog-player')) {
        return [root];
    }

    if (!(root instanceof Document || root instanceof Element)) {
        return [];
    }

    return [...root.querySelectorAll('video.js-catalog-player')];
};

const clearPlayerMarkers = (video) => {
    delete video.dataset.playerReady;
    delete video.dataset.playerReserved;
    delete video.dataset.playerFailed;
};

const safeStorage = (storageName) => {
    try {
        const storage = window[storageName];
        const probe = '__seasonvar_player_probe__';

        storage.setItem(probe, '1');
        storage.removeItem(probe);

        return storage;
    } catch {
        return null;
    }
};

const legacyPlyrPreferences = () => {
    const storage = safeStorage('localStorage');

    if (!storage) {
        return {};
    }

    try {
        const value = JSON.parse(storage.getItem('plyr') || '{}');
        const volume = Number(value?.volume);

        return {
            volume: Number.isFinite(volume) && volume >= 0 && volume <= 1
                ? Math.round(volume * 100)
                : undefined,
            muted: typeof value?.muted === 'boolean' ? value.muted : undefined,
        };
    } catch {
        return {};
    }
};

const boundedInteger = (value, minimum = 0, maximum = MAX_PROGRESS_DURATION_SECONDS) => {
    const number = Number.parseInt(String(value), 10);

    return Number.isInteger(number) && number >= minimum && number <= maximum ? number : null;
};

const transientResumeKey = (episodeId) => `${TRANSIENT_RESUME_PREFIX}${episodeId}`;

const takeTransientResume = (episodeId) => {
    const storage = safeStorage('sessionStorage');

    if (!storage || !Number.isInteger(episodeId) || episodeId < 1) {
        return null;
    }

    const key = transientResumeKey(episodeId);
    const raw = storage.getItem(key);

    storage.removeItem(key);

    if (!raw) {
        return null;
    }

    try {
        const value = JSON.parse(raw);
        const position = boundedInteger(value?.position);
        const expiresAt = boundedInteger(value?.expires_at, Date.now(), Number.MAX_SAFE_INTEGER);

        if (position === null || expiresAt === null) {
            return null;
        }

        return {
            position,
            notice: ['sourceChanged', 'authorizationRefreshed'].includes(value?.notice) ? value.notice : null,
        };
    } catch {
        return null;
    }
};

const anonymousProgressEntries = () => {
    const storage = safeStorage('localStorage');

    if (!storage) {
        return { storage: null, entries: {} };
    }

    try {
        const parsed = JSON.parse(storage.getItem(ANONYMOUS_PROGRESS_STORAGE_KEY) || '{}');
        const entries = parsed?.version === 1 && parsed.entries && typeof parsed.entries === 'object'
            ? parsed.entries
            : {};

        return { storage, entries };
    } catch {
        return { storage, entries: {} };
    }
};

const anonymousResumePosition = (episodeId) => {
    if (!Number.isInteger(episodeId) || episodeId < 1) {
        return 0;
    }

    const { entries } = anonymousProgressEntries();
    const entry = entries[String(episodeId)];
    const updatedAt = boundedInteger(entry?.updated_at, 1, Number.MAX_SAFE_INTEGER);
    const position = boundedInteger(entry?.position);
    const duration = boundedInteger(entry?.duration);

    if (
        updatedAt === null
        || Date.now() - updatedAt > ANONYMOUS_PROGRESS_RETENTION_MS
        || position === null
        || entry?.completed === true
        || (duration !== null && duration > 0 && position >= duration - 5)
    ) {
        return 0;
    }

    return position;
};

const persistAnonymousProgress = (episodeId, position, duration, completed) => {
    const { storage, entries } = anonymousProgressEntries();

    if (!storage || !Number.isInteger(episodeId) || episodeId < 1) {
        return;
    }

    const now = Date.now();
    const retained = Object.entries(entries)
        .filter(([, entry]) => {
            const updatedAt = boundedInteger(entry?.updated_at, 1, Number.MAX_SAFE_INTEGER);

            return updatedAt !== null && now - updatedAt <= ANONYMOUS_PROGRESS_RETENTION_MS;
        })
        .sort(([, left], [, right]) => Number(right.updated_at) - Number(left.updated_at))
        .slice(0, ANONYMOUS_PROGRESS_LIMIT - 1);
    const nextEntries = Object.fromEntries(retained);

    nextEntries[String(episodeId)] = {
        position,
        duration,
        completed,
        updated_at: now,
    };

    try {
        storage.setItem(ANONYMOUS_PROGRESS_STORAGE_KEY, JSON.stringify({
            version: 1,
            entries: nextEntries,
        }));
    } catch {
        // Playback never depends on optional anonymous device storage.
    }
};

const removeAnonymousProgress = (episodeId) => {
    const { storage, entries } = anonymousProgressEntries();

    if (!storage || !Object.hasOwn(entries, String(episodeId))) {
        return;
    }

    delete entries[String(episodeId)];

    try {
        storage.setItem(ANONYMOUS_PROGRESS_STORAGE_KEY, JSON.stringify({ version: 1, entries }));
    } catch {
        // Playback never depends on optional anonymous device storage.
    }
};

const showFatalPlayerState = (video) => {
    const copy = playerCopyFor(video);
    const shell = video.closest('[data-player-shell]');
    const status = shell?.querySelector('[data-player-status]');
    const statusIcon = shell?.querySelector('[data-player-status-icon]');
    const statusText = shell?.querySelector('[data-player-status-text]');
    const retryButton = shell?.querySelector('[data-player-retry]');

    clearPlayerMarkers(video);
    video.dataset.playerFailed = '1';
    shell?.setAttribute('data-player-state', 'fatal');

    if (status) {
        status.hidden = false;
        status.dataset.playerState = 'fatal';
        status.setAttribute('role', 'alert');
    }

    if (statusText && copy.runtime.fatal) {
        statusText.textContent = copy.runtime.fatal;
    }

    if (statusIcon) {
        statusIcon.className = playerStatusIcons.fatal;
    }

    if (retryButton) {
        retryButton.hidden = false;
        retryButton.onclick = () => window.location.reload();
    }
};

class CatalogPlayerSession {
    constructor(video, Plyr, Hls) {
        this.video = video;
        this.Plyr = Plyr;
        this.Hls = Hls;
        this.sessionKey = video.dataset.playerSession || '';
        this.playbackSessionToken = video.dataset.progressSession || '';
        this.episodeId = Number.parseInt(video.dataset.progressEpisode || '', 10);
        this.mediaId = Number.parseInt(video.dataset.playerMediaId || '', 10);
        this.authorizationVersion = Number.parseInt(video.dataset.playerAuthorizationVersion || '0', 10) || 0;
        this.authenticated = video.dataset.accountAuthenticated === '1';
        this.root = video.closest('[data-active-player-session]');
        this.shell = video.closest('[data-player-shell]');
        this.status = this.shell?.querySelector('[data-player-status]') || null;
        this.statusIcon = this.shell?.querySelector('[data-player-status-icon]') || null;
        this.statusText = this.shell?.querySelector('[data-player-status-text]') || null;
        this.retryButton = this.shell?.querySelector('[data-player-retry]') || null;
        this.captionStatus = this.shell?.querySelector('[data-player-caption-status]') || null;
        this.notice = this.shell?.querySelector('[data-player-notice]') || null;
        this.countdown = this.shell?.querySelector('[data-player-autoplay-countdown]') || null;
        this.countdownText = this.shell?.querySelector('[data-player-countdown-text]') || null;
        this.autoplayNowButton = this.shell?.querySelector('[data-player-autoplay-now]') || null;
        this.autoplayCancelButton = this.shell?.querySelector('[data-player-autoplay-cancel]') || null;
        this.autoplayToggle = this.root?.querySelector('[data-player-autoplay-toggle]') || null;
        this.restartButton = this.root?.querySelector('[data-player-restart-episode]') || null;
        this.saveMarkerButton = this.root?.querySelector('[data-player-save-marker]') || null;
        this.shortcutsOpenButton = this.root?.querySelector('[data-player-shortcuts-open]') || null;
        this.shortcutsDialog = this.shell?.querySelector('[data-player-shortcuts-dialog]') || null;
        this.shortcutsCloseButton = this.shell?.querySelector('[data-player-shortcuts-close]') || null;
        this.copy = playerCopyFor(video);
        this.abortController = new AbortController();
        this.hls = null;
        this.plyr = null;
        this.heartbeatTimer = null;
        this.seekTimer = null;
        this.recoveryTimer = null;
        this.bufferingTimer = null;
        this.countdownTimer = null;
        this.noticeTimer = null;
        this.countdownRemaining = 0;
        this.transientResume = takeTransientResume(this.episodeId);
        const serverPosition = Number.parseInt(video.dataset.progressPosition || '', 10) || 0;
        const devicePosition = this.authenticated ? 0 : anonymousResumePosition(this.episodeId);
        this.lastSavedPosition = this.transientResume?.position ?? Math.max(serverPosition, devicePosition);
        this.progressSequence = 0;
        this.hasDispatchedProgress = false;
        this.hasStartedPlayback = false;
        this.resumePosition = this.lastSavedPosition;
        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.fallbackRequested = false;
        this.expired = false;
        this.completed = false;
        this.destroyed = false;
        this.preferenceTimer = null;
        this.lastPreferenceFingerprint = '';
        this.preferences = this.resolvePreferences();
        this.connection = navigator.connection || null;
        this.connectionChangeHandler = () => this.handleConnectionChange();
        this.dataSaver = this.connection?.saveData === true;
        this.lastMediaSessionPositionUpdate = 0;
        this.ownsMediaSession = false;
    }

    initialize() {
        const signal = this.abortController.signal;

        this.video.dataset.playerReady = '1';
        delete this.video.dataset.playerReserved;
        this.setStatus('loading', 'loading');

        this.video.addEventListener('play', () => this.handlePlay(), { signal });
        this.video.addEventListener('pause', () => this.handlePause(), { signal });
        this.video.addEventListener('seeking', () => this.handleSeeking(), { signal });
        this.video.addEventListener('seeked', () => this.handleSeeked(), { signal });
        this.video.addEventListener('timeupdate', () => this.handleTimeUpdate(), { signal });
        this.video.addEventListener('ended', () => this.handleEnded(), { signal });
        this.video.addEventListener('error', () => this.handleNativeError(), { signal });
        this.video.addEventListener('loadstart', () => this.setStatus('loading', 'loading'), { signal });
        this.video.addEventListener('loadedmetadata', () => this.handleLoadedMetadata(), { signal });
        this.video.addEventListener('canplay', () => this.handleCanPlay(), { signal });
        this.video.addEventListener('waiting', () => this.handleBuffering(), { signal });
        this.video.addEventListener('stalled', () => this.handleBuffering(), { signal });
        this.video.addEventListener('emptied', () => this.setStatus('loading', 'loading'), { signal });
        this.video.addEventListener('volumechange', () => this.scheduleDevicePreferenceWrite(), { signal });
        this.video.addEventListener('ratechange', () => this.scheduleDevicePreferenceWrite(), { signal });
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange(), { signal });
        window.addEventListener('orientationchange', () => this.handleOrientationChange(), { signal });
        window.addEventListener('online', () => this.handleConnectionChange(), { signal });
        window.addEventListener('offline', () => this.handleConnectionChange(), { signal });
        this.connection?.addEventListener?.('change', this.connectionChangeHandler);
        this.retryButton?.addEventListener('click', () => this.retry(), { signal });
        this.autoplayNowButton?.addEventListener('click', () => this.navigateToNextEpisode(), { signal });
        this.autoplayCancelButton?.addEventListener('click', () => this.cancelAutoplayCountdown(true), { signal });
        this.autoplayToggle?.addEventListener('click', () => this.toggleAutoplayPreference(), { signal });
        this.restartButton?.addEventListener('click', () => this.requestRestart(), { signal });
        this.saveMarkerButton?.addEventListener('click', () => this.requestSaveMarker(), { signal });
        this.shortcutsOpenButton?.addEventListener('click', () => this.openShortcutHelp(), { signal });
        this.shortcutsCloseButton?.addEventListener('click', () => this.closeShortcutHelp(), { signal });
        this.root?.addEventListener('click', (event) => this.handleRootClick(event), { capture: true, signal });
        this.root?.addEventListener('playback-fallback-unavailable', () => {
            this.fallbackRequested = false;
            this.setStatus('error', 'fallbackUnavailable', true);
        }, { signal });
        document.addEventListener('keydown', (event) => this.handleKeyboard(event), { signal });

        if (this.dataSaver) {
            this.preferences.autoplay = false;
            this.video.preload = 'none';
            const dataSaverStatus = this.shell?.querySelector('[data-player-data-saver]');

            if (dataSaverStatus instanceof HTMLElement) {
                dataSaverStatus.hidden = false;
            }
        }

        this.initializeHls();
        this.initializeCaptionTracks();
        this.video.autoplay = this.preferences.autoplay;
        this.video.volume = this.preferences.volume / 100;
        this.video.muted = this.preferences.muted;
        this.plyr = new this.Plyr(this.video, {
            controls: playerControls,
            i18n: this.copy.controls,
            iconUrl: plyrIconUrl,
            blankVideo: 'data:video/mp4;base64,',
            autoplay: this.preferences.autoplay,
            volume: this.preferences.volume / 100,
            muted: this.preferences.muted,
            speed: {
                selected: this.preferences.speed,
                options: SUPPORTED_PLAYBACK_SPEEDS,
            },
            captions: {
                active: this.preferences.subtitlesEnabled,
                language: 'auto',
                update: true,
            },
            keyboard: {
                focused: this.preferences.keyboardShortcutsEnabled,
                global: false,
            },
            storage: {
                enabled: false,
            },
        });
        this.lastPreferenceFingerprint = `${this.preferences.volume}|${this.preferences.muted ? 1 : 0}|${Number(this.preferences.speed).toFixed(2)}`;
        this.initializeMediaSession();
        this.updateAutoplayToggle();

        if (this.transientResume?.notice) {
            this.showNotice(this.transientResume.notice);
        }
    }

    initializeHls() {
        const hlsSource = this.video.dataset.hlsSrc;

        if (!hlsSource) {
            return;
        }

        if (!this.Hls?.isSupported()) {
            this.video.src = hlsSource;

            return;
        }

        this.replaceHls(hlsSource);
    }

    replaceHls(hlsSource = this.video.dataset.hlsSrc) {
        if (!hlsSource || this.destroyed) {
            return;
        }

        this.hls?.destroy();
        this.hls = new this.Hls({
            enableWorker: true,
            lowLatencyMode: false,
            autoStartLoad: !this.dataSaver,
            ...(this.dataSaver ? {
                maxBufferLength: 15,
                maxMaxBufferLength: 30,
                backBufferLength: 15,
            } : {}),
            manifestLoadPolicy: noInternalRetryPolicy(20_000),
            playlistLoadPolicy: noInternalRetryPolicy(20_000),
            fragLoadPolicy: noInternalRetryPolicy(30_000),
        });
        this.hls.on(this.Hls.Events.ERROR, (_event, data) => this.handleHlsError(data));
        this.hls.loadSource(hlsSource);
        this.hls.attachMedia(this.video);
    }

    initializeCaptionTracks() {
        const signal = this.abortController.signal;
        const tracks = this.video.querySelectorAll('track[kind="subtitles"], track[kind="captions"]');

        tracks.forEach((track) => {
            track.addEventListener('load', () => {
                if (this.captionStatus) {
                    this.captionStatus.hidden = true;
                    this.captionStatus.textContent = '';
                }
            }, { signal });
            track.addEventListener('error', () => {
                const text = this.copy.runtime.captionsUnavailable || '';

                if (text && this.captionStatus && !this.destroyed) {
                    this.captionStatus.textContent = text;
                    this.captionStatus.hidden = false;
                }
            }, { signal });
        });
    }

    handlePlay() {
        this.cancelAutoplayCountdown(false);
        this.clearBufferingTimer();
        this.hasStartedPlayback = true;
        this.hls?.startLoad?.();
        this.setStatus('playing', 'playing');
        this.syncMediaSessionPlaybackState('playing');
        this.syncMediaSessionPosition(true);
        this.dispatchProgress(false, true, 'play');
        this.startHeartbeat();
    }

    handlePause() {
        this.stopHeartbeat();
        this.persistDevicePreferences();

        if (!this.video.ended) {
            this.flushProgress('pause');
            this.setStatus('paused', 'paused');
            this.syncMediaSessionPlaybackState('paused');
            this.syncMediaSessionPosition(true);
        }
    }

    handleSeeking() {
        this.clearSeekTimer();
        this.setStatus('buffering', 'seeking');
    }

    handleSeeked() {
        this.clearSeekTimer();
        this.seekTimer = window.setTimeout(() => {
            this.seekTimer = null;
            this.flushProgress('seeked');
            this.setStatus(this.video.paused ? 'paused' : 'playing', this.video.paused ? 'paused' : 'playing');
            this.syncMediaSessionPosition(true);
        }, STABLE_SEEK_DELAY_MS);
    }

    handleTimeUpdate() {
        if (!Number.isFinite(this.video.currentTime)) {
            return;
        }

        this.shell?.setAttribute('data-player-position', String(Math.max(0, Math.floor(this.video.currentTime))));
        this.syncMediaSessionPosition();
    }

    handleEnded() {
        if (this.completed) {
            return;
        }

        this.completed = true;
        this.stopHeartbeat();
        this.dispatchProgress(true, true, 'ended');
        this.setStatus('ended', 'ended');
        this.syncMediaSessionPlaybackState('none');
        this.syncMediaSessionPosition(true);
        this.startAutoplayCountdown();
    }

    handleLoadedMetadata() {
        if (
            Number.isInteger(this.resumePosition)
            && this.resumePosition > 0
            && Number.isFinite(this.video.duration)
            && this.resumePosition < this.video.duration - 5
            && this.video.currentTime < 1
        ) {
            this.video.currentTime = this.resumePosition;
        }

        this.setStatus('ready', 'ready');
        this.syncMediaSessionPosition(true);
    }

    handleCanPlay() {
        this.clearBufferingTimer();
        this.setStatus('ready', 'ready');
    }

    handleBuffering() {
        this.setStatus('buffering', 'buffering');
        this.clearBufferingTimer();
        this.bufferingTimer = window.setTimeout(() => {
            this.bufferingTimer = null;

            if (!this.destroyed && this.video.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) {
                this.setStatus('buffering', 'stalled', true);
            }
        }, BUFFERING_WARNING_DELAY_MS);
    }

    handleNativeError() {
        if (this.destroyed || this.hls) {
            return;
        }

        if (navigator.onLine !== false && this.networkRetries < 1) {
            this.networkRetries += 1;
            this.clearRecoveryTimer();
            this.setStatus('retrying', 'retryingNetwork');
            this.recoveryTimer = window.setTimeout(() => {
                this.recoveryTimer = null;
                this.video.load();
            }, HLS_RETRY_DELAY_MS);

            return;
        }

        this.setFailure(false);
    }

    handleHlsError(data) {
        if (this.destroyed || !data?.fatal) {
            return;
        }

        const responseCode = Number(data.response?.code || data.networkDetails?.status || 0);

        if ([401, 403, 410].includes(responseCode)) {
            this.setFailure(true);

            return;
        }

        if (data.type === this.Hls.ErrorTypes.NETWORK_ERROR && this.networkRetries < 1) {
            this.networkRetries += 1;
            this.clearRecoveryTimer();
            this.setStatus('retrying', 'retryingNetwork');
            this.recoveryTimer = window.setTimeout(() => {
                this.recoveryTimer = null;
                this.replaceHls();
            }, HLS_RETRY_DELAY_MS);

            return;
        }

        if (data.type === this.Hls.ErrorTypes.MEDIA_ERROR && this.mediaRecoveries < 1) {
            this.mediaRecoveries += 1;
            this.clearRecoveryTimer();
            this.setStatus('retrying', 'retryingMedia');
            this.hls?.recoverMediaError();

            return;
        }

        this.setFailure(false);
    }

    handleRootClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        const mediaOption = target?.closest('[data-player-media-option]');

        if (mediaOption instanceof HTMLAnchorElement && mediaOption.getAttribute('aria-current') !== 'true') {
            this.queueTransientResume();
        }
    }

    handleKeyboard(event) {
        if (!this.preferences.keyboardShortcutsEnabled || this.destroyed || !this.root) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const editable = target?.matches('input, textarea, select, [contenteditable="true"]');
        const withinPlayer = target !== null && (
            this.shell?.contains(target)
            || this.autoplayToggle?.contains(target)
            || this.restartButton?.contains(target)
            || this.shortcutsOpenButton?.contains(target)
        );

        if (editable || !withinPlayer) {
            return;
        }

        if (event.key === 'Escape') {
            this.cancelAutoplayCountdown(true);
            this.closeShortcutHelp();

            return;
        }

        if (event.key === '?' || (event.key === '/' && event.shiftKey)) {
            event.preventDefault();
            this.openShortcutHelp();

            return;
        }

        if (event.shiftKey && event.key.toLowerCase() === 'n') {
            event.preventDefault();
            this.navigateToNextEpisode();

            return;
        }

        if (event.shiftKey && event.key.toLowerCase() === 'p') {
            const previous = this.root.querySelector('[data-player-previous-episode]');

            if (previous instanceof HTMLAnchorElement) {
                event.preventDefault();
                this.cancelAutoplayCountdown(false);
                previous.click();
            }

            return;
        }

        if (event.key.toLowerCase() === 'p' && !event.shiftKey) {
            this.togglePictureInPicture(event);
        }
    }

    handleVisibilityChange() {
        if (document.visibilityState === 'hidden') {
            this.stopHeartbeat();
            this.flushProgress('visibility-hidden');

            return;
        }

        if (!this.video.paused && !this.video.ended) {
            this.startHeartbeat();
        }
    }

    handleOrientationChange() {
        window.requestAnimationFrame(() => {
            if (!this.destroyed && this.video.isConnected) {
                this.plyr?.fullscreen?.update();
            }
        });
    }

    handleConnectionChange() {
        this.dataSaver = this.connection?.saveData === true;

        if (navigator.onLine === false && this.status?.dataset.playerState === 'error') {
            this.setStatus('error', 'offline', true);
        }
    }

    toggleAutoplayPreference() {
        this.preferences.autoplay = !this.preferences.autoplay;
        this.video.autoplay = this.preferences.autoplay;
        this.updateAutoplayToggle();

        persistAccountDevicePreferences({
            autoplay: this.preferences.autoplay,
        }, document.body.dataset.accountStorageKey || undefined);

        if (this.authenticated) {
            this.video.dispatchEvent(new CustomEvent('catalog-autoplay-preference', {
                bubbles: true,
                detail: {
                    sessionKey: this.sessionKey,
                    enabled: this.preferences.autoplay,
                    reject: () => {
                        this.preferences.autoplay = !this.preferences.autoplay;
                        this.video.autoplay = this.preferences.autoplay;
                        this.updateAutoplayToggle();
                    },
                },
            }));
        }

        if (!this.preferences.autoplay) {
            this.cancelAutoplayCountdown(true);
        }
    }

    updateAutoplayToggle() {
        if (!(this.autoplayToggle instanceof HTMLButtonElement)) {
            return;
        }

        const label = this.autoplayToggle.querySelector('[data-player-autoplay-label]');

        this.autoplayToggle.setAttribute('aria-pressed', this.preferences.autoplay ? 'true' : 'false');

        if (label instanceof HTMLElement) {
            label.textContent = this.preferences.autoplay
                ? (this.autoplayToggle.dataset.playerAutoplayOn || '')
                : (this.autoplayToggle.dataset.playerAutoplayOff || '');
        }
    }

    startAutoplayCountdown() {
        this.cancelAutoplayCountdown(false);
        const next = this.root?.querySelector('[data-player-next-episode]');

        if (!(next instanceof HTMLAnchorElement)) {
            this.showNotice('finalEpisode');

            return;
        }

        if (!this.preferences.autoplay || !(this.countdown instanceof HTMLElement)) {
            return;
        }

        this.countdownRemaining = Math.max(3, Math.min(
            30,
            Number.parseInt(this.shell?.dataset.playerCountdownSeconds || '8', 10) || 8,
        ));
        this.countdown.hidden = false;
        this.renderAutoplayCountdown();
        this.countdownTimer = window.setInterval(() => {
            this.countdownRemaining -= 1;

            if (this.countdownRemaining <= 0) {
                this.navigateToNextEpisode();

                return;
            }

            this.renderAutoplayCountdown();
        }, 1_000);
        this.autoplayCancelButton?.focus({ preventScroll: true });
    }

    renderAutoplayCountdown() {
        if (!(this.countdownText instanceof HTMLElement)) {
            return;
        }

        const template = this.countdownText.dataset.playerCountdownTemplate || '';

        this.countdownText.textContent = template.replace(':seconds', String(this.countdownRemaining));
    }

    cancelAutoplayCountdown(announce = false) {
        const wasActive = this.countdownTimer !== null
            || (this.countdown instanceof HTMLElement && !this.countdown.hidden);

        if (this.countdownTimer !== null) {
            window.clearInterval(this.countdownTimer);
            this.countdownTimer = null;
        }

        this.countdownRemaining = 0;

        if (this.countdown instanceof HTMLElement) {
            this.countdown.hidden = true;
        }

        if (announce && wasActive) {
            this.showNotice('autoplayCancelled');
        }
    }

    navigateToNextEpisode() {
        const next = this.root?.querySelector('[data-player-next-episode]');

        if (!(next instanceof HTMLAnchorElement)) {
            this.cancelAutoplayCountdown(false);
            this.showNotice('finalEpisode');

            return;
        }

        this.cancelAutoplayCountdown(false);
        this.flushProgress('next-episode');
        next.click();
    }

    requestRestart() {
        this.cancelAutoplayCountdown(false);

        if (!this.authenticated) {
            this.applyRestart();

            return;
        }

        this.video.dispatchEvent(new CustomEvent('catalog-restart-progress', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                episodeId: this.episodeId,
                playbackSessionToken: this.playbackSessionToken,
                complete: () => this.applyRestart(),
                fail: () => this.showNotice('restartFailed'),
            },
        }));
    }

    requestSaveMarker() {
        if (!Number.isInteger(this.episodeId) || this.episodeId < 1 || !Number.isFinite(this.video.currentTime)) {
            return;
        }

        this.video.dispatchEvent(new CustomEvent('catalog-save-playback-marker', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                episodeId: this.episodeId,
                positionSeconds: Math.max(0, Math.floor(this.video.currentTime)),
            },
        }));
    }

    applyRestart() {
        removeAnonymousProgress(this.episodeId);
        this.completed = false;
        this.resumePosition = 0;
        this.lastSavedPosition = 0;
        this.hasDispatchedProgress = false;
        this.video.currentTime = 0;
        void this.video.play().catch(() => {
            this.setStatus('ready', 'ready');
        });
    }

    togglePictureInPicture(event) {
        if (
            document.pictureInPictureEnabled !== true
            || this.video.disablePictureInPicture === true
            || typeof this.video.requestPictureInPicture !== 'function'
        ) {
            return;
        }

        event.preventDefault();

        if (document.pictureInPictureElement === this.video) {
            if (typeof document.exitPictureInPicture === 'function') {
                void document.exitPictureInPicture().catch(() => {});
            }

            return;
        }

        void this.video.requestPictureInPicture().catch(() => {});
    }

    openShortcutHelp() {
        if (!(this.shortcutsDialog instanceof HTMLDialogElement) || this.shortcutsDialog.open) {
            return;
        }

        try {
            this.shortcutsDialog.showModal();
        } catch {
            this.shortcutsDialog.setAttribute('open', '');
        }

        this.shortcutsCloseButton?.focus({ preventScroll: true });
    }

    closeShortcutHelp(restoreFocus = true) {
        if (!(this.shortcutsDialog instanceof HTMLDialogElement) || !this.shortcutsDialog.open) {
            return;
        }

        this.shortcutsDialog.close();

        if (restoreFocus) {
            this.shortcutsOpenButton?.focus({ preventScroll: true });
        }
    }

    queueTransientResume(notice = null) {
        const storage = safeStorage('sessionStorage');
        const position = Number.isFinite(this.video.currentTime)
            ? Math.max(0, Math.floor(this.video.currentTime))
            : 0;

        if (!storage || !Number.isInteger(this.episodeId) || this.episodeId < 1) {
            return;
        }

        try {
            storage.setItem(transientResumeKey(this.episodeId), JSON.stringify({
                position,
                notice,
                expires_at: Date.now() + 5 * 60 * 1_000,
            }));
        } catch {
            // A failed optional handoff must not block source recovery.
        }
    }

    showNotice(copyKey) {
        const text = this.copy.runtime[copyKey] || '';

        if (!text || !(this.notice instanceof HTMLElement)) {
            return;
        }

        window.clearTimeout(this.noticeTimer);
        this.notice.textContent = text;
        this.notice.hidden = false;
        this.noticeTimer = window.setTimeout(() => {
            this.noticeTimer = null;

            if (this.notice instanceof HTMLElement) {
                this.notice.hidden = true;
                this.notice.textContent = '';
            }
        }, PLAYER_NOTICE_DURATION_MS);
    }

    initializeMediaSession() {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function') {
            return;
        }

        const metadata = {
            title: this.video.dataset.mediaTitle || document.title,
            artist: this.video.dataset.mediaArtist || '',
            album: this.video.dataset.mediaAlbum || '',
        };
        const artwork = this.video.dataset.mediaArtwork;

        if (artwork) {
            metadata.artwork = [{ src: artwork }];
        }

        try {
            navigator.mediaSession.metadata = new window.MediaMetadata(metadata);
            this.ownsMediaSession = true;
        } catch {
            return;
        }

        this.setMediaSessionAction('play', () => void this.video.play());
        this.setMediaSessionAction('pause', () => this.video.pause());
        this.setMediaSessionAction('seekbackward', (details) => {
            this.seekMediaBy(-Math.max(1, Number(details.seekOffset) || 10));
        });
        this.setMediaSessionAction('seekforward', (details) => {
            this.seekMediaBy(Math.max(1, Number(details.seekOffset) || 10));
        });
        this.setMediaSessionAction('seekto', (details) => {
            if (Number.isFinite(details.seekTime)) {
                this.video.currentTime = Math.max(0, Math.min(details.seekTime, this.video.duration || details.seekTime));
            }
        });

        const playerRoot = this.video.closest('[data-active-player-session]');
        const previous = playerRoot?.querySelector('[data-player-previous-episode]');
        const next = playerRoot?.querySelector('[data-player-next-episode]');

        if (previous instanceof HTMLAnchorElement) {
            this.setMediaSessionAction('previoustrack', () => previous.click());
        }

        if (next instanceof HTMLAnchorElement) {
            this.setMediaSessionAction('nexttrack', () => next.click());
        }

        this.syncMediaSessionPlaybackState(this.video.paused ? 'paused' : 'playing');
        this.syncMediaSessionPosition(true);
    }

    setMediaSessionAction(action, handler) {
        try {
            navigator.mediaSession.setActionHandler(action, handler);
        } catch {
            // Unsupported Media Session actions stay absent without a fake control.
        }
    }

    seekMediaBy(offset) {
        if (!Number.isFinite(this.video.duration)) {
            return;
        }

        this.video.currentTime = Math.max(0, Math.min(this.video.duration, this.video.currentTime + offset));
    }

    syncMediaSessionPlaybackState(state) {
        if (!this.ownsMediaSession) {
            return;
        }

        try {
            navigator.mediaSession.playbackState = state;
        } catch {
            // Older implementations expose metadata but not playback state.
        }
    }

    syncMediaSessionPosition(force = false) {
        if (!this.ownsMediaSession || typeof navigator.mediaSession.setPositionState !== 'function') {
            return;
        }

        const now = Date.now();

        if (!force && now - this.lastMediaSessionPositionUpdate < MEDIA_SESSION_POSITION_INTERVAL_MS) {
            return;
        }

        if (!Number.isFinite(this.video.duration) || this.video.duration <= 0 || !Number.isFinite(this.video.currentTime)) {
            return;
        }

        this.lastMediaSessionPositionUpdate = now;

        try {
            navigator.mediaSession.setPositionState({
                duration: this.video.duration,
                playbackRate: this.video.playbackRate || 1,
                position: Math.max(0, Math.min(this.video.currentTime, this.video.duration)),
            });
        } catch {
            // Invalid transient media state is ignored until the next bounded update.
        }
    }

    clearMediaSession() {
        if (!this.ownsMediaSession || !('mediaSession' in navigator)) {
            return;
        }

        ['play', 'pause', 'seekbackward', 'seekforward', 'seekto', 'previoustrack', 'nexttrack'].forEach((action) => {
            try {
                navigator.mediaSession.setActionHandler(action, null);
            } catch {
                // Unsupported actions were never registered.
            }
        });

        navigator.mediaSession.metadata = null;
        this.syncMediaSessionPlaybackState('none');
        this.ownsMediaSession = false;
    }

    startHeartbeat() {
        this.stopHeartbeat();
        this.heartbeatTimer = window.setInterval(() => {
            if (!this.video.paused && !this.video.ended && document.visibilityState === 'visible') {
                this.dispatchProgress(false, false, 'heartbeat');
            }
        }, PROGRESS_HEARTBEAT_MS);
    }

    stopHeartbeat() {
        if (this.heartbeatTimer !== null) {
            window.clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    clearSeekTimer() {
        if (this.seekTimer !== null) {
            window.clearTimeout(this.seekTimer);
            this.seekTimer = null;
        }
    }

    clearRecoveryTimer() {
        if (this.recoveryTimer !== null) {
            window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
        }
    }

    clearBufferingTimer() {
        if (this.bufferingTimer !== null) {
            window.clearTimeout(this.bufferingTimer);
            this.bufferingTimer = null;
        }
    }

    resolvePreferences() {
        const storageKey = document.body.dataset.accountStorageKey || undefined;
        const device = accountDevicePreferences(storageKey);
        const authenticated = this.video.dataset.accountAuthenticated === '1';
        const legacy = authenticated ? {} : legacyPlyrPreferences();
        const accountVersion = Number.parseInt(document.body.dataset.accountSettingsVersion || '1', 10) || 1;
        const deviceMatchesAccount = !authenticated || Number(device.account_version || 0) >= accountVersion;
        const serverRememberVolume = this.video.dataset.accountRememberVolume !== '0';
        const serverVolume = Number.parseInt(this.video.dataset.accountVolume || '70', 10);
        const serverSpeed = Number.parseFloat(this.video.dataset.accountSpeed || '1') || 1;
        const deviceSpeed = Number.parseFloat(device.playback_speed);
        const server = {
            autoplay: this.video.dataset.accountAutoplay === '1',
            rememberVolume: serverRememberVolume,
            volume: Number.isInteger(serverVolume) ? Math.min(100, Math.max(0, serverVolume)) : 70,
            muted: this.video.dataset.accountMuted === '1',
            speed: SUPPORTED_PLAYBACK_SPEEDS.includes(serverSpeed) ? serverSpeed : 1,
            subtitlesEnabled: this.video.dataset.accountSubtitles === '1',
            keyboardShortcutsEnabled: this.video.dataset.accountKeyboard !== '0',
        };
        const rememberVolume = authenticated
            ? server.rememberVolume
            : (typeof device.remember_volume === 'boolean' ? device.remember_volume : server.rememberVolume);

        return {
            autoplay: authenticated ? server.autoplay : (device.autoplay ?? server.autoplay),
            rememberVolume,
            volume: rememberVolume && deviceMatchesAccount ? (device.volume ?? legacy.volume ?? server.volume) : (rememberVolume ? server.volume : 70),
            muted: rememberVolume && deviceMatchesAccount ? (device.muted ?? legacy.muted ?? server.muted) : (rememberVolume ? server.muted : false),
            speed: authenticated
                ? server.speed
                : (SUPPORTED_PLAYBACK_SPEEDS.includes(deviceSpeed) ? deviceSpeed : server.speed),
            subtitlesEnabled: authenticated ? server.subtitlesEnabled : (device.subtitles_enabled ?? server.subtitlesEnabled),
            keyboardShortcutsEnabled: authenticated
                ? server.keyboardShortcutsEnabled
                : (device.keyboard_shortcuts_enabled ?? server.keyboardShortcutsEnabled),
        };
    }

    scheduleDevicePreferenceWrite() {
        if (this.destroyed) {
            return;
        }

        window.clearTimeout(this.preferenceTimer);
        this.preferenceTimer = window.setTimeout(() => {
            this.preferenceTimer = null;
            this.persistDevicePreferences();
        }, DEVICE_PREFERENCE_WRITE_DELAY_MS);
    }

    persistDevicePreferences() {
        if (this.destroyed) {
            return;
        }

        const volume = Math.round(this.video.volume * 100);
        const speed = Number(this.video.playbackRate || 1).toFixed(2);
        const payload = {
            remember_volume: this.preferences.rememberVolume,
            playback_speed: Number(this.video.playbackRate || 1).toFixed(2),
        };

        if (this.preferences.rememberVolume) {
            payload.volume = volume;
            payload.muted = this.video.muted;
        }

        persistAccountDevicePreferences(payload, document.body.dataset.accountStorageKey || undefined);

        if (!this.authenticated) {
            return;
        }

        const fingerprint = `${volume}|${this.video.muted ? 1 : 0}|${speed}`;

        if (fingerprint === this.lastPreferenceFingerprint) {
            return;
        }

        this.lastPreferenceFingerprint = fingerprint;
        this.video.dispatchEvent(new CustomEvent('catalog-player-preferences', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                volume,
                muted: this.video.muted,
                speed,
            },
        }));
    }

    flushProgress(reason = 'flush') {
        this.dispatchProgress(false, true, reason);
    }

    dispatchProgress(completed = false, force = false, reason = 'update') {
        const canReportToServer = this.canReportProgress();
        const canStoreAnonymously = this.canStoreAnonymousProgress();

        if (!canReportToServer && !canStoreAnonymously) {
            return;
        }

        if (!Number.isFinite(this.video.currentTime) || this.video.currentTime < 0) {
            return;
        }

        const durationSeconds = Number.isFinite(this.video.duration)
            && this.video.duration >= 1
            && this.video.duration <= MAX_PROGRESS_DURATION_SECONDS
            ? Math.floor(this.video.duration)
            : 0;
        const positionSeconds = Math.max(0, Math.floor(completed && durationSeconds > 0
            ? durationSeconds
            : this.video.currentTime));
        const progressDelta = Math.abs(positionSeconds - this.lastSavedPosition);

        if (!completed && this.hasDispatchedProgress && progressDelta === 0) {
            return;
        }

        if (!force && !completed && progressDelta < PROGRESS_HEARTBEAT_MIN_DELTA_SECONDS) {
            return;
        }

        this.lastSavedPosition = positionSeconds;
        this.hasDispatchedProgress = true;
        if (canStoreAnonymously) {
            persistAnonymousProgress(this.episodeId, positionSeconds, durationSeconds, completed);
        }

        if (canReportToServer) {
            this.video.dispatchEvent(new CustomEvent('catalog-progress', {
                bubbles: true,
                detail: {
                    sessionKey: this.sessionKey,
                    playbackSessionToken: this.playbackSessionToken,
                    eventSequence: ++this.progressSequence,
                    episodeId: this.episodeId,
                    positionSeconds,
                    durationSeconds,
                    completed,
                    reason,
                },
            }));
        }
    }

    canReportProgress() {
        if (
            this.destroyed
            || !this.hasStartedPlayback
            || this.video.dataset.progressEnabled !== '1'
            || this.playbackSessionToken === ''
        ) {
            return false;
        }

        const episodeId = Number.parseInt(this.video.dataset.progressEpisode || '', 10);
        const activePlayer = this.video.closest('[data-active-player-session]');

        return Number.isInteger(episodeId)
            && episodeId > 0
            && this.sessionKey !== ''
            && activePlayer?.dataset.activePlayerSession === this.sessionKey
            && playerSessions.get(this.video) === this;
    }

    canStoreAnonymousProgress() {
        return !this.authenticated
            && !this.destroyed
            && this.hasStartedPlayback
            && Number.isInteger(this.episodeId)
            && this.episodeId > 0
            && this.sessionKey !== ''
            && this.root?.dataset.activePlayerSession === this.sessionKey
            && playerSessions.get(this.video) === this;
    }

    setFailure(expired) {
        this.expired = expired;
        this.stopHeartbeat();
        this.clearRecoveryTimer();
        this.clearBufferingTimer();
        const offline = !expired && navigator.onLine === false;
        this.setStatus(
            expired ? 'expired' : 'error',
            expired ? 'expired' : (offline ? 'offline' : 'playbackError'),
            true,
        );

        if (!expired && !offline) {
            this.requestSourceFallback();
        }
    }

    requestSourceFallback() {
        if (this.fallbackRequested || !Number.isInteger(this.mediaId) || this.mediaId < 1) {
            return;
        }

        this.fallbackRequested = true;
        this.queueTransientResume('sourceChanged');
        this.setStatus('retrying', 'sourceFallback');
        this.video.dispatchEvent(new CustomEvent('catalog-source-fallback', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                failedMediaId: this.mediaId,
                fail: () => {
                    this.fallbackRequested = false;
                    this.setStatus('error', 'fallbackUnavailable', true);
                },
            },
        }));
    }

    setStatus(state, copyKey, canRetry = false) {
        if (this.destroyed || !this.status) {
            return;
        }

        this.status.dataset.playerState = state;
        this.shell?.setAttribute('data-player-state', state);
        this.status.hidden = false;

        const text = this.copy.runtime[copyKey] || '';

        if (text && this.statusText) {
            this.statusText.textContent = text;
        }

        if (this.statusIcon) {
            this.statusIcon.className = playerStatusIcons[state] || 'fa-solid fa-circle-info text-emerald-700';
        }

        if (this.retryButton) {
            this.retryButton.hidden = !canRetry;
        }
    }

    retry() {
        if (this.destroyed) {
            return;
        }

        this.clearRecoveryTimer();

        if (navigator.onLine === false) {
            this.setStatus('error', 'offline', true);

            return;
        }

        if (this.expired || (!this.hls && this.authorizationVersion === 0)) {
            this.queueTransientResume('authorizationRefreshed');
            this.setStatus('retrying', 'retryingNetwork');
            this.video.dispatchEvent(new CustomEvent('catalog-source-refresh', {
                bubbles: true,
                detail: {
                    sessionKey: this.sessionKey,
                    fail: () => this.setStatus('error', 'playbackError', true),
                },
            }));

            return;
        }

        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.fallbackRequested = false;
        this.setStatus('retrying', 'retryingNetwork');

        if (this.hls) {
            this.replaceHls();
        } else {
            this.video.load();
        }
    }

    destroy({ flush = true, reason = 'destroy' } = {}) {
        if (this.destroyed) {
            return null;
        }

        if (flush) {
            this.flushProgress(reason);
        }

        this.persistDevicePreferences();
        this.destroyed = true;
        this.stopHeartbeat();
        this.clearSeekTimer();
        this.clearRecoveryTimer();
        this.clearBufferingTimer();
        this.cancelAutoplayCountdown(false);
        this.closeShortcutHelp(false);

        if (this.noticeTimer !== null) {
            window.clearTimeout(this.noticeTimer);
            this.noticeTimer = null;
        }

        if (this.preferenceTimer !== null) {
            window.clearTimeout(this.preferenceTimer);
            this.preferenceTimer = null;
        }

        this.abortController.abort();
        this.connection?.removeEventListener?.('change', this.connectionChangeHandler);
        this.clearMediaSession();
        this.hls?.destroy();
        let restoredVideo = this.video;

        if (this.plyr) {
            this.plyr.destroy(function () {
                restoredVideo = this;
                clearPlayerMarkers(this);
            });
        }

        try {
            this.video.pause();
        } catch {
            // The media element may already have been detached by Livewire.
        }

        this.hls = null;
        this.plyr = null;
        playerSessions.delete(this.video);
        clearPlayerMarkers(this.video);

        return restoredVideo;
    }
}

export const initializeCatalogPlayers = async (root = document) => {
    const generation = initializationGeneration;
    const videos = videosWithin(root).filter((video) => (
        !playerSessions.has(video)
        && !video.dataset.playerReserved
        && !video.dataset.playerFailed
        && video.dataset.playerSession
    ));

    if (videos.length === 0) {
        return;
    }

    videos.forEach((video) => {
        video.dataset.playerReserved = String(generation);
    });

    const needsHls = videos.some((video) => Boolean(video.dataset.hlsSrc));
    let Plyr;
    let Hls;

    try {
        [Plyr, Hls] = await Promise.all([
            loadPlyr(),
            needsHls ? loadHls() : Promise.resolve(null),
        ]);
    } catch {
        videos.filter((video) => video.isConnected).forEach(showFatalPlayerState);

        return;
    }

    videos.forEach((video) => {
        if (
            generation !== initializationGeneration
            || !video.isConnected
            || video.dataset.playerReserved !== String(generation)
            || playerSessions.has(video)
        ) {
            delete video.dataset.playerReserved;

            return;
        }

        const session = new CatalogPlayerSession(video, Plyr, Hls);

        playerSessions.set(video, session);

        try {
            session.initialize();
        } catch {
            const restoredVideo = session.destroy({ flush: false });

            showFatalPlayerState(restoredVideo || video);
        }
    });
};

export const flushCatalogPlayersWithin = (root = document, reason = 'flush') => {
    videosWithin(root).forEach((video) => playerSessions.get(video)?.flushProgress(reason));
};

export const destroyCatalogPlayersWithin = (root = document, { flush = true, reason = 'destroy' } = {}) => {
    initializationGeneration += 1;

    videosWithin(root).forEach((video) => {
        playerSessions.get(video)?.destroy({ flush, reason });
        delete video.dataset.playerReserved;
    });
};
