/**
 * On a phone, a table with 5 or more columns turns each row into a card, one "Label  value" line per
 * column (the look is in app.css, under "Tables on a phone"). This writes each cell's column heading
 * into data-label, for the tables Blade draws and the ones React redraws as live data comes in.
 *
 *   class="table-keep"   stays a table on a phone (it fits)
 *   class="table-stack"  turns into cards even with fewer columns, or without the .table class
 */
const STACK_FROM_COLUMNS = 5;
const FIELD = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea';

function headings(table) {
    const row = table.tHead?.rows[0];
    if (!row) return null;
    const names = [];
    for (const th of row.cells) {
        // A heading that is only for screen readers, such as "Actions", isn't printed on the card
        const visible = th.querySelector('.sr-only') ? '' : th.textContent.replace(/\s+/g, ' ').trim();
        for (let i = 0; i < th.colSpan; i++) names.push(visible);
    }
    return names;
}

function label(table) {
    const names = headings(table);
    if (!names) return;
    const stack = !table.classList.contains('table-keep') && (table.classList.contains('table-stack') || names.length >= STACK_FROM_COLUMNS);
    table.classList.toggle('is-stacked', stack);
    if (!stack) return;

    for (const section of [...table.tBodies, table.tFoot].filter(Boolean)) {
        for (const tr of section.rows) {
            let col = 0;
            let first = true; // the first column after any checkbox is the card's title, unless it's a field
            for (const td of tr.cells) {
                const name = td.colSpan > 1 ? '' : names[col] ?? '';
                const kind = td.colSpan > 1 ? 'span'
                    : col === 0 && !name && td.querySelector('input[type=checkbox]') ? 'check'
                    : td.querySelector(FIELD) ? 'field'
                    : !name ? 'actions'
                    : first && section.tagName === 'TBODY' ? 'title'
                    : 'value';
                if (kind !== 'check') first = false;
                if (td.dataset.label !== name) td.dataset.label = name;
                if (td.dataset.cell !== kind) td.dataset.cell = kind;
                col += td.colSpan;
            }
        }
    }
}

const TABLES = 'table.table, table.table-stack';
let queued = false;
function labelAll() {
    queued = false;
    document.querySelectorAll(TABLES).forEach(label);
}
function queue() {
    if (!queued) {
        queued = true;
        requestAnimationFrame(labelAll);
    }
}

export function startTableCards() {
    labelAll();
    // Only new or replaced rows matter; the labels written here are attributes, so they don't come back around
    new MutationObserver(queue).observe(document.body, { childList: true, subtree: true });
}
