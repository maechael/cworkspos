import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Modal } from './pos/Dialogs';
import { beep, errorMessage, lookupBarcode, peso, qtyText, searchProducts } from '../lib';
import { useScanner } from '../useScanner';

/**
 * A scanned code isn't on file, but the item is, such as a new box of something already stocked.
 * Scan a barcode the product already has, or search its name, and the new code joins that product.
 */
export default function AttachBarcode({ code, onDone, onCancel }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [product, setProduct] = useState(null);
    const [pieces, setPieces] = useState('1');
    const [label, setLabel] = useState('');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const piecesField = useRef();

    useEffect(() => {
        if (product || query.trim().length < 2) {
            setResults([]);
            return undefined;
        }
        let stale = false;
        const timer = setTimeout(async () => {
            try {
                const found = await searchProducts(query.trim());
                if (!stale) setResults(found);
            } catch (err) {
                if (!stale) setError(errorMessage(err));
            }
        }, 200);
        return () => { stale = true; clearTimeout(timer); };
    }, [query, product]);

    useEffect(() => {
        if (!product) return;
        piecesField.current?.focus();
        piecesField.current?.select();
    }, [product]);

    // Scanning the item's usual barcode is the quickest way to say which product this is
    useScanner(async (scanned) => {
        setError(null);
        try {
            const found = await lookupBarcode(scanned);
            beep(true);
            setProduct(found);
        } catch (err) {
            beep(false);
            setError(err?.response?.status === 404 ? `Barcode ${scanned} isn't on file either. Search by name instead.` : errorMessage(err));
        }
    });

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post(`/products/${product.id}/barcodes`, { barcode: code, pieces, label: label.trim() || null });
            onDone(data);
        } catch (err) {
            setError(errorMessage(err));
            setSaving(false);
        }
    };

    const submit = (e) => {
        e.preventDefault();
        if (product) save();
        else if (results.length) setProduct(results[0]);
    };

    return (
        <Modal onClose={saving ? undefined : onCancel}>
            <h2 className="text-lg font-semibold">Add this barcode to a product</h2>
            <p className="mt-1 font-mono text-sm text-slate-500">{code}</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                {product ? (
                    <>
                        <div className="flex items-center justify-between gap-3 rounded-xl bg-brand-50 px-3 py-2 text-sm">
                            <span className="min-w-0">
                                <span className="block truncate font-medium">{product.name}</span>
                                <span className="text-xs text-slate-500">{peso(product.price)} / {product.unit} · {qtyText(product.stock)} {product.unit} in stock</span>
                            </span>
                            <button type="button" className="shrink-0 text-xs font-medium text-brand-700 underline" onClick={() => setProduct(null)}>Change</button>
                        </div>
                        <div className="grid grid-cols-[110px_minmax(0,1fr)] gap-3">
                            <div>
                                <label className="label" htmlFor="attach-pieces">Pieces inside</label>
                                <input id="attach-pieces" ref={piecesField} className="input" type="number" min="0" step="any" required value={pieces} onChange={(e) => setPieces(e.target.value)} />
                            </div>
                            <div>
                                <label className="label" htmlFor="attach-label">Name (optional)</label>
                                <input id="attach-label" className="input" maxLength="60" placeholder="e.g. Box of 30" autoComplete="off" value={label} onChange={(e) => setLabel(e.target.value)} />
                            </div>
                        </div>
                        <p className="text-xs text-slate-500">For a box or pack, enter how many pieces are inside. Use 1 for the same item with another code.</p>
                    </>
                ) : (
                    <div>
                        <label className="label" htmlFor="attach-search">Which product is it?</label>
                        <input id="attach-search" autoFocus className="input" autoComplete="off" placeholder="Scan its usual barcode, or type its name" value={query} onChange={(e) => setQuery(e.target.value)} />
                        {results.length > 0 && (
                            <ul className="mt-2 max-h-56 overflow-auto rounded-xl border border-slate-200 p-1">
                                {results.map((p) => (
                                    <li key={p.id}>
                                        <button type="button" className="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-brand-50" onClick={() => setProduct(p)}>
                                            <span className="min-w-0 truncate font-medium">{p.name}</span>
                                            <span className="shrink-0 text-xs text-slate-500">{qtyText(p.stock)} {p.unit}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
                {error && <p role="alert" className="text-sm font-medium text-red-600">{error}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" disabled={saving} onClick={onCancel}>Cancel</button>
                    <button className="btn btn-primary" disabled={!product || saving}>{saving ? 'Saving…' : 'Add barcode'}</button>
                </div>
            </form>
        </Modal>
    );
}
