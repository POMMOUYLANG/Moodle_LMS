const ROOT_SELECTOR = '[data-region="local-rtcsync-guide"]';
const OPEN_CLASS = 'local-rtcsync-guide-open';

const setOpen = (root, open, returnFocus = true) => {
    const panel = root.querySelector('[data-region="guide-panel"]');
    const openButton = root.querySelector('[data-action="open-guide"]');
    const closeButton = root.querySelector('[data-action="close-guide"]');

    if (!panel || !openButton || !closeButton) {
        return;
    }

    panel.hidden = !open;
    panel.setAttribute('aria-modal', String(open && window.matchMedia('(max-width: 767.98px)').matches));
    openButton.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle(OPEN_CLASS, open);

    if (open) {
        closeButton.focus();
    } else if (returnFocus) {
        openButton.focus();
    }
};

export const init = () => {
    const root = document.querySelector(ROOT_SELECTOR);
    if (!root || root.dataset.initialized === 'true') {
        return;
    }

    root.dataset.initialized = 'true';
    root.querySelector('[data-action="open-guide"]')?.addEventListener('click', () => setOpen(root, true));
    root.querySelector('[data-action="close-guide"]')?.addEventListener('click', () => setOpen(root, false));

    document.addEventListener('keydown', (event) => {
        const panel = root.querySelector('[data-region="guide-panel"]');
        if (event.key === 'Escape' && panel && !panel.hidden) {
            setOpen(root, false);
        }
    });
};
