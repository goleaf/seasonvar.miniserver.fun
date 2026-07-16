import 'plyr/dist/plyr.css';
import plyrIconUrl from '../images/plyr.svg?url';
import { accountDevicePreferences, persistAccountDevicePreferences } from './settings.js';

const PROGRESS_HEARTBEAT_MS = 30_000;
const PROGRESS_HEARTBEAT_MIN_DELTA_SECONDS = 10;
const STABLE_SEEK_DELAY_MS = 750;
const HLS_RETRY_DELAY_MS = 1_000;
const MAX_PROGRESS_DURATION_SECONDS = 86_400;
const DEVICE_PREFERENCE_WRITE_DELAY_MS = 600;

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
        this.shell = video.closest('[data-player-shell]');
        this.status = this.shell?.querySelector('[data-player-status]') || null;
        this.statusIcon = this.shell?.querySelector('[data-player-status-icon]') || null;
        this.statusText = this.shell?.querySelector('[data-player-status-text]') || null;
        this.retryButton = this.shell?.querySelector('[data-player-retry]') || null;
        this.captionStatus = this.shell?.querySelector('[data-player-caption-status]') || null;
        this.copy = playerCopyFor(video);
        this.abortController = new AbortController();
        this.hls = null;
        this.plyr = null;
        this.heartbeatTimer = null;
        this.seekTimer = null;
        this.recoveryTimer = null;
        this.lastSavedPosition = Number.parseInt(video.dataset.progressPosition || '', 10) || 0;
        this.progressSequence = 0;
        this.hasDispatchedProgress = false;
        this.hasStartedPlayback = false;
        this.resumePosition = this.lastSavedPosition;
        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.expired = false;
        this.completed = false;
        this.destroyed = false;
        this.preferenceTimer = null;
        this.preferences = this.resolvePreferences();
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
        this.video.addEventListener('canplay', () => this.setStatus('ready', 'ready'), { signal });
        this.video.addEventListener('waiting', () => this.setStatus('buffering', 'buffering'), { signal });
        this.video.addEventListener('stalled', () => this.setStatus('buffering', 'buffering'), { signal });
        this.video.addEventListener('emptied', () => this.setStatus('loading', 'loading'), { signal });
        this.video.addEventListener('volumechange', () => this.scheduleDevicePreferenceWrite(), { signal });
        this.video.addEventListener('ratechange', () => this.scheduleDevicePreferenceWrite(), { signal });
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange(), { signal });
        window.addEventListener('orientationchange', () => this.handleOrientationChange(), { signal });
        this.retryButton?.addEventListener('click', () => this.retry(), { signal });

        this.initializeHls();
        this.initializeCaptionTracks();
        this.video.autoplay = this.preferences.autoplay;
        this.video.volume = this.preferences.volume / 100;
        this.video.muted = this.preferences.muted;
        this.plyr = new this.Plyr(this.video, {
            controls: playerControls,
            i18n: this.copy.controls,
            iconUrl: plyrIconUrl,
            autoplay: this.preferences.autoplay,
            volume: this.preferences.volume / 100,
            muted: this.preferences.muted,
            speed: {
                selected: this.preferences.speed,
                options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2],
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
        });
    }

    initializeHls() {
        const hlsSource = this.video.dataset.hlsSrc;

        if (!hlsSource || !this.Hls?.isSupported()) {
            return;
        }

        this.hls = new this.Hls({
            enableWorker: true,
            lowLatencyMode: false,
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
        this.hasStartedPlayback = true;
        this.setStatus('playing', 'playing');
        this.dispatchProgress(false, true, 'play');
        this.startHeartbeat();
    }

    handlePause() {
        this.stopHeartbeat();
        this.persistDevicePreferences();

        if (!this.video.ended) {
            this.flushProgress('pause');
            this.setStatus('paused', 'paused');
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
        }, STABLE_SEEK_DELAY_MS);
    }

    handleTimeUpdate() {
        if (!Number.isFinite(this.video.currentTime)) {
            return;
        }

        this.shell?.setAttribute('data-player-position', String(Math.max(0, Math.floor(this.video.currentTime))));
    }

    handleEnded() {
        if (this.completed) {
            return;
        }

        this.completed = true;
        this.stopHeartbeat();
        this.dispatchProgress(true, true, 'ended');
        this.setStatus('ended', 'ended');
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
    }

    handleNativeError() {
        if (this.destroyed || this.hls) {
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
                this.hls?.startLoad();
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

    resolvePreferences() {
        const storageKey = document.body.dataset.accountStorageKey || undefined;
        const device = accountDevicePreferences(storageKey);
        const authenticated = this.video.dataset.accountAuthenticated === '1';
        const accountVersion = Number.parseInt(document.body.dataset.accountSettingsVersion || '1', 10) || 1;
        const deviceMatchesAccount = !authenticated || Number(device.account_version || 0) >= accountVersion;
        const serverRememberVolume = this.video.dataset.accountRememberVolume !== '0';
        const serverVolume = Number.parseInt(this.video.dataset.accountVolume || '70', 10);
        const server = {
            autoplay: this.video.dataset.accountAutoplay === '1',
            rememberVolume: serverRememberVolume,
            volume: Number.isInteger(serverVolume) ? Math.min(100, Math.max(0, serverVolume)) : 70,
            muted: this.video.dataset.accountMuted === '1',
            speed: Number.parseFloat(this.video.dataset.accountSpeed || '1') || 1,
            subtitlesEnabled: this.video.dataset.accountSubtitles === '1',
            keyboardShortcutsEnabled: this.video.dataset.accountKeyboard !== '0',
        };
        const rememberVolume = authenticated
            ? server.rememberVolume
            : (typeof device.remember_volume === 'boolean' ? device.remember_volume : server.rememberVolume);

        return {
            autoplay: authenticated ? server.autoplay : (device.autoplay ?? server.autoplay),
            rememberVolume,
            volume: rememberVolume && deviceMatchesAccount ? (device.volume ?? server.volume) : (rememberVolume ? server.volume : 70),
            muted: rememberVolume && deviceMatchesAccount ? (device.muted ?? server.muted) : (rememberVolume ? server.muted : false),
            speed: authenticated ? server.speed : (Number.parseFloat(device.playback_speed) || server.speed),
            subtitlesEnabled: authenticated ? server.subtitlesEnabled : (device.subtitles_enabled ?? server.subtitlesEnabled),
            keyboardShortcutsEnabled: authenticated
                ? server.keyboardShortcutsEnabled
                : (device.keyboard_shortcuts_enabled ?? server.keyboardShortcutsEnabled),
        };
    }

    scheduleDevicePreferenceWrite() {
        if (!this.preferences.rememberVolume || this.destroyed) {
            return;
        }

        window.clearTimeout(this.preferenceTimer);
        this.preferenceTimer = window.setTimeout(() => {
            this.preferenceTimer = null;
            this.persistDevicePreferences();
        }, DEVICE_PREFERENCE_WRITE_DELAY_MS);
    }

    persistDevicePreferences() {
        if (!this.preferences.rememberVolume || this.destroyed) {
            return;
        }

        persistAccountDevicePreferences({
            remember_volume: true,
            volume: Math.round(this.video.volume * 100),
            muted: this.video.muted,
            playback_speed: Number(this.video.playbackRate || 1).toFixed(2),
        }, document.body.dataset.accountStorageKey || undefined);
    }

    flushProgress(reason = 'flush') {
        this.dispatchProgress(false, true, reason);
    }

    dispatchProgress(completed = false, force = false, reason = 'update') {
        if (!this.canReportProgress()) {
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
        this.video.dispatchEvent(new CustomEvent('catalog-progress', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                playbackSessionToken: this.playbackSessionToken,
                eventSequence: ++this.progressSequence,
                episodeId: Number.parseInt(this.video.dataset.progressEpisode || '', 10),
                positionSeconds,
                durationSeconds,
                completed,
                reason,
            },
        }));
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

    setFailure(expired) {
        this.expired = expired;
        this.stopHeartbeat();
        this.clearRecoveryTimer();
        this.setStatus(
            expired ? 'expired' : 'error',
            expired ? 'expired' : 'playbackError',
            true,
        );
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

        if (this.expired) {
            window.location.reload();

            return;
        }

        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.setStatus('retrying', 'retryingNetwork');

        if (this.hls) {
            this.hls.stopLoad();
            this.hls.startLoad(-1);
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

        if (this.preferenceTimer !== null) {
            window.clearTimeout(this.preferenceTimer);
            this.preferenceTimer = null;
        }

        this.abortController.abort();
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

    const needsHls = videos.some((video) => (
        video.dataset.hlsSrc
        && video.canPlayType('application/vnd.apple.mpegurl') === ''
        && video.canPlayType('application/x-mpegURL') === ''
    ));
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
