import '@fortawesome/fontawesome-free/css/all.min.css';
import Hls from 'hls.js';
import Plyr from 'plyr';
import 'plyr/dist/plyr.css';

const initializeSeasonvarPlayers = () => {
    document.querySelectorAll('video.js-seasonvar-player:not([data-player-ready])').forEach((video) => {
        video.dataset.playerReady = '1';

        const hlsSource = video.dataset.hlsSrc;

        if (hlsSource && Hls.isSupported()) {
            const hls = new Hls({
                enableWorker: true,
                lowLatencyMode: false,
            });

            hls.loadSource(hlsSource);
            hls.attachMedia(video);
            video._seasonvarHls = hls;
        }

        new Plyr(video, {
            controls: [
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
            ],
            i18n: {
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
            },
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSeasonvarPlayers);
} else {
    initializeSeasonvarPlayers();
}
