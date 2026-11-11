import React, { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { beep, parseScan, qtyPrefix, searchProducts, peso, qtyText } from '../lib';
import { useScanner } from '../useScanner';

/**
 * The barcode field. A USB scanner types the code and presses Enter, so this is a
 * normal input that handles Enter. Typing a name instead shows matching products.
 */
const ScanBox = forwardRef(function ScanBox({ onScan, onPick, disabled, placeholder }, ref) {
    const [value, setValue] = useState('');
    const [results, setResults] = useState([]);
    const [active, setActive] = useState(0);
    const timer = useRef();
    const latest = useRef('');
    const inputRef = useRef();
    useImperativeHandle(ref, () => inputRef.current);

    // Only search by name; digits alone are treated as a barcode being typed
    const isNameSearch = (code) => code.length >= 2 && !/^\d+$/.test(code);

    const search = async (code) => {
        latest.current = code;
        const found = await searchProducts(code);
        // Ignore answers that arrive after the box was cleared or changed
        if (latest.current !== code) return null;
        setResults(found);
        setActive(0);
        return found;
    };

    useEffect(() => {
        clearTimeout(timer.current);
        const { code } = parseScan(value);
        if (!isNameSearch(code)) {
            latest.current = '';
            setResults([]);
            return;
        }
        timer.current = setTimeout(() => search(code), 200);
        return () => clearTimeout(timer.current);
    }, [value]);

    const reset = () => {
        clearTimeout(timer.current);
        latest.current = '';
        setValue('');
        setResults([]);
    };

    // A scan arrives here wherever the cursor is
    useScanner((scanned, { field, before }) => {
        if (disabled) return beep(false);
        const qty = field === inputRef.current ? qtyPrefix(before) : null;
        reset();
        onScan(scanned, qty);
    });

    const pick = (product) => {
        const { qty } = parseScan(value);
        reset();
        onPick(product, qty);
    };

    const onKeyDown = async (e) => {
        if (results.length) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, results.length - 1)); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); return; }
            if (e.key === 'Escape') { setResults([]); return; }
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (results.length) return pick(results[active]);
            const parsed = parseScan(value);
            if (!parsed.code) return;
            if (isNameSearch(parsed.code)) {
                // Enter pressed before the list appeared: search now, take it if there is only one match
                clearTimeout(timer.current);
                const found = await search(parsed.code);
                if (found?.length === 1) {
                    reset();
                    onPick(found[0], parsed.qty);
                    return;
                }
                if (found?.length) return;
            }
            reset();
            onScan(parsed.code, parsed.qty, isNameSearch(parsed.code));
        }
    };

    return (
        <div className="relative">
            <div className="flex items-center gap-3 rounded-xl border-2 border-brand-500 bg-white px-4 py-3 shadow-sm focus-within:ring-4 focus-within:ring-brand-100">
                <svg className="h-7 w-7 shrink-0 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M3 5v14M7 5v14M11 5v14M14 5v14M18 5v14M21 5v14" />
                </svg>
                <input
                    ref={inputRef}
                    value={value}
                    disabled={disabled}
                    onChange={(e) => setValue(e.target.value)}
                    onKeyDown={onKeyDown}
                    autoFocus
                    autoComplete="off"
                    placeholder={placeholder || 'Scan barcode or type product name…'}
                    className="w-full bg-transparent text-lg outline-none placeholder:text-slate-400"
                />
                <span className="hidden shrink-0 rounded bg-slate-100 px-2 py-1 text-xs text-slate-500 sm:block">F2</span>
            </div>
            {results.length > 0 && (
                <ul className="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                    {results.map((p, i) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); pick(p); }}
                                className={`flex w-full items-center justify-between px-4 py-2 text-left text-sm ${i === active ? 'bg-brand-50' : ''}`}
                            >
                                <span>
                                    <span className="font-medium text-slate-800">{p.name}</span>
                                    <span className="ml-2 text-xs text-slate-400">{p.barcode}</span>
                                </span>
                                <span className="text-right">
                                    <span className="font-semibold">{peso(p.price)}</span>
                                    <span className="ml-2 text-xs text-slate-500">{qtyText(p.stock)} {p.unit} left</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
});

export default ScanBox;
