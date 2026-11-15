import axios from 'axios';
import { baseUrl, peso, qtyText } from './lib';

/**
 * Suggestions under the top bar's product search: type part of a name or barcode and matching products
 * drop down. Arrow keys move, Enter opens the highlighted product (or, with none picked, the full product list),
 * Escape closes. Works on every <input data-suggest> inside a search form.
 */
export function startSearchSuggest() {
    document.querySelectorAll('input[data-suggest]').forEach(attach);
}

let uid = 0;
const escape = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** The typed text in bold where it appears. */
function mark(text, term) {
    const safe = escape(text);
    if (!term) return safe;
    const at = String(text).toLowerCase().indexOf(term.toLowerCase());
    if (at < 0) return safe;
    return escape(text.slice(0, at)) + '<b>' + escape(text.slice(at, at + term.length)) + '</b>' + escape(text.slice(at + term.length));
}

function attach(input) {
    const form = input.form;
    const id = `search-suggest-${++uid}`;
    const list = document.createElement('div');
    list.id = id;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Matching products');
    list.className = 'search-suggest';
    list.hidden = true;
    form.appendChild(list);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', id);
    input.setAttribute('aria-expanded', 'false');

    let items = [];
    let active = -1;
    let timer;
    let controller;
    let term = '';

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const highlight = (i) => {
        active = i;
        list.querySelectorAll('[role=option]').forEach((el, n) => el.setAttribute('aria-selected', n === i ? 'true' : 'false'));
        if (i >= 0) {
            input.setAttribute('aria-activedescendant', `${id}-${i}`);
            list.querySelector(`#${id}-${i}`)?.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const render = (loading = false) => {
        if (loading && !items.length) {
            list.innerHTML = '<p class="search-suggest-note">Searching…</p>';
        } else if (!items.length) {
            list.innerHTML = `<p class="search-suggest-note">No product matches “${escape(term)}”.</p>`;
        } else {
            list.innerHTML = items.map((p, i) => {
                const out = p.stock <= 0;
                return `<a id="${id}-${i}" role="option" aria-selected="false" href="${baseUrl}/products/${p.id}" class="search-suggest-item">
                    <span class="min-w-0">
                        <span class="search-suggest-name">${mark(p.name, term)}</span>
                        <span class="search-suggest-meta"><span class="font-mono">${mark(p.barcode || 'no barcode', /^\d+$/.test(term) ? term : '')}</span>${p.category ? ` · ${escape(p.category)}` : ''}</span>
                    </span>
                    <span class="search-suggest-side">
                        <span class="font-semibold">${escape(peso(p.price))}</span>
                        <span class="${out ? 'text-red-600' : 'search-suggest-meta'}">${out ? 'Out of stock' : `${escape(qtyText(p.stock))} ${escape(p.unit || '')} left`}</span>
                    </span>
                </a>`;
            }).join('') + `<a href="${baseUrl}/products?q=${encodeURIComponent(term)}" class="search-suggest-all">See all results for “${escape(term)}”</a>`;
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
    };

    const search = async () => {
        term = input.value.trim();
        if (!term) { items = []; close(); return; }
        controller?.abort();
        controller = new AbortController();
        render(true);
        try {
            const { data } = await axios.get('/api/products/search', { params: { q: term }, signal: controller.signal });
            if (term !== input.value.trim()) return; // the box changed while this was loading
            items = data;
            render();
        } catch (err) {
            if (axios.isCancel(err)) return;
            items = [];
            list.innerHTML = '<p class="search-suggest-note">Couldn\'t search right now. Press Enter for the product list.</p>';
        }
    };

    // Wait for typing (or a scan) to pause before asking
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 200); });
    input.addEventListener('focus', () => { if (input.value.trim() && items.length) render(); });

    input.addEventListener('keydown', (e) => {
        if (list.hidden) {
            if (e.key === 'ArrowDown' && input.value.trim()) { e.preventDefault(); search(); }
            return;
        }
        if (e.key === 'ArrowDown') { e.preventDefault(); highlight(items.length ? (active + 1) % items.length : -1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(items.length ? (active <= 0 ? items.length - 1 : active - 1) : -1); }
        else if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'Enter') {
            // A whole barcode that matches exactly one product goes straight to it; otherwise the highlighted one
            const exact = items.filter((p) => p.barcode === input.value.trim());
            const pick = active >= 0 ? items[active] : exact.length === 1 ? exact[0] : null;
            if (pick) { e.preventDefault(); window.location.href = `${baseUrl}/products/${pick.id}`; }
        }
    });

    list.addEventListener('mousemove', (e) => {
        const option = e.target.closest('[role=option]');
        if (option) highlight([...list.querySelectorAll('[role=option]')].indexOf(option));
    });
    // Clicking a suggestion must not blur-close the list before the link opens
    list.addEventListener('mousedown', (e) => e.preventDefault());
    input.addEventListener('blur', () => setTimeout(close, 150));
}
