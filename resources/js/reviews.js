const STORAGE_PREFIX = 'seasonvar:review-draft:';
const MAX_AGE_MS = 24 * 60 * 60 * 1000;
let focusedReviewHash = null;

const storage = () => {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
};

const fieldValue = (field) => field.type === 'checkbox' ? field.checked : field.value;

const restoreField = (field, value) => {
    if (field.type === 'checkbox') {
        field.checked = Boolean(value);
    } else {
        field.value = typeof value === 'string' ? value : '';
    }

    field.dispatchEvent(new Event(field.type === 'checkbox' ? 'change' : 'input', { bubbles: true }));
};

const bindDraft = (form) => {
    if (form.dataset.reviewDraftBound === 'true') return;

    const target = storage();
    const draftKey = form.dataset.reviewDraftKey;
    if (!target || !draftKey) return;

    const key = `${STORAGE_PREFIX}${draftKey}`;
    const fields = [...form.querySelectorAll('[data-review-draft-field]')];

    try {
        const stored = JSON.parse(target.getItem(key) || 'null');
        if (stored && Number.isFinite(stored.savedAt) && Date.now() - stored.savedAt <= MAX_AGE_MS) {
            fields.forEach((field) => restoreField(field, stored.values?.[field.dataset.reviewDraftField]));
        } else if (stored) {
            target.removeItem(key);
        }
    } catch {
        target.removeItem(key);
    }

    const save = () => {
        const values = Object.fromEntries(fields.map((field) => [field.dataset.reviewDraftField, fieldValue(field)]));
        try {
            target.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
        } catch {
            // Browser storage is optional; the Livewire state still preserves recoverable failures.
        }
    };

    fields.forEach((field) => {
        field.addEventListener('input', save);
        field.addEventListener('change', save);
    });
    form.dataset.reviewDraftBound = 'true';
};

const bindReviewDrafts = () => document.querySelectorAll('[data-review-draft]').forEach(bindDraft);

const focusDirectReview = () => {
    if (!/^#review-\d+$/.test(window.location.hash)) return;
    if (focusedReviewHash === window.location.hash) return;

    const review = document.getElementById(window.location.hash.slice(1));
    if (!review) return;

    focusedReviewHash = window.location.hash;
    window.requestAnimationFrame(() => {
        review.scrollIntoView({
            block: 'center',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        review.focus({ preventScroll: true });
    });
};

export const initializeReviews = () => {
    bindReviewDrafts();
    focusDirectReview();
};

const focusReviewTarget = (event) => {
    const reviewId = Number(event.detail?.reviewId);
    const target = event.detail?.target;
    if (!Number.isInteger(reviewId) || reviewId < 1 || typeof target !== 'string') return;

    const selector = {
        editor: '#review-title-input',
        item: `#review-${reviewId}`,
        report: `#review-report-category-${reviewId}`,
        spoiler: `#review-spoiler-toggle-${reviewId}`,
    }[target];
    if (!selector) return;

    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        document.querySelector(selector)?.focus({ preventScroll: true });
    }));
};

window.addEventListener('hashchange', () => {
    focusedReviewHash = null;
    focusDirectReview();
});
window.addEventListener('review-draft-clear', (event) => {
    const key = event.detail?.key;
    const target = storage();
    if (target && typeof key === 'string' && key !== '') target.removeItem(`${STORAGE_PREFIX}${key}`);
});
window.addEventListener('review-focus', focusReviewTarget);
