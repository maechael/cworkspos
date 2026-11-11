import { useEffect, useRef } from 'react';

/**
 * Listens for a USB/wireless barcode scanner anywhere on the page, wherever the cursor is.
 *
 * A scanner in keyboard mode looks like very fast typing, so a run of quick keys counts as a scan.
 * Whatever those keys typed into the focused field is undone, and the Enter at the end never reaches
 * the page. Without this, a scan on the payment screen could type into the cash amount and complete the sale.
 *
 * Normal typing is too slow to count as a scan and is left alone. A field marked data-scan-keep
 * (such as an invoice number) keeps what is scanned into it.
 */
const MAX_GAP_MS = 50; // slowest gap between keys that still counts as a scanner
const MIN_LENGTH = 4;
const IDLE_MS = 120; // scanners set up without an Enter suffix: the code ends when the keys stop
const TRAILING_ENTER_MS = 200; // scanners that send two line endings, so the extra one can't press Pay

// The newest screen wins, so an open dialog handles scans instead of the page behind it
const listeners = [];
let installed = false;

let buffer = '';
let gaps = [];
let lastKeyAt = -Infinity;
let scannedAt = -Infinity;
let field = null;
let before = '';
let idleTimer;

const top = () => listeners[listeners.length - 1];

const editable = (el) => (el?.matches?.('input:not([type=checkbox]):not([type=radio]), textarea, select') ? el : null);

function restoreField() {
    if (!field?.isConnected || field.value === before) return;
    // Goes through the native setter so React sees the change
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(field), 'value').set.call(field, before);
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function clear() {
    clearTimeout(idleTimer);
    buffer = '';
    gaps = [];
    field = null;
    before = '';
}

function finish(suffix) {
    const code = buffer.trim();
    const context = {
        field,
        before,
        suffix,
        msPerKey: gaps.length ? Math.round(gaps.reduce((a, b) => a + b, 0) / gaps.length) : 0,
    };
    restoreField();
    clear();
    scannedAt = performance.now();
    if (code) top()?.onScan(code, context);
}

function onKeyDown(e) {
    if (!listeners.length || e.target?.closest?.('[data-scan-keep]')) return;
    const now = e.timeStamp;

    if (e.key === 'Enter' || e.key === 'Tab') {
        if (buffer.length >= MIN_LENGTH) {
            e.preventDefault();
            e.stopImmediatePropagation();
            finish(e.key);
        } else if (e.key === 'Enter' && now - scannedAt < TRAILING_ENTER_MS) {
            e.preventDefault();
            e.stopImmediatePropagation();
        } else {
            clear();
        }
        return;
    }

    if (e.ctrlKey || e.altKey || e.metaKey) return clear();
    if (e.key.length !== 1) {
        // Shift is how a scanner sends capital letters
        if (e.key !== 'Shift' && e.key !== 'CapsLock') clear();
        return;
    }

    const gap = now - lastKeyAt;
    lastKeyAt = now;
    if (!buffer || gap > MAX_GAP_MS) {
        // Could be the first key of a scan: remember the state to go back to
        clear();
        field = editable(document.activeElement);
        before = field ? field.value : '';
        top()?.onStart?.();
    } else {
        gaps.push(gap);
    }
    buffer += e.key;

    clearTimeout(idleTimer);
    if (buffer.length >= MIN_LENGTH) idleTimer = setTimeout(() => finish(null), IDLE_MS);
}

/**
 * Calls onScan(code, { field, before, suffix, msPerKey }) for each scan.
 * `field` and `before` are the focused field and its value before the scan.
 * onStart runs when a scan may be starting, for state that isn't held in a field.
 */
export function useScanner(onScan, { onStart, enabled = true } = {}) {
    const entry = useRef({});
    entry.current.onScan = onScan;
    entry.current.onStart = onStart;

    useEffect(() => {
        if (!enabled) return undefined;
        if (!installed) {
            // Capture phase: runs before any field, form or other key listener
            window.addEventListener('keydown', onKeyDown, true);
            installed = true;
        }
        const me = entry.current;
        listeners.push(me);
        return () => listeners.splice(listeners.indexOf(me), 1);
    }, [enabled]);
}
