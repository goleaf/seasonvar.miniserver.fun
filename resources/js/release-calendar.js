const timers = new Map();

const formatRemaining = (milliseconds, node) => {
    if (milliseconds <= 0) {
        return node.dataset.releaseCountdownFallback || '';
    }

    const minutes = Math.ceil(milliseconds / 60000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const remainingMinutes = minutes % 60;
    const daysLabel = node.dataset.releaseCountdownDays || '';
    const hoursLabel = node.dataset.releaseCountdownHours || '';
    const minutesLabel = node.dataset.releaseCountdownMinutes || '';

    if (days > 0) {
        return `${days} ${daysLabel} ${hours} ${hoursLabel}`;
    }

    if (hours > 0) {
        return `${hours} ${hoursLabel} ${remainingMinutes} ${minutesLabel}`;
    }

    return `${remainingMinutes} ${minutesLabel}`;
};

const clearReleaseCountdowns = () => {
    timers.forEach((timer) => window.clearInterval(timer));
    timers.clear();
};

export const initializeReleaseCountdowns = (root = document) => {
    clearReleaseCountdowns();

    root.querySelectorAll('[data-release-countdown]').forEach((node) => {
        const target = Date.parse(node.dataset.releaseCountdown || '');

        if (!Number.isFinite(target)) {
            return;
        }

        const update = () => {
            const remaining = target - Date.now();
            const visual = node.querySelector('[aria-hidden="true"]');

            if (visual) {
                visual.textContent = formatRemaining(remaining, node);
            }

            if (remaining <= 0) {
                const timer = timers.get(node);
                if (timer) window.clearInterval(timer);
                timers.delete(node);

                return false;
            }

            return true;
        };

        if (update()) {
            timers.set(node, window.setInterval(update, 60000));
        }
    });
};

document.addEventListener('livewire:navigating', clearReleaseCountdowns);
