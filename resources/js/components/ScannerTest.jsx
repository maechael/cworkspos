import React, { useEffect, useRef, useState } from 'react';
import { baseUrl, beep, lookupBarcode, peso, qtyText } from '../lib';
import { useScanner } from '../useScanner';

const ENDINGS = { Enter: 'Enter', Tab: 'Tab', none: 'Nothing', typed: 'Enter' };

let scanId = 0;

/** Checks the scanner without touching stock or sales: shows what it sends and which product it finds. */
export default function ScannerTest() {
    const [scans, setScans] = useState([]);
    const [typed, setTyped] = useState('');
    const inputRef = useRef();

    useEffect(() => inputRef.current?.focus(), []);

    const check = async (code, reading) => {
        const id = ++scanId;
        setScans((s) => [{ id, code, ...reading, status: 'checking' }, ...s].slice(0, 10));
        const finish = (fields) => setScans((s) => s.map((x) => (x.id === id ? { ...x, ...fields } : x)));
        try {
            const product = await lookupBarcode(code);
            beep(true);
            finish({ status: 'found', product });
        } catch (err) {
            beep(false);
            finish({ status: err?.response?.status === 404 ? 'missing' : 'error' });
        }
    };

    useScanner((code, { suffix, msPerKey }) => {
        setTyped('');
        check(code, { fast: true, ending: suffix || 'none', msPerKey });
    });

    // Reaching this box means the keys were too slow to count as a scan
    const onKeyDown = (e) => {
        if (e.key !== 'Enter' || !typed.trim()) return;
        e.preventDefault();
        check(typed.trim(), { fast: false, ending: 'typed' });
        setTyped('');
    };

    const last = scans[0];

    return (
        <div className="max-w-4xl space-y-4">
            <div className="card p-5">
                <label className="label" htmlFor="scanner-test">Scan any item</label>
                <input id="scanner-test" ref={inputRef} className="input font-mono text-lg" autoComplete="off" value={typed}
                    onChange={(e) => setTyped(e.target.value)} onKeyDown={onKeyDown} placeholder="Waiting for a scan…" />
                <p className="mt-2 text-sm text-slate-500">This page only reads the barcode. It doesn't change stock or record a sale.</p>
            </div>

            {last && last.fast === true && last.ending === 'none' && (
                <div className="rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-700">
                    Scanner detected. It doesn't send Enter after a code, but scans will still work on every screen.
                </div>
            )}
            {last && last.fast === true && last.ending !== 'none' && (
                <div className="rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-700">Scanner detected and ready. Scans will work on every screen, wherever the cursor is.</div>
            )}
            {last && last.fast === false && (
                <div className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    This code came in slowly, or was typed by hand. If it was the scanner, keep the cursor in the scan box when scanning, because the scanner won't be recognized anywhere else.
                </div>
            )}

            <div className="card overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Barcode read</th>
                            <th className="text-right">Digits</th>
                            <th className="text-right">Speed</th>
                            <th>Ends with</th>
                            <th>Product</th>
                        </tr>
                    </thead>
                    <tbody>
                        {scans.length === 0 && <tr><td colSpan="5" className="py-10 text-center text-slate-400">No scans yet.</td></tr>}
                        {scans.map((s) => (
                            <tr key={s.id}>
                                <td className="font-mono">{s.code}</td>
                                <td className="text-right">{s.code.length}</td>
                                <td className="text-right text-slate-500">{s.fast ? `${s.msPerKey} ms/key` : 'Slow'}</td>
                                <td className="text-slate-500">{ENDINGS[s.ending]}</td>
                                <td>
                                    {s.status === 'checking' && <span className="text-slate-400">Checking…</span>}
                                    {s.status === 'found' && (
                                        <span>
                                            <b>{s.product.name}</b> · {peso(s.product.price)} · {qtyText(s.product.stock)} {s.product.unit} in stock
                                            {s.product.scan_pieces > 1 && ` · ${s.product.scan_label || 'This barcode'}: ${qtyText(s.product.scan_pieces)} ${s.product.unit} per scan`}
                                        </span>
                                    )}
                                    {s.status === 'missing' && <a className="font-medium text-brand-700 hover:underline" href={`${baseUrl}/products/create?barcode=${encodeURIComponent(s.code)}`}>Not in the product list. Add it</a>}
                                    {s.status === 'error' && <span className="text-red-600">Couldn't check this code. Try again.</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
