import '@fortawesome/fontawesome-free/css/all.min.css';
import '../css/app.css';

const loadSeasonvarPlayers = async () => {
    if (!document.querySelector('video.js-seasonvar-player:not([data-player-ready])')) {
        return;
    }

    const { initializeSeasonvarPlayers } = await import('./player.js');

    await initializeSeasonvarPlayers();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadSeasonvarPlayers);
} else {
    void loadSeasonvarPlayers();
}
