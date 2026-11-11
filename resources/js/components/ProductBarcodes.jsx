import React, { useEffect, useRef, useState } from 'react';
import { beep } from '../lib';
import { useScanner } from '../useScanner';

/**
 * The extra barcodes in the product form. A scan anywhere on the page lands here, so an owner can
 * scan the sachet, the pack and the box one after another. The rows post with the rest of the form.
 */
export default function ProductBarcodes({ rows: start = [] }) {
    const [rows, setRows] = useState(start);
    const [typed, setTyped] = useState('');
    const [note, setNote] = useState(null);
    const added = useRef(false);
    const list = useRef();

    const add = (raw) => {
        const code = raw.trim();
        if (!code) return;
        setTyped('');

        const main = document.querySelector('input[name=barcode]');
        if (main.value.trim() === code || rows.some((r) => r.barcode === code)) {
            beep(false);
            setNote(`Barcode ${code} is already on this product.`);
            return;
        }
        // The first code on a new product is its main barcode
        if (!main.value.trim()) {
            main.value = code;
            beep(true);
            setNote(null);
            document.querySelector('input[name=name]')?.focus();
            return;
        }
        beep(true);
        setNote(null);
        added.current = true;
        setRows((r) => [...r, { barcode: code, pieces: 1, label: '' }]);
    };

    useScanner((code) => add(code));

    // The code in the main box turned out to be printed on the box, not on one piece
    const mainIsTheBox = () => {
        const main = document.querySelector('input[name=barcode]');
        const code = main.value.trim();
        if (!code) {
            setNote('Scan the box first, then press this.');
            return;
        }
        main.value = '';
        added.current = true;
        setNote('Now set the pieces inside. Then scan the barcode on one piece, or leave the top box empty and the system makes a code you can print.');
        setRows((r) => [...r, { barcode: code, pieces: 1, label: 'Box' }]);
    };

    // The next thing to decide about a new code is how many pieces it means
    useEffect(() => {
        if (!added.current) return;
        added.current = false;
        const field = list.current?.querySelector('[data-row]:last-child input[data-pieces]');
        field?.focus();
        field?.select();
    }, [rows.length]);

    const update = (i, field, value) => setRows((r) => r.map((row, n) => (n === i ? { ...row, [field]: value } : row)));

    // Enter must not save the whole form while a code is being added
    const keepFormOpen = (e) => {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault();
    };

    return (
        <div className="border-t pt-5" onKeyDown={keepFormOpen}>
            <div className="text-sm font-semibold">More barcodes</div>
            <p className="mt-0.5 text-xs text-slate-500">
                The top barcode is for one piece. Add the box's barcode here and say how many pieces are inside, so scanning a box at delivery adds all of them to stock. Use 1 for the same item with another code.
            </p>
            <button type="button" className="mt-2 text-sm font-medium text-brand-700 underline" onClick={mainIsTheBox}>The top barcode is on the box, not on one piece</button>

            {rows.length > 0 && (
                <div ref={list} className="mt-3 space-y-2">
                    {rows.map((row, i) => (
                        <div key={row.barcode} data-row className="grid gap-2 sm:grid-cols-[minmax(0,1.3fr)_110px_minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <label className="label text-xs" htmlFor={`extra-barcode-${i}`}>Barcode</label>
                                <input id={`extra-barcode-${i}`} className="input py-1.5 font-mono" name={`extra_barcodes[${i}][barcode]`} value={row.barcode} autoComplete="off"
                                    onChange={(e) => update(i, 'barcode', e.target.value)} />
                            </div>
                            <div>
                                <label className="label text-xs" htmlFor={`extra-pieces-${i}`}>Pieces inside</label>
                                <input id={`extra-pieces-${i}`} data-pieces className="input py-1.5" type="number" min="0" step="any" name={`extra_barcodes[${i}][pieces]`} value={row.pieces} required
                                    onChange={(e) => update(i, 'pieces', e.target.value)} />
                            </div>
                            <div>
                                <label className="label text-xs" htmlFor={`extra-label-${i}`}>Name (optional)</label>
                                <input id={`extra-label-${i}`} className="input py-1.5" name={`extra_barcodes[${i}][label]`} value={row.label ?? ''} maxLength="60" placeholder="e.g. Box of 30" autoComplete="off"
                                    onChange={(e) => update(i, 'label', e.target.value)} />
                            </div>
                            <button type="button" className="btn btn-secondary py-1.5" aria-label={`Remove barcode ${row.barcode}`} onClick={() => setRows((r) => r.filter((_, n) => n !== i))}>Remove</button>
                        </div>
                    ))}
                </div>
            )}

            <div className="mt-3 flex gap-2">
                <input className="input font-mono" aria-label="Add another barcode" placeholder="Scan or type another barcode" autoComplete="off" value={typed}
                    onChange={(e) => setTyped(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && add(typed)} />
                <button type="button" className="btn btn-secondary shrink-0" disabled={!typed.trim()} onClick={() => add(typed)}>Add barcode</button>
            </div>
            {note && <p role="status" className="mt-2 text-sm font-medium text-amber-700">{note}</p>}
        </div>
    );
}
