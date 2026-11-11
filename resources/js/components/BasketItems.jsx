import React, { useEffect, useRef, useState } from 'react';
import { beep, errorMessage, lookupBarcode, peso, qtyText, searchProducts } from '../lib';
import { useScanner } from '../useScanner';

const round2 = (n) => Math.round(n * 100) / 100;

/**
 * What goes in a basket, and its price. Scan an item anywhere on the page or search by name.
 * Scanning an item that is already in adds 1 more. The rows post with the rest of the form.
 */
export default function BasketItems({ rows: start = [], price: startPrice = '' }) {
    const [rows, setRows] = useState(start);
    const [price, setPrice] = useState(startPrice === '' ? '' : String(startPrice));
    const [query, setQuery] = useState('');
    const [found, setFound] = useState([]);
    const [active, setActive] = useState(0);
    const [note, setNote] = useState(null);
    const lastAdded = useRef(null);
    const list = useRef();

    const add = (product) => {
        setNote(null);
        setQuery('');
        setFound([]);
        beep(true);
        lastAdded.current = product.id;
        setRows((r) => (r.some((row) => row.product.id === product.id)
            ? r.map((row) => (row.product.id === product.id ? { ...row, quantity: Number(row.quantity || 0) + 1 } : row))
            : [...r, { product, quantity: 1 }]));
    };

    useScanner(async (code) => {
        try {
            add(await lookupBarcode(code));
        } catch (err) {
            beep(false);
            setNote(errorMessage(err));
        }
    });

    // Name search, a moment after typing stops
    useEffect(() => {
        const term = query.trim();
        if (term.length < 2) { setFound([]); return undefined; }
        const timer = setTimeout(() => searchProducts(term).then((r) => { setFound(r); setActive(0); }).catch(() => setFound([])), 200);
        return () => clearTimeout(timer);
    }, [query]);

    // The quantity of the item just added is the next thing to check
    useEffect(() => {
        if (lastAdded.current == null) return;
        const field = list.current?.querySelector(`[data-qty="${lastAdded.current}"]`);
        lastAdded.current = null;
        field?.focus();
        field?.select();
    }, [rows]);

    const onSearchKey = (e) => {
        if (e.key === 'ArrowDown' && found.length) { e.preventDefault(); setActive((a) => Math.min(a + 1, found.length - 1)); }
        if (e.key === 'ArrowUp' && found.length) { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
        if (e.key === 'Escape') setQuery('');
        if (e.key === 'Enter') { e.preventDefault(); if (found[active]) add(found[active]); }
    };

    const regular = round2(rows.reduce((s, r) => s + (Number(r.quantity) || 0) * r.product.price, 0));
    const cost = round2(rows.reduce((s, r) => s + (Number(r.quantity) || 0) * r.product.cost, 0));
    const priceValue = parseFloat(price);
    const hasPrice = !Number.isNaN(priceValue);
    const canMake = rows.length ? Math.max(0, Math.min(...rows.map((r) => Math.floor(r.product.stock / (Number(r.quantity) || Infinity))))) : 0;

    return (
        <div className="space-y-4 border-t pt-5" onKeyDown={(e) => e.key === 'Enter' && e.target.tagName === 'INPUT' && e.preventDefault()}>
            <div>
                <div className="text-sm font-semibold">What's inside</div>
                <p className="mt-0.5 text-xs text-slate-500">Scan each item, or search by name. Scanning the same item again adds 1 more.</p>
            </div>

            {rows.length > 0 && (
                <div ref={list} className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="table">
                        <thead>
                            <tr><th>Item</th><th className="text-right">Quantity</th><th className="text-right">Price each</th><th className="text-right">Amount</th><th><span className="sr-only">Remove</span></th></tr>
                        </thead>
                        <tbody>
                            {rows.map((row, i) => (
                                <tr key={row.product.id}>
                                    <td>
                                        <input type="hidden" name={`items[${i}][product_id]`} value={row.product.id} />
                                        <div className="font-medium">{row.product.name}</div>
                                        <div className="text-xs text-slate-400">{qtyText(row.product.stock)} {row.product.unit} in stock</div>
                                    </td>
                                    <td className="text-right whitespace-nowrap">
                                        <span className="inline-flex items-center gap-1">
                                        <input data-qty={row.product.id} type="number" min="0" step={row.product.allows_decimal ? '0.001' : '1'} required name={`items[${i}][quantity]`} value={row.quantity}
                                            aria-label={`Quantity of ${row.product.name}`} className="input w-24 py-1 text-right"
                                            onChange={(e) => setRows((r) => r.map((x, n) => (n === i ? { ...x, quantity: e.target.value } : x)))} />
                                        <span className="text-xs text-slate-500">{row.product.unit}</span>
                                        </span>
                                    </td>
                                    <td className="text-right text-slate-600">{peso(row.product.price)}</td>
                                    <td className="text-right">{peso((Number(row.quantity) || 0) * row.product.price)}</td>
                                    <td className="text-right">
                                        <button type="button" className="btn btn-secondary py-1 text-sm" aria-label={`Remove ${row.product.name}`} onClick={() => setRows((r) => r.filter((_, n) => n !== i))}>Remove</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="relative">
                <label className="label" htmlFor="basket-search">Add an item</label>
                <input id="basket-search" className="input" placeholder="Scan, or type a name" autoComplete="off" value={query}
                    role="combobox" aria-expanded={found.length > 0} aria-controls="basket-search-results"
                    onChange={(e) => setQuery(e.target.value)} onKeyDown={onSearchKey} />
                {found.length > 0 && (
                    <ul id="basket-search-results" role="listbox" className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                        {found.map((p, n) => (
                            <li key={p.id} role="option" aria-selected={n === active}>
                                <button type="button" onClick={() => add(p)} onMouseEnter={() => setActive(n)}
                                    className={`flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm ${n === active ? 'bg-slate-100' : ''}`}>
                                    <span className="min-w-0 truncate font-medium">{p.name}</span>
                                    <span className="shrink-0 text-xs text-slate-500">{peso(p.price)} · {qtyText(p.stock)} {p.unit}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                {note && <p role="status" className="mt-2 text-sm font-medium text-amber-700">{note}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label className="label" htmlFor="basket-price">Basket price (₱)</label>
                    <input id="basket-price" type="number" step="0.01" min="0" name="price" required value={price} onChange={(e) => setPrice(e.target.value)} className="input text-lg font-semibold" />
                    {regular > 0 && (
                        <div className="mt-2 flex flex-wrap gap-2">
                            <button type="button" className="btn btn-secondary py-1 text-sm" onClick={() => setPrice(String(regular))}>Same as separately ({peso(regular)})</button>
                            {[5, 10].map((pct) => (
                                <button key={pct} type="button" className="btn btn-secondary py-1 text-sm" onClick={() => setPrice(String(Math.floor(regular * (1 - pct / 100))))}>{pct}% off</button>
                            ))}
                        </div>
                    )}
                </div>
                <dl className="space-y-1 rounded-xl bg-slate-50 p-4 text-sm">
                    <div className="flex justify-between"><dt className="text-slate-500">Bought separately</dt><dd>{peso(regular)}</dd></div>
                    <div className="flex justify-between"><dt className="text-slate-500">Customer saves</dt><dd className="text-brand-700">{hasPrice && regular > priceValue ? peso(regular - priceValue) : '—'}</dd></div>
                    <div className="flex justify-between"><dt className="text-slate-500">Cost of the items</dt><dd>{peso(cost)}</dd></div>
                    <div className="flex justify-between border-t pt-1 font-semibold"><dt>Profit per basket</dt><dd className={hasPrice && priceValue < cost ? 'text-red-600' : ''}>{hasPrice ? peso(priceValue - cost) : '—'}</dd></div>
                    <div className="flex justify-between"><dt className="text-slate-500">Stock can make</dt><dd>{qtyText(canMake)} basket{canMake === 1 ? '' : 's'}</dd></div>
                </dl>
            </div>
            {hasPrice && rows.length > 0 && priceValue < cost && <p className="text-sm font-medium text-red-600">This price is below what the items cost you, so each basket sold loses {peso(cost - priceValue)}.</p>}
        </div>
    );
}
