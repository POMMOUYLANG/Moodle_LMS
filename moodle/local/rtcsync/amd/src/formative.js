const ROOT_SELECTOR = '[data-region="formative-settings"]';

const rows = (root) => [...root.querySelectorAll('[data-region="activity-row"]')];
const selectedRows = (root) => rows(root).filter((row) =>
    row.querySelector('[data-action="include-activity"]')?.checked
);

const formatNumber = (value) => {
    const rounded = Math.round(value * 100) / 100;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
};

const updateRow = (row) => {
    const checked = row.querySelector('[data-action="include-activity"]')?.checked ?? false;
    row.classList.toggle('is-included', checked);
    row.querySelectorAll('[data-field]').forEach((field) => {
        field.disabled = !checked;
    });
};

const updateSummary = (root, strings) => {
    const selected = selectedRows(root);
    const total = selected.reduce((sum, row) => {
        const value = Number.parseFloat(row.querySelector('[data-field="weight"]')?.value ?? '0');
        return sum + (Number.isFinite(value) ? value : 0);
    }, 0);
    const summary = root.querySelector('[data-region="selection-summary"]');
    if (summary) {
        summary.textContent = strings.summary
            .replace('{count}', String(selected.length))
            .replace('{weight}', formatNumber(total));
        summary.classList.toggle('is-complete', selected.length > 0 && Math.abs(total - 100) < 0.01);
    }
};

const balanceWeights = (root, strings) => {
    const selected = selectedRows(root);
    if (!selected.length) {
        updateSummary(root, strings);
        return;
    }

    const base = Math.floor((100 / selected.length) * 100) / 100;
    let assigned = 0;
    selected.forEach((row, index) => {
        const weight = index === selected.length - 1 ? 100 - assigned : base;
        const input = row.querySelector('[data-field="weight"]');
        if (input) {
            input.value = formatNumber(weight);
        }
        assigned += weight;
    });
    updateSummary(root, strings);
};

const updateStatus = (root, strings) => {
    const enabled = root.querySelector('[data-action="toggle-sync"]')?.checked ?? false;
    const status = root.querySelector('.local-rtcsync-formative__status');
    const badge = root.querySelector('[data-region="sync-badge"]');
    const help = root.querySelector('[data-region="sync-help"]');
    status?.classList.toggle('is-enabled', enabled);
    status?.classList.toggle('is-disabled', !enabled);
    if (badge) {
        badge.textContent = enabled ? strings.statusOn : strings.statusOff;
    }
    if (help) {
        help.textContent = enabled ? strings.helpOn : strings.helpOff;
    }
};

export const init = (strings) => {
    const root = document.querySelector(ROOT_SELECTOR);
    if (!root || root.dataset.initialized === 'true') {
        return;
    }
    root.dataset.initialized = 'true';

    rows(root).forEach(updateRow);
    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-action="include-activity"]')) {
            updateRow(event.target.closest('[data-region="activity-row"]'));
            balanceWeights(root, strings);
        } else if (event.target.matches('[data-action="toggle-sync"]')) {
            updateStatus(root, strings);
        } else if (event.target.matches('[data-field="weight"]')) {
            updateSummary(root, strings);
        }
    });
    root.querySelector('[data-action="balance-weights"]')?.addEventListener('click', () => balanceWeights(root, strings));
    updateSummary(root, strings);
    updateStatus(root, strings);
};
