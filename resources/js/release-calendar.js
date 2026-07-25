const timers = new Map();
const copyTimers = new Map();
const initializedCalendarActions = new WeakSet();

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

const privateFeedUrl = (value) => {
    try {
        const url = new URL(value, window.location.origin);

        if (
            url.origin !== window.location.origin
            || !['http:', 'https:'].includes(url.protocol)
            || url.search !== ''
            || url.hash !== ''
            || !/^\/calendar\/feed\/[A-Za-z0-9_-]{64}\.ics$/.test(url.pathname)
        ) {
            return null;
        }

        return url.toString();
    } catch {
        return null;
    }
};

const fallbackCopy = (value) => {
    const input = document.createElement('textarea');
    input.value = value;
    input.readOnly = true;
    input.setAttribute('aria-hidden', 'true');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.append(input);
    input.select();

    const copied = document.execCommand('copy');
    input.remove();

    if (!copied) {
        throw new Error('Clipboard copy was rejected.');
    }
};

const copyText = async (value) => {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }

    fallbackCopy(value);
};

const setCopyStatus = (action, success) => {
    const label = action.querySelector('[data-calendar-copy-label]');

    if (!label) {
        return;
    }

    const original = label.dataset.calendarOriginalLabel || label.textContent || '';
    label.dataset.calendarOriginalLabel = original;
    label.textContent = success
        ? action.dataset.copySuccess || original
        : action.dataset.copyError || original;

    const previousTimer = copyTimers.get(action);
    if (previousTimer) window.clearTimeout(previousTimer);

    copyTimers.set(action, window.setTimeout(() => {
        label.textContent = original;
        copyTimers.delete(action);
    }, 5000));
};

const clearCopyStatuses = () => {
    copyTimers.forEach((timer) => window.clearTimeout(timer));
    copyTimers.clear();
};

export const initializeCalendarSubscriptionActions = (root = document) => {
    root.querySelectorAll('[data-calendar-copy], [data-calendar-google]').forEach((action) => {
        if (initializedCalendarActions.has(action)) {
            return;
        }

        initializedCalendarActions.add(action);
        action.addEventListener('click', () => {
            const url = privateFeedUrl(action.dataset.calendarUrl || '');

            if (!url) {
                setCopyStatus(action, false);
                return;
            }

            void copyText(url)
                .then(() => setCopyStatus(action, true))
                .catch(() => setCopyStatus(action, false));
        });
    });
};

export const initializeReleaseCalendarInterfaces = (root = document) => {
    initializeReleaseCountdowns(root);
    initializeCalendarSubscriptionActions(root);
};

document.addEventListener('livewire:navigating', () => {
    clearReleaseCountdowns();
    clearCopyStatuses();
});
