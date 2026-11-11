import React, { useState } from 'react';
import axios from 'axios';
import ScanBox from './ScanBox';
import { beep, errorMessage, lookupBarcode, qtyText, scanQty } from '../lib';

export default function StockCount() {
    const [lines, setLines] = useState([]);
    const [notes, setNotes] = useState('');
    const [notice, setNotice] = useState(null);
    const [saving, setSaving] = useState(false);

    // Each scan adds one to the count, so the counter can scan every item on the shelf
    const add = (product, qty) => {
        setNotice(null);
        beep(true);
        setLines((ls) => {
            const found = ls.find((l) => l.product.id === product.id);
            if (found) {
                return [{ ...found, counted: +(Number(found.counted) + (qty ?? 1)).toFixed(3) }, ...ls.filter((l) => l !== found)];
            }
            return [{ product, counted: qty ?? (product.allows_decimal ? '' : 1) }, ...ls];
        });
    };

    const onScan = async (code, qty) => {
        try {
            const product = await lookupBarcode(code);
            add(product, scanQty(product, qty));
        } catch (err) {
            beep(false);
            setNotice(errorMessage(err));
        }
    };

    const update = (id, counted) => setLines((ls) => ls.map((l) => (l.product.id === id ? { ...l, counted } : l)));

    const save = async () => {
        if (!confirm('Save this count? Stock on record will be changed to match what you counted.')) return;
        setSaving(true);
        try {
            const { data } = await axios.post('/stock-counts', {
                notes,
                items: lines.map((l) => ({ product_id: l.product.id, counted_qty: Number(l.counted) || 0 })),
            });
            window.location = data.url;
        } catch (err) {
            setNotice(errorMessage(err));
            setSaving(false);
        }
    };

    return (
        <div className="space-y-4">
            <ScanBox onScan={onScan} onPick={add} placeholder="Scan each item on the shelf. A box barcode counts every piece in the box…" />
            {notice && <div className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{notice}</div>}

            <div className="card overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th className="text-right">On record</th>
                            <th className="w-36">Counted</th>
                            <th className="text-right">Difference</th>
                            <th className="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.length === 0 && (
                            <tr><td colSpan="5" className="py-10 text-center text-slate-400">Scan items to start counting. Only the items you scan are adjusted.</td></tr>
                        )}
                        {lines.map((l) => {
                            const diff = (Number(l.counted) || 0) - l.product.stock;
                            return (
                                <tr key={l.product.id}>
                                    <td>
                                        <div className="font-medium">{l.product.name}</div>
                                        <div className="text-xs text-slate-400">{l.product.barcode}</div>
                                    </td>
                                    <td className="text-right text-slate-500">{qtyText(l.product.stock)} {l.product.unit}</td>
                                    <td>
                                        <input type="number" min="0" step={l.product.allows_decimal ? '0.01' : '1'} className="input py-1"
                                            value={l.counted} onChange={(e) => update(l.product.id, e.target.value)} />
                                    </td>
                                    <td className={`text-right font-semibold ${diff < 0 ? 'text-red-600' : diff > 0 ? 'text-amber-600' : 'text-brand-700'}`}>
                                        {diff > 0 ? '+' : ''}{qtyText(diff)}
                                    </td>
                                    <td>
                                        <button type="button" className="text-slate-400 hover:text-red-600" onClick={() => setLines((ls) => ls.filter((x) => x !== l))}>✕</button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-3">
                <input className="input max-w-sm" placeholder="Notes (e.g. Aisle 2 canned goods)" value={notes} onChange={(e) => setNotes(e.target.value)} />
                <button type="button" className="btn btn-primary px-6 py-3" disabled={!lines.length || saving} onClick={save}>
                    {saving ? 'Saving…' : `Save count (${lines.length} item${lines.length === 1 ? '' : 's'})`}
                </button>
            </div>
        </div>
    );
}
