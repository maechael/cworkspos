import React, { useEffect, useState } from 'react';
import axios from 'axios';
import AttachBarcode from './AttachBarcode';
import ScanBox from './ScanBox';
import { beep, errorMessage, lookupBarcode, peso, qtyText, scanQty } from '../lib';

const DRAFT_KEY = 'batch-add-draft';

function loadDraft() {
    try {
        return JSON.parse(localStorage.getItem(DRAFT_KEY)) || [];
    } catch {
        return [];
    }
}

function saveDraft(lines) {
    try {
        if (lines.length) localStorage.setItem(DRAFT_KEY, JSON.stringify(lines));
        else localStorage.removeItem(DRAFT_KEY);
    } catch {
        // The draft is only a convenience
    }
}

const newKey = () => `${Date.now()}-${Math.random()}`;
const addQty = (line, qty) => +(Number(line.quantity) + (qty ?? 1)).toFixed(3);
const isBlank = (v) => v === '' || v === null || v === undefined;
const isIncomplete = (l) => !(Number(l.quantity) > 0) || isBlank(l.unit_cost) || isBlank(l.selling_price) || (!l.product && (!l.name.trim() || !l.unit_id)) || (l.is_box && !(Number(l.box_pieces) > 0));
// A new product marked as a box counts its boxes in Qty, and stock gets every piece inside
const stockQty = (l) => (Number(l.quantity) || 0) * (l.is_box ? Number(l.box_pieces) || 0 : 1);

/**
 * Scan items in any order. Scanning an item again adds 1 to its quantity, and a barcode that isn't
 * in the system becomes a new product to fill in. Saving adds everything to stock as one delivery.
 */
