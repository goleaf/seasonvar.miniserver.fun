import 'plyr/dist/plyr.css';
import plyrIconUrl from '../images/plyr.svg?url';

const PROGRESS_HEARTBEAT_MS = 30_000;
const PROGRESS_HEARTBEAT_MIN_DELTA_SECONDS = 10;
const STABLE_SEEK_DELAY_MS = 750;
const HLS_RETRY_DELAY_MS = 1_000;
const MAX_PROGRESS_DURATION_SECONDS = 86_400;

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

const playerTranslations = {
    restart: 'Сначала',
    rewind: 'Назад {seektime} секунд',
    play: 'Воспроизвести',
    pause: 'Пауза',
    fastForward: 'Вперед {seektime} секунд',
    seek: 'Перемотка',
    played: 'Просмотрено',
    buffered: 'Загружено',
    currentTime: 'Текущее время',
    duration: 'Длительность',
    volume: 'Громкость',
    mute: 'Выключить звук',
    unmute: 'Включить звук',
    enableCaptions: 'Включить субтитры',
    disableCaptions: 'Выключить субтитры',
    enterFullscreen: 'На весь экран',
    exitFullscreen: 'Выйти из полноэкранного режима',
    settings: 'Настройки',
    pip: 'Картинка в картинке',
};

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

    if (statusText) {
        statusText.textContent = 'Плеер не удалось запустить.';
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
        this.shell = video.closest('[data-player-shell]');
        this.status = this.shell?.querySelector('[data-player-status]') || null;
        this.statusIcon = this.shell?.querySelector('[data-player-status-icon]') || null;
        this.statusText = this.shell?.querySelector('[data-player-status-text]') || null;
        this.retryButton = this.shell?.querySelector('[data-player-retry]') || null;
        this.abortController = new AbortController();
        this.hls = null;
        this.plyr = null;
        this.heartbeatTimer = null;
        this.seekTimer = null;
        this.recoveryTimer = null;
        this.lastSavedPosition = Number.parseInt(video.dataset.progressPosition || '', 10) || 0;
        this.resumePosition = this.lastSavedPosition;
        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.expired = false;
        this.completed = false;
        this.destroyed = false;
    }

    initialize() {
        const signal = this.abortController.signal;

        this.video.dataset.playerReady = '1';
        delete this.video.dataset.playerReserved;
        this.setStatus('loading', 'Загружаем видео…');

        this.video.addEventListener('play', () => this.handlePlay(), { signal });
        this.video.addEventListener('pause', () => this.handlePause(), { signal });
        this.video.addEventListener('seeking', () => this.handleSeeking(), { signal });
        this.video.addEventListener('seeked', () => this.handleSeeked(), { signal });
        this.video.addEventListener('timeupdate', () => this.handleTimeUpdate(), { signal });
        this.video.addEventListener('ended', () => this.handleEnded(), { signal });
        this.video.addEventListener('error', () => this.handleNativeError(), { signal });
        this.video.addEventListener('loadstart', () => this.setStatus('loading', 'Загружаем видео…'), { signal });
        this.video.addEventListener('loadedmetadata', () => this.handleLoadedMetadata(), { signal });
        this.video.addEventListener('canplay', () => this.setStatus('ready', 'Видео готово к просмотру.'), { signal });
        this.video.addEventListener('waiting', () => this.setStatus('buffering', 'Видео загружается…'), { signal });
        this.video.addEventListener('stalled', () => this.setStatus('buffering', 'Видео загружается…'), { signal });
        this.video.addEventListener('emptied', () => this.setStatus('loading', 'Загружаем видео…'), { signal });
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange(), { signal });
        window.addEventListener('orientationchange', () => this.handleOrientationChange(), { signal });
        this.retryButton?.addEventListener('click', () => this.retry(), { signal });

        this.initializeHls();
        this.plyr = new this.Plyr(this.video, {
            controls: playerControls,
            i18n: playerTranslations,
            iconUrl: plyrIconUrl,
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
        });
        this.hls.on(this.Hls.Events.ERROR, (_event, data) => this.handleHlsError(data));
        this.hls.loadSource(hlsSource);
        this.hls.attachMedia(this.video);
    }

    handlePlay() {
        this.setStatus('playing', 'Видео воспроизводится.');
        this.startHeartbeat();
    }

    handlePause() {
        this.stopHeartbeat();

        if (!this.video.ended) {
            this.flushProgress('pause');
            this.setStatus('paused', 'Воспроизведение приостановлено.');
        }
    }

    handleSeeking() {
        this.clearSeekTimer();
        this.setStatus('buffering', 'Переходим к выбранному моменту…');
    }

    handleSeeked() {
        this.clearSeekTimer();
        this.seekTimer = window.setTimeout(() => {
            this.seekTimer = null;
            this.flushProgress('seeked');
            this.setStatus(this.video.paused ? 'paused' : 'playing', this.video.paused
                ? 'Воспроизведение приостановлено.'
                : 'Видео воспроизводится.');
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
        this.setStatus('ended', 'Серия просмотрена.');
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

        this.setStatus('ready', 'Видео готово к просмотру.');
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
            this.setStatus('retrying', 'Повторяем загрузку видео…');
            this.recoveryTimer = window.setTimeout(() => {
                this.recoveryTimer = null;
                this.hls?.startLoad();
            }, HLS_RETRY_DELAY_MS);

            return;
        }

        if (data.type === this.Hls.ErrorTypes.MEDIA_ERROR && this.mediaRecoveries < 1) {
            this.mediaRecoveries += 1;
            this.setStatus('retrying', 'Восстанавливаем воспроизведение…');
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

    flushProgress(reason = 'flush') {
        this.dispatchProgress(false, true, reason);
    }

    dispatchProgress(completed = false, force = false, reason = 'update') {
        if (!this.canReportProgress()) {
            return;
        }

        if (
            !Number.isFinite(this.video.duration)
            || this.video.duration < 1
            || this.video.duration > MAX_PROGRESS_DURATION_SECONDS
        ) {
            return;
        }

        const positionSeconds = Math.max(0, Math.floor(completed ? this.video.duration : this.video.currentTime));
        const progressDelta = Math.abs(positionSeconds - this.lastSavedPosition);

        if (progressDelta === 0) {
            return;
        }

        if (!force && !completed && progressDelta < PROGRESS_HEARTBEAT_MIN_DELTA_SECONDS) {
            return;
        }

        this.lastSavedPosition = positionSeconds;
        this.video.dispatchEvent(new CustomEvent('catalog-progress', {
            bubbles: true,
            detail: {
                sessionKey: this.sessionKey,
                episodeId: Number.parseInt(this.video.dataset.progressEpisode || '', 10),
                positionSeconds,
                durationSeconds: Math.floor(this.video.duration),
                completed,
                reason,
            },
        }));
    }

    canReportProgress() {
        if (this.destroyed || this.video.dataset.progressEnabled !== '1') {
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
        this.setStatus(
            expired ? 'expired' : 'error',
            expired ? 'Ссылка на просмотр устарела.' : 'Не удалось воспроизвести видео.',
            true,
        );
    }

    setStatus(state, text, canRetry = false) {
        if (this.destroyed || !this.status) {
            return;
        }

        this.status.dataset.playerState = state;
        this.shell?.setAttribute('data-player-state', state);
        this.status.hidden = false;

        if (this.statusText) {
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

        if (this.expired) {
            window.location.reload();

            return;
        }

        this.networkRetries = 0;
        this.mediaRecoveries = 0;
        this.setStatus('retrying', 'Повторяем загрузку видео…');

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

        this.destroyed = true;
        this.stopHeartbeat();
        this.clearSeekTimer();

        if (this.recoveryTimer !== null) {
            window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
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
