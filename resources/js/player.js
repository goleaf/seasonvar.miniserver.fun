import 'plyr/dist/plyr.css';
import plyrIconUrl from '../images/plyr.svg?url';

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

const loadHls = async () => {
    const module = await import('hls.js/light');

    return module.default;
};

const loadPlyr = async () => {
    const module = await import('plyr');

    return module.default;
};

const initializeProgressTracking = (video) => {
    const episodeId = Number.parseInt(video.dataset.progressEpisode || '', 10);
    const resumePosition = Number.parseInt(video.dataset.progressPosition || '', 10);

    if (!Number.isInteger(episodeId) || episodeId < 1) {
        return;
    }

    video.addEventListener('loadedmetadata', () => {
        if (
            Number.isInteger(resumePosition)
            && resumePosition > 0
            && Number.isFinite(video.duration)
            && resumePosition < video.duration - 5
            && video.currentTime < 1
        ) {
            video.currentTime = resumePosition;
        }
    }, { once: true });

    if (video.dataset.progressEnabled !== '1') {
        return;
    }

    let lastSavedPosition = resumePosition > 0 ? resumePosition : 0;

    const dispatchProgress = (completed = false) => {
        if (!Number.isFinite(video.duration) || video.duration < 1) {
            return;
        }

        const positionSeconds = Math.max(0, Math.floor(completed ? video.duration : video.currentTime));

        lastSavedPosition = positionSeconds;
        video.dispatchEvent(new CustomEvent('catalog-progress', {
            bubbles: true,
            detail: {
                episodeId,
                positionSeconds,
                durationSeconds: Math.floor(video.duration),
                completed,
            },
        }));
    };

    video.addEventListener('timeupdate', () => {
        if (Math.abs(Math.floor(video.currentTime) - lastSavedPosition) >= 30) {
            dispatchProgress();
        }
    });
    video.addEventListener('pause', () => {
        if (!video.ended && Math.abs(Math.floor(video.currentTime) - lastSavedPosition) > 1) {
            dispatchProgress();
        }
    });
    video.addEventListener('ended', () => dispatchProgress(true));
};

export const initializeCatalogPlayers = async () => {
    const videos = [...document.querySelectorAll('video.js-catalog-player:not([data-player-ready])')];

    if (videos.length === 0) {
        return;
    }

    const needsHlsLibrary = videos.some((video) => (
        video.dataset.hlsSrc
        && video.canPlayType('application/vnd.apple.mpegurl') === ''
        && video.canPlayType('application/x-mpegURL') === ''
    ));
    const [Plyr, Hls] = await Promise.all([
        loadPlyr(),
        needsHlsLibrary ? loadHls() : Promise.resolve(null),
    ]);

    videos.forEach((video) => {
        video.dataset.playerReady = '1';

        const hlsSource = video.dataset.hlsSrc;

        if (hlsSource && Hls?.isSupported()) {
            const hls = new Hls({
                enableWorker: true,
                lowLatencyMode: false,
            });

            hls.loadSource(hlsSource);
            hls.attachMedia(video);
            video._catalogHls = hls;
        }

        initializeProgressTracking(video);
        video._catalogPlyr = new Plyr(video, {
            controls: playerControls,
            i18n: playerTranslations,
            iconUrl: plyrIconUrl,
        });
    });
};
