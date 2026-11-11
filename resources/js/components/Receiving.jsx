import React, { useRef, useState } from 'react';
import axios from 'axios';
import AttachBarcode from './AttachBarcode';
import ScanBox from './ScanBox';
import { baseUrl, beep, errorMessage, lookupBarcode, peso, scanQty } from '../lib';

let lineKey = 0;
const today = () => new Date().toLocaleDateString('en-CA');

export default function Receiving({ suppliers }) {
    const [header, setHeader] = useState({ supplier_id: '', reference_no: '', received_at: today(), notes: '' });
    const [lines, setLines] = useState([]);
    const [missing, setMissing] = useState(null); // { code, qty } of a scan that isn't on file
    const [attaching, setAttaching] = useState(false);
    const [boxNote, setBoxNote] = useState(null);
    const [askExpiry, setAskExpiry] = useState(false); // the reminder about blank expiry dates is showing
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const scanRef = useRef();

    const add = (product, qty) => {
        setMissing(null);
        setBoxNote(product.scan_pieces > 1 ? `${product.scan_label || 'Box'}: added ${qty} ${product.unit} of ${product.name}. Unit cost is per ${product.unit}.` : null);
        beep(true);
        setLines((ls) => {
            // Scanning the same item again adds to its newest line
            const last = [...ls].reverse().find((l) => l.product.id === product.id);
            const box = product.scan_pieces > 1 ? product.scan_pieces : null;
            if (last) return ls.map((l) => (l.key === last.key ? { ...l, box: box ?? l.box, quantity: +(Number(l.quantity) + (qty ?? 1)).toFixed(3) } : l));
            return [...ls, { key: ++lineKey, product, box, quantity: qty ?? 1, unit_cost: product.cost, expiry_date: '', selling_price: '' }];
        });
    };

    const onScan = async (code, qty) => {
        try {
            const product = await lookupBarcode(code);
            add(product, scanQty(product, qty));
        } catch (err) {
            beep(false);
            setMissing({ code, qty });
        }
    };

    const attached = (product) => {
        setAttaching(false);
        add(product, scanQty(product, missing.qty));
    };

    const update = (key, field, value) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, [field]: value } : l)));
    // Same item, another expiry date. One box moves down to its own line, or 1 piece if no box was scanned,
    // so the delivery's total stays the same. A line of just 1 adds another below it to fill in.
    const splitLot = (line) =>
        setLines((ls) => {
            const i = ls.findIndex((l) => l.key === line.key);
            const have = Number(line.quantity) || 0;
            const move = line.box && have > line.box ? line.box : 1;
            const copy = { ...line, key: ++lineKey, quantity: move, expiry_date: '' };
            const kept = have > move ? { ...line, quantity: +(have - move).toFixed(3) } : line;
            return [...ls.slice(0, i), kept, copy, ...ls.slice(i + 1)];
        });

    const total = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);
    const noExpiry = lines.filter((l) => !l.expiry_date).length;

    // Not everything expires, so a blank date is allowed, but it should be on purpose
    const receive = () => {
        if (noExpiry === 0 || askExpiry) {
            save();
            return;
        }
        setAskExpiry(true);
        document.querySelector('tbody input[type=date][data-blank]')?.focus();
    };

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post('/deliveries', {
                ...header,
                supplier_id: header.supplier_id || null,
                items: lines.map((l) => ({
                    product_id: l.product.id,
                    quantity: l.quantity,
                    unit_cost: l.unit_cost,
                    expiry_date: l.expiry_date || null,
                    selling_price: l.selling_price === '' ? null : l.selling_price,
                })),
            });
            window.location = data.url;
        } catch (err) {
            setError(errorMessage(err));
            setSaving(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label className="label">Supplier</label>
                    <select className="input" value={header.supplier_id} onChange={(e) => setHeader({ ...header, supplier_id: e.target.value })}>
                        <option value="">— None —</option>
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label">Invoice / DR no.</label>
                    <input className="input" data-scan-keep value={header.reference_no} onChange={(e) => setHeader({ ...header, reference_no: e.target.value })} />
                </div>
                <div>
                    <label className="label">Date received</label>
                    <input type="date" className="input" value={header.received_at} onChange={(e) => setHeader({ ...header, received_at: e.target.value })} />
                </div>
                <div>
                    <label className="label">Notes</label>
                    <input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} />
                </div>
            </div>

            <ScanBox ref={scanRef} onScan={onScan} onPick={add} placeholder="Scan each item as you unpack the delivery…" />
            {missing && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span>Barcode <b>{missing.code}</b> is not in the product list yet.</span>
                    <span className="flex flex-wrap gap-2">
                        <button type="button" className="btn btn-secondary py-1" onClick={() => setAttaching(true)}>Add it to an existing product</button>
                        <a className="btn btn-secondary py-1" href={`${baseUrl}/products/create?barcode=${encodeURIComponent(missing.code)}`} target="_blank" rel="noreferrer">Add it as a new product</a>
                    </span>
                </div>
            )}
            {boxNote && <div role="status" className="rounded-lg bg-brand-50 px-4 py-3 text-sm font-medium text-brand-700">{boxNote}</div>}
            {attaching && missing && <AttachBarcode code={missing.code} onDone={attached} onCancel={() => setAttaching(false)} />}

            <div className="card overflow-x-auto">
                <table className="table min-w-[860px]">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th className="w-28">Qty</th>
                            <th className="w-32">Unit cost</th>
                            <th className="w-44">Expiry date</th>
                            <th className="w-32">New price</th>
                            <th className="text-right">Line cost</th>
                            <th className="w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.length === 0 && (
                            <tr><td colSpan="7" className="py-10 text-center text-slate-400">No items yet. Scan the first item from the delivery.</td></tr>
                        )}
                        {lines.map((l) => (
                            <tr key={l.key}>
                                <td>
                                    <div className="font-medium">{l.product.name}</div>
                                    <div className="text-xs text-slate-400">{l.product.barcode} · selling {peso(l.product.price)} / {l.product.unit}</div>
                                </td>
                                <td><input type="number" min="0" step={l.product.allows_decimal ? '0.01' : '1'} className="input py-1" value={l.quantity} onChange={(e) => update(l.key, 'quantity', e.target.value)} /></td>
                                <td><input type="number" min="0" step="0.01" className="input py-1" value={l.unit_cost} onChange={(e) => update(l.key, 'unit_cost', e.target.value)} /></td>
                                <td>
                                    <input type="date" aria-label={`Expiry date of ${l.product.name}`} data-blank={l.expiry_date ? undefined : ''} className={`input py-1 ${l.expiry_date ? '' : 'border-amber-500'}`} value={l.expiry_date} onChange={(e) => update(l.key, 'expiry_date', e.target.value)} />
                                    {!l.expiry_date && <div className="mt-0.5 text-xs font-medium text-amber-700">No date yet</div>}
                                </td>
                                <td><input type="number" min="0" step="0.01" className="input py-1" placeholder="keep" value={l.selling_price} onChange={(e) => update(l.key, 'selling_price', e.target.value)} /></td>
                                <td className="text-right font-medium">{peso((Number(l.quantity) || 0) * (Number(l.unit_cost) || 0))}</td>
                                <td className="whitespace-nowrap text-right">
                                    <button type="button" className="text-xs font-medium text-brand-700 hover:underline" title="Some of this item expire on a different date, such as another box" onClick={() => splitLot(l)}>+ another date</button>
                                    <button type="button" className="ml-3 text-slate-400 hover:text-red-600" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>✕</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {askExpiry && noExpiry > 0 && (
                <div role="alert" className="rounded-lg bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                    {noExpiry} item{noExpiry === 1 ? ' has' : 's have'} no expiry date. Add {noExpiry === 1 ? 'it' : 'them'} above, or press the button again if {noExpiry === 1 ? 'this item doesn\'t' : 'these items don\'t'} expire.
                </div>
            )}
            <div className="flex flex-wrap items-center justify-end gap-4">
                {error && <p className="text-sm text-red-600">{error}</p>}
                <div className="text-lg">Delivery total: <b>{peso(total)}</b></div>
                <button type="button" className="btn btn-primary px-6 py-3" disabled={!lines.length || saving} onClick={receive}>
                    {saving ? 'Saving…' : askExpiry && noExpiry > 0 ? 'Receive without dates' : `Receive ${lines.length} item${lines.length === 1 ? '' : 's'}`}
                </button>
            </div>
        </div>
    );
}
