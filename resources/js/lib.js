import axios from 'axios';

// The app may live in a sub-folder (http://localhost/arnold-bulan-minigrocery), so requests use the base URL
export const baseUrl = (document.querySelector('meta[name=base-url]')?.content || '').replace(/\/$/, '');
axios.defaults.baseURL = baseUrl;

export const peso = (n) =>
    '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const qtyText = (n) => Number(n).toLocaleString('en-PH', { maximumFractionDigits: 3 });

/** `baskets: true` on the cashier screen, the only place baskets are sold. */
export async function lookupBarcode(code, { baskets = false } = {}) {
    const { data } = await axios.get('/api/products/lookup', { params: { barcode: code, ...(baskets ? { baskets: 1 } : {}) } });
    return data;
}

export const round2 = (n) => Math.round(n * 100) / 100;

/** How many on this order line are free under its Buy X Take Y promo. Works the same as Product::freeQty on the server. */
export const freeQty = (line) => (line.promo && !line.allows_decimal ? Math.floor(line.qty / (line.promo.buy + line.promo.take)) * line.promo.take : 0);

/** What the line costs the customer, free items taken off. */
export const lineTotal = (line) => round2((line.qty - freeQty(line)) * line.price);

/** Free items the customer has earned but not picked up yet, such as the second one on Buy 1 Take 1. */
export function promoOwed(line) {
    if (!line.promo || line.allows_decimal) return 0;
    const { buy, take } = line.promo;
    const into = line.qty % (buy + take);
    return into >= buy ? buy + take - into : 0;
}

/**
 * How much a scan adds. A box barcode counts every piece in the box, and "2*" before it means two boxes.
 * Anything else keeps the quantity as it was, including none, so kilo items still ask for their weight.
 */
export const scanQty = (product, qty) => (product.scan_pieces && product.scan_pieces !== 1 ? (qty ?? 1) * product.scan_pieces : qty);

export async function searchProducts(q) {
    const { data } = await axios.get('/api/products/search', { params: { q } });
    return data;
}

export function errorMessage(err) {
    const res = err?.response?.data;
    if (res?.errors) return Object.values(res.errors).flat().join(' ');
    return res?.message || 'Something went wrong. Please try again.';
}

/**
 * Parses what came from the scanner box. Supports "3*4800..." to scan with a quantity.
 */
export function parseScan(raw) {
    const text = raw.trim();
    const m = text.match(/^(\d+(?:\.\d+)?)\s*[*xX]\s*(.+)$/);
    if (m) return { qty: parseFloat(m[1]), code: m[2].trim() };
    return { qty: null, code: text };
}

/** The quantity from a hand-typed "3*" sitting in the box when a scan arrives, or null. */
export function qtyPrefix(text) {
    const m = (text || '').trim().match(/^(\d+(?:\.\d+)?)\s*[*xX]$/);
    return m ? parseFloat(m[1]) : null;
}

let audioCtx;
export function beep(ok = true) {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.frequency.value = ok ? 1200 : 220;
        gain.gain.value = 0.08;
        osc.connect(gain).connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + (ok ? 0.08 : 0.3));
    } catch {
        // Sound is optional
    }
}
