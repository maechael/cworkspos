import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { beep, errorMessage, lineTotal, lookupBarcode, parseScan, promoOwed, qtyPrefix, qtyText, round2, scanQty } from '../../lib';
import { useScanner } from '../../useScanner';

export { round2 };

/**
 * Cart, scanning, payment and keyboard shortcuts shared by every cashier style.
 * The styles only differ in how products are found and how the cart is drawn.
 */
export function usePos({ payKey = 'F8', supervisor = false } = {}) {
    const [cart, setCart] = useState([]);
    const [selected, setSelected] = useState(null);
    const [notice, setNotice] = useState(null);
    const [weighing, setWeighing] = useState(null); // product waiting for a kilo amount
    const [paying, setPaying] = useState(false);
    const [done, setDone] = useState(null);
    const [saleNo, setSaleNo] = useState(0); // bumps on each new sale so product lists reload stock
    const [held, setHeld] = useState([]); // sales put on hold while serving the next customer
    const [undo, setUndo] = useState(null); // the lines the last removal took out, while the Undo toast is up
    const [voidRequest, setVoidRequest] = useState(null); // lines waiting for a supervisor's approval
    const scanRef = useRef();
    const noticeTimer = useRef();

    const focusScan = useCallback(() => setTimeout(() => scanRef.current?.focus(), 0), []);

    const flash = (type, text) => {
        setNotice({ type, text });
        clearTimeout(noticeTimer.current);
        noticeTimer.current = setTimeout(() => setNotice(null), 3500);
    };

    const subtotal = useMemo(() => round2(cart.reduce((s, l) => s + lineTotal(l), 0)), [cart]);
    const itemCount = cart.reduce((s, l) => s + (l.allows_decimal ? 1 : l.qty), 0);

    const addToCart = (product, qty) => {
        qty = qty ?? 1;
        if (!product.allows_decimal) qty = Math.max(1, Math.round(qty));
        setCart((c) => {
            const found = c.find((l) => l.id === product.id);
            if (found) return c.map((l) => (l.id === product.id ? { ...l, qty: round2(l.qty + qty) } : l));
            return [...c, { ...product, qty }];
        });
        setSelected(product.id);
        // Adding a removed item again means it is already fixed, and Undo would count it twice
        setUndo((u) => (u?.lines.some(({ line }) => line.id === product.id) ? null : u));
        const inCart = (cart.find((l) => l.id === product.id)?.qty || 0) + qty;
        const owed = promoOwed({ ...product, qty: inCart });
        if (inCart > product.stock) flash('warn', `${product.name}: only ${qtyText(product.stock)} ${product.unit} on record.`);
        else if (owed) flash('promo', `${product.promo.label}: the customer gets ${owed} more ${product.name} free. Scan ${owed === 1 ? 'it' : 'them'} too.`);
        beep(true);
    };

    /** Kilo items with no amount given open the weight dialog first. */
    const handleProduct = (product, qty) => {
        if (product.allows_decimal && qty == null) {
            setWeighing(product);
            return;
        }
        addToCart(product, qty);
        focusScan();
    };

    const onScan = async (code, qty) => {
        try {
            const product = await lookupBarcode(code, { baskets: true });
            const amount = scanQty(product, qty);
            // The quantity jumps on a box barcode, so say why. A low stock warning from addToCart replaces this.
            if (product.scan_pieces > 1) flash('warn', `${product.scan_label ? `${product.scan_label}: ` : ''}added ${qtyText(amount)} ${product.unit} of ${product.name}.`);
            handleProduct(product, amount);
        } catch (err) {
            beep(false);
            flash('error', errorMessage(err));
            focusScan();
        }
    };

    const setQty = (id, qty) => {
        setCart((c) => c.map((l) => (l.id === id ? { ...l, qty: Math.max(0, l.allows_decimal ? round2(qty) : Math.round(qty)) } : l)));
    };
    const step = (line, dir) => setQty(line.id, round2(line.qty + dir * (line.allows_decimal ? 0.25 : 1)));
    const removeLine = (id) => {
        const index = cart.findIndex((l) => l.id === id);
        if (index < 0) return;
        setCart((c) => c.filter((l) => l.id !== id));
        if (selected === id) setSelected(null);
        setUndo({ id: Date.now(), text: `Removed ${cart[index].name}`, lines: [{ line: cart[index], index }] });
        focusScan();
    };

    const clearLines = () => {
        setUndo({ id: Date.now(), text: `Cleared ${cart.length} line${cart.length === 1 ? '' : 's'}`, lines: cart.map((line, index) => ({ line, index })) });
        setCart([]);
        setSelected(null);
        focusScan();
    };

    // Taking lines off a sale needs a supervisor's approval. An owner logged in here already is one.
    const remove = (id) => {
        const line = cart.find((l) => l.id === id);
        if (!line) return;
        if (supervisor) removeLine(id);
        else setVoidRequest({ lines: [line] });
    };
    const clearOrder = () => {
        if (!cart.length) return;
        if (supervisor) clearLines();
        else setVoidRequest({ lines: cart, clearAll: true });
    };
    const approveVoid = () => {
        if (voidRequest.clearAll) clearLines();
        else removeLine(voidRequest.lines[0].id);
        setVoidRequest(null);
    };
    const cancelVoid = () => {
        setVoidRequest(null);
        focusScan();
    };

    /** Puts the removed lines back where they were. Anything scanned since then stays in the order. */
    const undoLast = () => {
        if (!undo) return;
        setCart((c) => {
            const next = [...c];
            undo.lines.forEach(({ line, index }) => {
                if (!next.some((l) => l.id === line.id)) next.splice(index, 0, line);
            });
            return next;
        });
        setSelected(undo.lines[undo.lines.length - 1].line.id);
        setUndo(null);
        focusScan();
    };
    const dismissUndo = useCallback(() => setUndo(null), []);

    // At checkout the order is final, so the removed lines can't come back behind the payment screen
    useEffect(() => { if (paying) setUndo(null); }, [paying]);

    const canPay = cart.length > 0 && !cart.some((l) => l.qty <= 0);

    const voidSelected = () => selected && remove(selected);

    const hold = () => {
        if (!cart.length) return;
        setHeld((h) => [...h, { id: Date.now(), cart, at: new Date() }]);
        setCart([]);
        setSelected(null);
        setUndo(null);
        flash('warn', 'Sale put on hold. Bring it back with Resume.');
        focusScan();
    };
    const resume = (id) => {
        const sale = held.find((h) => h.id === id);
        if (!sale) return;
        // A sale in progress goes on hold in its place
        setHeld((h) => [...h.filter((x) => x.id !== id), ...(cart.length ? [{ id: Date.now(), cart, at: new Date() }] : [])]);
        setCart(sale.cart);
        setSelected(null);
        setUndo(null);
        focusScan();
    };

    // Shortcuts: F2 scan, pay key (F8 by default), Delete removes the selected line, Ctrl+Z takes back a removal
    useEffect(() => {
        const onKey = (e) => {
            if (voidRequest) return; // the approval dialog has the keyboard
            if (e.key === 'F2') { e.preventDefault(); scanRef.current?.focus(); }
            if (e.key === payKey && canPay && !done && !paying) { e.preventDefault(); setPaying(true); }
            if (e.key === 'Delete' && selected && document.activeElement === scanRef.current && !scanRef.current.value) remove(selected);
            // A field with something typed in it keeps its own Ctrl+Z
            if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z' && undo && !document.activeElement?.value) { e.preventDefault(); undoLast(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [cart, selected, done, paying, payKey, undo, voidRequest]);

    const newSale = () => {
        setCart([]);
        setDone(null);
        setSelected(null);
        setUndo(null);
        setSaleNo((n) => n + 1);
        focusScan();
    };

    return {
        cart, setCart, selected, setSelected, held, hold, resume, voidSelected, flash, payKey, notice, weighing, setWeighing, paying, setPaying, done, setDone,
        scanRef, focusScan, saleNo, subtotal, itemCount, canPay,
        addToCart, handleProduct, onScan, setQty, step, remove, clearOrder, voidRequest, approveVoid, cancelVoid, undo, undoLast, dismissUndo, newSale,
        busy: !!done || paying || !!weighing || !!voidRequest,
    };
}

/** Every active product, best sellers first. Used by the tile, keypad and list styles. */
export function useCatalog(reload = 0) {
    const [products, setProducts] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        axios.get('/api/products/catalog')
            .then(({ data }) => setProducts(data))
            .catch((err) => setError(errorMessage(err)));
    }, [reload]);

    const categories = useMemo(() => {
        if (!products) return [];
        return [...new Set(products.map((p) => p.category || 'Others'))].sort();
    }, [products]);

    // Only the first load counts: a reload after each sale keeps showing the list it already has
    return { products, categories, error, loading: !products && !error };
}

/**
 * Search box behaviour shared by the styles: type a name to see matches (arrow keys + Enter),
 * or scan / type a barcode and press Enter. "3*code" adds 3.
 */
export function useLookup(pos, products, limit = 8) {
    const [value, setValue] = useState('');
    const [active, setActive] = useState(0);
    const { qty, code } = parseScan(value);

    const matches = useMemo(() => {
        if (!products || code.length < 2) return [];
        const term = code.toLowerCase();
        const digits = /^\d+$/.test(term);
        return products
            .filter((p) => (digits ? (p.barcode || '').startsWith(term) : p.name.toLowerCase().includes(term) || (p.barcode || '').toLowerCase().startsWith(term)))
            .slice(0, limit);
    }, [products, code, limit]);

    useEffect(() => setActive(0), [code]);

    const pick = (p) => {
        setValue('');
        pos.handleProduct(p, qty);
    };

    const onKeyDown = (e) => {
        if (matches.length && e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, matches.length - 1)); return; }
        if (matches.length && e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); return; }
        if (e.key === 'Escape') { setValue(''); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!code) return;
        const exact = products?.find((p) => p.barcode === code);
        if (exact) return pick(exact);
        if (matches.length && !/^\d+$/.test(code)) return pick(matches[active]);
        setValue('');
        pos.onScan(code, qty); // the server knows barcodes the list may not have loaded
    };

    // A scan arrives here wherever the cursor is
    useScanner((scanned, { field, before }) => {
        const box = pos.scanRef.current;
        if (box?.disabled) {
            beep(false);
            pos.flash('warn', 'Scan not added. Select a table first.');
            return;
        }
        const scanQty = field === box ? qtyPrefix(before) : null;
        setValue('');
        const exact = products?.find((p) => p.barcode === scanned);
        if (exact) pos.handleProduct(exact, scanQty);
        else pos.onScan(scanned, scanQty);
    }, { enabled: !pos.isPreview });

    const inputProps = {
        ref: pos.scanRef, value, onKeyDown, disabled: pos.busy, autoFocus: true, autoComplete: 'off', spellCheck: false,
        onChange: (e) => setValue(e.target.value),
    };

    return { value, setValue, matches, active, setActive, pick, inputProps, qty };
}
