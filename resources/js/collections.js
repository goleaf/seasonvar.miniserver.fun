const initializedDialogs = new WeakSet();
const initializedShareButtons = new WeakSet();
let collectionDialogTrigger = null;

const closeControl = (dialog) => dialog.querySelector('[data-collection-dialog-close]');

const initializeDialogs = (root = document) => {
    root.querySelectorAll?.('[data-collection-dialog][data-collection-dialog-open]').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement) || initializedDialogs.has(dialog)) {
            return;
        }

        initializedDialogs.add(dialog);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeControl(dialog)?.click();
        });

        if (!dialog.open) {
            dialog.showModal();
        }

        window.requestAnimationFrame(() => {
            const focusTarget = dialog.querySelector('input:not([type="hidden"]), select, textarea, button');
            focusTarget?.focus({ preventScroll: true });
        });
    });
};

const copyShareUrl = async (url) => {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
        return;
    }

    const input = document.createElement('textarea');
    input.value = url;
    input.setAttribute('readonly', '');
    input.className = 'fixed -left-[9999px] top-0';
    document.body.append(input);
    input.select();
    const copied = document.execCommand('copy');
    input.remove();

    if (!copied) {
        throw new Error('Clipboard write failed');
    }
};

const initializeShareButtons = (root = document) => {
    root.querySelectorAll?.('[data-collection-share]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement) || initializedShareButtons.has(button)) {
            return;
        }

        initializedShareButtons.add(button);
        button.addEventListener('click', async () => {
            const url = button.dataset.shareUrl || window.location.href;
            const title = button.dataset.shareTitle || document.title;
            const status = button.closest('article')?.querySelector('[data-collection-share-status]');

            button.disabled = true;

            try {
                if (navigator.share) {
                    await navigator.share({ title, url });
                } else {
                    await copyShareUrl(url);
                }

                if (status) {
                    status.textContent = button.dataset.shareSuccess || '';
                }
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                if (status) {
                    status.textContent = button.dataset.shareError || '';
                }
            } finally {
                button.disabled = false;
            }
        });
    });
};

export const initializeCollectionInterfaces = (root = document) => {
    initializeDialogs(root);
    initializeShareButtons(root);
};

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const trigger = target?.closest('[data-collection-dialog-trigger]');

    if (trigger instanceof HTMLElement) {
        collectionDialogTrigger = trigger;
    }
});

window.addEventListener('collection-selector-closed', () => {
    collectionDialogTrigger?.focus({ preventScroll: true });
    collectionDialogTrigger = null;
});
