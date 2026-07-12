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

        new Plyr(video, {
            controls: playerControls,
            i18n: playerTranslations,
            iconUrl: plyrIconUrl,
        });
    });
};
