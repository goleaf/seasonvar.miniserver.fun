const initializedCommentDialogs = new WeakSet();
const initializedDiscussionLocaleLinks = new WeakSet();
const initializedCommentCharacterCounters = new WeakSet();
const focusedComments = new WeakSet();
let commentDialogTrigger = null;

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const discussionQueryKeys = [
    'discussion_scope',
    'discussion_sort',
    'comments_page',
    'thread',
    'comment',
];

const focusCommentElement = (element) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    window.requestAnimationFrame(() => {
        element.focus({ preventScroll: true });
        element.scrollIntoView({
            behavior: reducedMotion.matches ? 'auto' : 'smooth',
            block: 'center',
        });
    });
};

const initializeFocusedComments = (root = document) => {
    root.querySelectorAll?.('[data-comment-focused]').forEach((comment) => {
        if (!(comment instanceof HTMLElement) || focusedComments.has(comment)) {
            return;
        }

        focusedComments.add(comment);
        focusCommentElement(comment);
    });
};

const initializeCommentDialogs = (root = document) => {
    root.querySelectorAll?.('[data-comment-dialog][data-comment-dialog-open]').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement) || initializedCommentDialogs.has(dialog)) {
            return;
        }

        initializedCommentDialogs.add(dialog);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            dialog.querySelector('[data-comment-dialog-close]')?.click();
        });

        if (!dialog.open) {
            dialog.showModal();
        }

        window.requestAnimationFrame(() => {
            dialog.querySelector('select, textarea, input, button')?.focus({ preventScroll: true });
        });
    });
};

const initializeDiscussionLocaleLinks = (root = document) => {
    root.querySelectorAll?.('[data-preserve-discussion-state]').forEach((link) => {
        if (!(link instanceof HTMLAnchorElement) || initializedDiscussionLocaleLinks.has(link)) {
            return;
        }

        initializedDiscussionLocaleLinks.add(link);
        link.addEventListener('click', () => {
            const current = new URL(window.location.href);
            const destination = new URL(link.href, window.location.href);

            discussionQueryKeys.forEach((key) => {
                if (current.searchParams.has(key)) {
                    destination.searchParams.set(key, current.searchParams.get(key) || '');
                }
            });

            if (current.searchParams.has('comment') && current.hash.startsWith('#comment-')) {
                destination.hash = current.hash;
            }

            link.href = destination.toString();
        });
    });
};

const initializeCommentCharacterCounters = (root = document) => {
    root.querySelectorAll?.('[data-comment-character-counter]').forEach((container) => {
        const input = container.querySelector('textarea');
        const output = container.querySelector('[data-comment-character-output]');

        if (!(input instanceof HTMLTextAreaElement) || !(output instanceof HTMLElement)) {
            return;
        }

        const update = () => {
            const template = output.dataset.commentCharacterTemplate || '__COUNT__';
            const length = Array.from(input.value).length;

            output.textContent = template.replace('__COUNT__', String(length));
        };

        if (!initializedCommentCharacterCounters.has(container)) {
            initializedCommentCharacterCounters.add(container);
            input.addEventListener('input', update);
        }

        update();
    });
};

export const initializeCommentInterfaces = (root = document) => {
    initializeFocusedComments(root);
    initializeCommentDialogs(root);
    initializeDiscussionLocaleLinks(root);
    initializeCommentCharacterCounters(root);
};

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const trigger = target?.closest('[data-comment-report-trigger]');

    if (trigger instanceof HTMLElement) {
        commentDialogTrigger = trigger;
    }
});

window.addEventListener('comment-report-closed', () => {
    commentDialogTrigger?.focus({ preventScroll: true });
    commentDialogTrigger = null;
});

window.addEventListener('comment-editor-opened', (event) => {
    const selector = event.detail?.selector;

    if (typeof selector !== 'string') {
        return;
    }

    window.requestAnimationFrame(() => {
        const editor = document.querySelector(selector);
        const field = editor?.querySelector('textarea');

        if (field instanceof HTMLTextAreaElement) {
            field.focus({ preventScroll: false });
            field.setSelectionRange(field.value.length, field.value.length);
        }
    });
});

window.addEventListener('comment-action-completed', (event) => {
    const selector = event.detail?.selector;

    if (typeof selector !== 'string') {
        return;
    }

    window.requestAnimationFrame(() => focusCommentElement(document.querySelector(selector)));
});

window.addEventListener('comment-spoiler-toggled', (event) => {
    const selector = event.detail?.selector;

    if (typeof selector !== 'string') {
        return;
    }

    window.requestAnimationFrame(() => {
        const toggle = document.querySelector(selector)?.querySelector('[data-comment-spoiler-toggle]');

        if (toggle instanceof HTMLButtonElement) {
            toggle.focus({ preventScroll: true });
        }
    });
});