export default function BatchAdd({ categories, units }) {
    const [lines, setLines] = useState(loadDraft);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [attaching, setAttaching] = useState(null); // a new line that may really be an existing product

    useEffect(() => saveDraft(lines), [lines]);

    const defaultUnit = units.find((u) => u.name.toLowerCase() === 'piece') || units[0];

    // The newest scan moves to the top, so its quantity is always in view
    const bump = (ls, found, qty) => [{ ...found, quantity: addQty(found, qty) }, ...ls.filter((l) => l.key !== found.key)];

    const addExisting = (product, qty) => {
        setError(null);
        beep(true);
        setLines((ls) => {
            const found = ls.find((l) => l.product?.id === product.id);
            if (found) return bump(ls, found, qty);
            return [{ key: newKey(), product, quantity: qty ?? 1, unit_cost: product.cost, selling_price: product.price, expiry_date: '' }, ...ls];
        });
    };

    const addNew = (barcode, name, qty) => {
        setError(null);
        beep(false); // the low tone means "new item, needs details"
        setLines((ls) => {
            const found = barcode && ls.find((l) => !l.product && l.barcode === barcode);
            if (found) return bump(ls, found, qty);
            // New items in a batch are often alike, so start from the previous one's category and unit
            const previous = ls.find((l) => !l.product);
            return [{
                key: newKey(), product: null, barcode, name,
                category_id: previous?.category_id ?? '',
                unit_id: previous?.unit_id ?? defaultUnit?.id ?? '',
                quantity: qty ?? 1, unit_cost: '', selling_price: '', expiry_date: '',
            }, ...ls];
        });
    };

    const onScan = async (code, qty, typedName) => {
        try {
            const product = await lookupBarcode(code);
            addExisting(product, scanQty(product, qty));
        } catch (err) {
            if (err?.response?.status !== 404) {
                beep(false);
                setError(errorMessage(err));
                return;
            }
            // A typed name with no match starts a new product, and the system makes its barcode
            if (typedName) addNew('', code, qty);
            else addNew(code, '', qty);
        }
    };

    // The new line's scans so far now count for the product it turned out to be
    const attached = (line, product) => {
        setAttaching(null);
        setLines((ls) => ls.filter((l) => l.key !== line.key));
        addExisting(product, scanQty(product, Number(line.quantity) || 1));
    };

    const update = (key, field, value) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, [field]: value } : l)));
    const remove = (key) => setLines((ls) => ls.filter((l) => l.key !== key));
    const clearAll = () => {
        if (confirm('Remove all items from this batch? Nothing has been saved yet.')) setLines([]);
    };

    const newCount = lines.filter((l) => !l.product).length;
    const incomplete = lines.filter(isIncomplete).length;
    const totalCost = lines.reduce((s, l) => s + stockQty(l) * (Number(l.unit_cost) || 0), 0);

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post('/products/batch', {
                items: lines.map((l) => ({
                    ...(l.product
                        ? { product_id: l.product.id }
                        : {
                            barcode: l.barcode || null, name: l.name.trim(), category_id: l.category_id || null, unit_id: l.unit_id,
                            box_pieces: l.is_box ? l.box_pieces : null, piece_barcode: (l.is_box && l.piece_barcode?.trim()) || null,
                        }),
                    quantity: stockQty(l),
                    unit_cost: l.unit_cost,
                    selling_price: l.selling_price,
                    expiry_date: l.expiry_date || null,
                })),
            });
            saveDraft([]);
            window.location = data.url;
        } catch (err) {
            setError(errorMessage(err));
            setSaving(false);
        }
    };

    const unitOf = (l) => (l.product ? null : units.find((u) => String(u.id) === String(l.unit_id)));
    const qtyStep = (l) => ((l.product ? l.product.allows_decimal : unitOf(l)?.allows_decimal) ? '0.01' : '1');

    return (
        <div className="space-y-4">
            <ScanBox onScan={onScan} onPick={addExisting} placeholder="Scan an item. Scan it again to add 1 more…" />
            {error && <div className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            {attaching && <AttachBarcode code={attaching.barcode} onDone={(product) => attached(attaching, product)} onCancel={() => setAttaching(null)} />}

            <div className="card overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th className="min-w-64">Product</th>
                            <th className="w-40">Category</th>
                            <th className="w-28">Sold by</th>
                            <th className="w-24">Qty</th>
                            <th className="w-28">Cost</th>
                            <th className="w-28">Price</th>
                            <th className="w-40">Expiry date</th>
                            <th className="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.length === 0 && (
                            <tr><td colSpan="8" className="py-10 text-center text-slate-400">No items yet. Scan the first item.</td></tr>
                        )}
                        {lines.map((l, i) => (
                            <tr key={l.key} className={i === 0 ? 'bg-brand-50' : ''}>
                                <td>
                                    {l.product ? (
                                        <>
                                            <div className="font-medium">{l.product.name}</div>
                                            <div className="text-xs text-slate-400">{l.product.barcode} · {qtyText(l.product.stock)} {l.product.unit} in stock</div>
                                        </>
                                    ) : (
                                        <>
                                            <input className="input py-1" placeholder="Product name" aria-label="Product name" value={l.name} onChange={(e) => update(l.key, 'name', e.target.value)} />
                                            <div className="mt-1 text-xs text-amber-700">New product · {l.barcode || 'barcode made on save'}</div>
                                            {l.barcode && <button type="button" className="mt-0.5 block text-xs font-medium text-brand-700 underline" onClick={() => setAttaching(l)}>Add this barcode to an existing product</button>}
                                            {l.barcode && (
                                                <label className="mt-1.5 flex items-center gap-2 text-xs font-medium">
                                                    <input type="checkbox" checked={!!l.is_box} onChange={(e) => update(l.key, 'is_box', e.target.checked)} /> This barcode is on a box
                                                </label>
                                            )}
                                            {l.is_box && (
                                                <div className="mt-1.5 space-y-1.5">
                                                    <input type="number" min="0" step="any" className="input py-1" aria-label="Pieces inside each box" placeholder="Pieces inside each box" value={l.box_pieces ?? ''} onChange={(e) => update(l.key, 'box_pieces', e.target.value)} />
                                                    <input data-scan-keep className="input py-1 font-mono" aria-label="Barcode on one piece" placeholder="Scan one piece here, if it has a barcode" autoComplete="off" value={l.piece_barcode ?? ''} onChange={(e) => update(l.key, 'piece_barcode', e.target.value)} />
                                                    {Number(l.box_pieces) > 0 && <div className="text-xs text-slate-500">{qtyText(Number(l.quantity) || 0)} box × {qtyText(Number(l.box_pieces))} = <b>{qtyText(stockQty(l))} pieces</b> into stock. Cost and price are per piece.</div>}
                                                </div>
                                            )}
                                        </>
                                    )}
                                </td>
                                <td>
                                    {l.product ? <span className="text-sm text-slate-500">{l.product.category || '—'}</span> : (
                                        <select className="input py-1" aria-label="Category" value={l.category_id} onChange={(e) => update(l.key, 'category_id', e.target.value)}>
                                            <option value="">— None —</option>
                                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                        </select>
                                    )}
                                </td>
                                <td>
                                    {l.product ? <span className="text-sm text-slate-500">{l.product.unit}</span> : (
                                        <select className="input py-1" aria-label="Sold by" value={l.unit_id} onChange={(e) => update(l.key, 'unit_id', e.target.value)}>
                                            {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                        </select>
                                    )}
                                </td>
                                <td><input type="number" min="0" step={qtyStep(l)} className="input py-1 font-semibold" aria-label="Quantity" value={l.quantity} onChange={(e) => update(l.key, 'quantity', e.target.value)} /></td>
                                <td><input type="number" min="0" step="0.01" className="input py-1" aria-label="Cost" placeholder="0.00" value={l.unit_cost} onChange={(e) => update(l.key, 'unit_cost', e.target.value)} /></td>
                                <td><input type="number" min="0" step="0.01" className="input py-1" aria-label="Price" placeholder="0.00" value={l.selling_price} onChange={(e) => update(l.key, 'selling_price', e.target.value)} /></td>
                                <td><input type="date" className="input py-1" aria-label="Expiry date" value={l.expiry_date} onChange={(e) => update(l.key, 'expiry_date', e.target.value)} /></td>
                                <td><button type="button" className="text-slate-400 hover:text-red-600" aria-label="Remove" onClick={() => remove(l.key)}>✕</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-4">
                {lines.length > 0 && <button type="button" className="mr-auto text-sm text-slate-500 hover:text-red-600" onClick={clearAll}>Clear batch</button>}
                {incomplete > 0 && <p className="text-sm text-amber-700">{incomplete} item{incomplete === 1 ? '' : 's'} still need{incomplete === 1 ? 's' : ''} a name, cost, price or quantity.</p>}
                <div className="text-lg">Total cost: <b>{peso(totalCost)}</b></div>
                <button type="button" className="btn btn-primary px-6 py-3" disabled={!lines.length || incomplete > 0 || saving} onClick={save}>
                    {saving ? 'Saving…' : `Save ${lines.length} item${lines.length === 1 ? '' : 's'}${newCount ? ` (${newCount} new)` : ''}`}
                </button>
            </div>
        </div>
    );
}
