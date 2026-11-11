import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { beep, errorMessage, freeQty, lineTotal, peso, qtyText, round2 } from '../../lib';
import { useScanner } from '../../useScanner';

/** What the server needs for an order line: a product or a basket, and how many. */
const saleLine = (l) => (l.basket ? { basket_id: l.basket_id, quantity: l.qty } : { product_id: l.id, quantity: l.qty });

export function Modal({ children, onClose }) {
    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);
    return (
        <div className="pos-backdrop fixed inset-0 z-50 m-0 flex items-center justify-center p-4">
            <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">{children}</div>
        </div>
    );
}

export function WeightDialog({ product, onConfirm, onCancel }) {
    const [kg, setKg] = useState('1');
    const [scanHint, setScanHint] = useState(false);
    const value = parseFloat(kg) || 0;
    useScanner(() => { beep(false); setScanHint(true); });
    return (
        <Modal onClose={onCancel}>
            <h2 className="text-lg font-semibold">{product.name}</h2>
            <p className="text-sm text-slate-500">{peso(product.price)} per {product.unit}. How much?</p>
            {scanHint && <p className="mt-2 text-sm font-medium text-red-600">Scan not added. Finish this item first, then scan again.</p>}
            <form onSubmit={(e) => { e.preventDefault(); value > 0 && onConfirm(value); }}>
                <div className="mt-4 flex items-center gap-2">
                    <input autoFocus type="number" step="0.01" min="0.01" value={kg} onChange={(e) => setKg(e.target.value)}
                        onFocus={(e) => e.target.select()} className="input text-2xl" />
                    <span className="text-lg text-slate-500">{product.unit}</span>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    {[0.25, 0.5, 1, 2, 5, 10, 25].map((v) => (
                        <button key={v} type="button" className="btn btn-secondary px-3 py-1" onClick={() => setKg(String(v))}>{v}</button>
                    ))}
                </div>
                <div className="mt-4 text-right text-xl font-bold">{peso(value * product.price)}</div>
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onCancel}>Cancel</button>
                    <button className="btn btn-primary" disabled={value <= 0}>Add to sale</button>
                </div>
            </form>
        </Modal>
    );
}

// Hides the typing without being a real password box, which would make the browser offer to save the supervisor's password on the cashier's PC
const CAN_MASK_TEXT = typeof CSS !== 'undefined' && CSS.supports?.('-webkit-text-security', 'disc');

/** A supervisor types their account password or passkey before lines come off the sale. */
export function VoidApprovalDialog({ request, onApproved, onCancel }) {
    const [credential, setCredential] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const field = useRef();
    const { lines, clearAll } = request;
    const amount = round2(lines.reduce((s, l) => s + lineTotal(l), 0));

    // A scan here must not end up in the password box or add to the sale
    useScanner(() => {
        beep(false);
        setError('Scan not added. Finish this first, then scan again.');
    });

    const submit = async (e) => {
        e.preventDefault();
        if (!credential || saving) return;
        setSaving(true);
        setError(null);
        try {
            await axios.post('/pos/void-approval', { credential, lines: lines.map(saleLine) });
            onApproved();
        } catch (err) {
            beep(false);
            setError(errorMessage(err));
            setCredential('');
            setSaving(false);
            field.current?.focus();
        }
    };

    return (
        <Modal onClose={saving ? undefined : onCancel}>
            <h2 className="text-lg font-semibold">Supervisor approval needed</h2>
            <p className="mt-1 text-sm text-slate-500">
                {clearAll
                    ? `Clear the whole order: ${lines.length} line${lines.length === 1 ? '' : 's'}, ${peso(amount)}.`
                    : `Void ${lines[0].name} × ${qtyText(lines[0].qty)}, ${peso(amount)}.`}
            </p>
            <form onSubmit={submit} autoComplete="off" className="mt-4">
                <label htmlFor="void-approval-credential" className="label">Supervisor's password or passkey</label>
                <input id="void-approval-credential" ref={field} autoFocus type={CAN_MASK_TEXT ? 'text' : 'password'} value={credential} readOnly={saving}
                    onChange={(e) => { setCredential(e.target.value); setError(null); }} autoComplete={CAN_MASK_TEXT ? 'off' : 'new-password'} autoCapitalize="off" autoCorrect="off" spellCheck={false}
                    style={CAN_MASK_TEXT ? { WebkitTextSecurity: 'disc' } : undefined} className="input text-lg" />
                {error && <p role="alert" className="mt-2 text-sm font-medium text-red-600">{error}</p>}
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" disabled={saving} onClick={onCancel}>Cancel</button>
                    <button className="btn btn-primary" disabled={!credential || saving}>{saving ? 'Checking…' : clearAll ? 'Approve and clear' : 'Approve void'}</button>
                </div>
            </form>
        </Modal>
    );
}

const V = (name) => `var(--pos-${name})`;
const PAD =['1', '2', '3', '4', '5', '6', '7', '8', '9', '00', '0', '⌫'];
// The backend accepts these three; the layout follows the Checkout design
const METHODS = [['cash', 'Cash', 'Count & change'], ['gcash', 'GCash', 'Scan QR'], ['card', 'Card', 'Tap or swipe']];
const money = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyLabel = (l) => `${Number(l.qty).toLocaleString('en-PH', { maximumFractionDigits: 3 })} ${l.unit || ''}`.trim();
const tint = (color, pct) => `color-mix(in srgb, ${color} ${pct}%, var(--pos-surface))`;

/** Full-screen checkout from the design: method, cash keypad, change, and a 58 mm receipt preview. */
export function PayDialog({ subtotal, cart, onCancel, onDone, storeName, cashier }) {
    const [discount, setDiscount] = useState('');
    const [paid, setPaid] = useState('');
    const [method, setMethod] = useState('cash');
    const [print, setPrint] = useState(() => { try { return localStorage.getItem('pos-print') !== '0'; } catch { return true; } });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const sending = useRef(false); // Enter can arrive from both the form and the window listener
    const paidBeforeScan = useRef('');

    // A scan here must not change the cash amount or complete the sale
    useScanner(() => {
        setPaid(paidBeforeScan.current);
        beep(false);
        setError('Scan not added. Go back to the sale to add more items.');
    }, { onStart: () => { paidBeforeScan.current = paid; } });

    const disc = Math.min(parseFloat(discount) || 0, subtotal);
    const total = round2(subtotal - disc);
    const tendered = method === 'cash' ? parseFloat(paid) || 0 : total;
    const change = round2(tendered - total);
    const ok = change >= 0;
    const quick = [...new Set([100, 500, 1000].map((d) => Math.ceil(total / d) * d))].filter((v) => v > total).slice(0, 2);

    const submit = async (e) => {
        e?.preventDefault?.();
        if (!ok || sending.current || !cart.length) return;
        sending.current = true;
        setSaving(true);
        setError(null);
        try { localStorage.setItem('pos-print', print ? '1' : '0'); } catch { /* optional */ }
        try {
            const { data } = await axios.post('/pos/sales', {
                items: cart.map(saleLine),
                discount: disc,
                amount_paid: tendered,
                payment_method: method,
            });
            if (print) window.open(data.receipt_url + '?print=1', 'receipt', 'width=380,height=640');
            onDone(data);
        } catch (err) {
            setError(errorMessage(err));
            setSaving(false);
            sending.current = false;
        }
    };

    // The keyboard types into the cash amount, like the on-screen keypad
    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape' && !saving) return onCancel();
            if (e.target.tagName === 'INPUT') return;
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') return submit(e);
            if (method !== 'cash') return;
            if (/^\d$/.test(e.key)) setPaid((p) => (p + e.key).replace(/^0+(?=\d)/, '').slice(0, 9));
            if (e.key === '.') setPaid((p) => (p.includes('.') ? p : p + '.'));
            if (e.key === 'Backspace') setPaid((p) => p.slice(0, -1));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const press = (k) => {
        if (k === '⌫') return setPaid((p) => p.slice(0, -1));
        setPaid((p) => (p + k).replace(/^0+(?=\d)/, '').slice(0, 9));
    };

    const card = { background: V('surface'), border: `1px solid ${V('border')}`, borderRadius: 16, boxShadow: 'var(--pos-shadow)' };
    const cap = { fontSize: 14, fontWeight: 600, color: V('muted') };
    const good = ok ? 'var(--pos-brand)' : '#dc2626';
    const now = new Date().toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" style={{ background: V('bg'), color: V('text') }}>
            <header className="pos-topbar flex items-center gap-4 px-6 py-3">
                <button type="button" onClick={onCancel} disabled={saving} className="pos-btn pos-btn-line px-4 py-2.5">‹ Back to sale</button>
                <div>
                    <div className="pos-heading text-xl font-bold">Checkout</div>
                    <div className="text-sm" style={{ color: V('muted') }}>{cart.length} line{cart.length === 1 ? '' : 's'} · {cashier}</div>
                </div>
            </header>

            <form onSubmit={submit} className="grid gap-6 p-6 xl:grid-cols-[1fr_380px]">
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-5 p-6" style={card}>
                        <div className="mr-auto">
                            <div style={cap}>Amount due</div>
                            <div className="pos-heading text-6xl font-bold tracking-tight">{peso(total)}</div>
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm" style={{ color: V('muted') }}>
                                Subtotal {peso(subtotal)} · Discount ₱
                                <input type="number" min="0" step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} placeholder="0.00"
                                    className="w-24 rounded-md px-2 py-1 text-right outline-none" style={{ background: V('bg'), border: `1px solid ${V('border')}`, color: V('text') }} />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            {METHODS.map(([v, label, sub]) => (
                                <button key={v} type="button" onClick={() => setMethod(v)} aria-pressed={method === v} className="min-w-28 rounded-2xl px-4 py-3 text-center transition"
                                    style={method === v ? { background: V('soft'), border: `2px solid ${V('brand')}` } : { background: V('surface'), border: `2px solid ${V('border')}` }}>
                                    <div className="text-lg font-semibold" style={method === v ? { color: V('soft-ink') } : undefined}>{label}</div>
                                    <div className="text-sm" style={{ color: V('muted') }}>{sub}</div>
                                </button>
                            ))}
                        </div>
                    </div>

                    {method === 'cash' ? (
                        <div className="grid gap-5 lg:grid-cols-2">
                            <div className="space-y-4">
                                <div className="p-6" style={card}>
                                    <div style={cap}>Cash received</div>
                                    <div className="text-5xl font-bold">{paid ? peso(parseFloat(paid) || 0) : <span style={{ color: V('muted') }}>₱0.00</span>}</div>
                                </div>
                                <div className="p-6" style={{ borderRadius: 16, background: tint(good, 12), border: `1px solid ${tint(good, 40)}` }}>
                                    <div style={{ ...cap, color: `color-mix(in srgb, ${good} 65%, var(--pos-text))` }}>{ok ? 'Change' : 'Still due'}</div>
                                    <div className="text-6xl font-bold" style={{ color: `color-mix(in srgb, ${good} 75%, var(--pos-text))` }}>{peso(Math.abs(change))}</div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <button type="button" className="pos-btn pos-btn-soft py-4 text-lg" onClick={() => setPaid(String(total))}>Exact {peso(total)}</button>
                                    {quick.map((v) => <button key={v} type="button" className="pos-btn pos-btn-soft py-4 text-lg" onClick={() => setPaid(String(v))}>{peso(v).replace('.00', '')}</button>)}
                                    <button type="button" className="pos-btn pos-btn-line py-4 text-lg" onClick={() => setPaid('')}>Clear</button>
                                </div>
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                {PAD.map((k) => <button key={k} type="button" className="min-h-20 text-3xl font-medium transition hover:border-[var(--pos-brand)] active:scale-[0.97]" style={card} onClick={() => press(k)}>{k}</button>)}
                            </div>
                        </div>
                    ) : (
                        <div className="p-8 text-lg" style={card}>
                            {method === 'gcash' ? 'Ask the customer to scan the store GCash QR, then check that the payment came in.' : 'Run the card on the terminal, then complete the sale.'}
                            <div className="mt-2 text-sm" style={{ color: V('muted') }}>Amount: {peso(total)}</div>
                        </div>
                    )}

                    {error && <p className="pos-error rounded-xl px-4 py-3 text-sm">{error}</p>}
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <label className="flex items-center gap-3 text-lg">
                            <input type="checkbox" checked={print} onChange={(e) => setPrint(e.target.checked)} className="h-6 w-6" style={{ accentColor: 'var(--pos-brand)' }} /> Print receipt
                        </label>
                        <button className="pos-btn pos-btn-primary px-10 py-5 text-xl" disabled={!ok || saving}>
                            {saving ? 'Saving…' : method === 'cash' ? `Complete sale · change ${peso(Math.max(change, 0))}` : `Complete sale · ${peso(total)}`}
                        </button>
                    </div>
                </div>

                <div>
                    <div className="mb-3" style={cap}>Receipt preview · 58 mm</div>
                    <div className="rounded-2xl border border-slate-200 bg-[#FBFAF7] p-6 text-[13px] leading-6 text-[#1a1a1a]" style={{ fontFamily: 'ui-monospace, Consolas, monospace' }}>
                        <div className="text-center font-bold uppercase">{storeName}</div>
                        <div className="text-center">{now}</div>
                        <div className="text-center">Cashier: {cashier}</div>
                        <div className="my-2 border-t border-dashed border-[#999]" />
                        {cart.map((l) => (
                            <div key={l.id}>
                                <div>{l.name}</div>
                                <div className="flex justify-between"><span>{qtyLabel(l)} x {money(l.price)}</span><span>{money(l.price * l.qty)}</span></div>
                                {freeQty(l) > 0 && <div className="flex justify-between"><span>&nbsp;{l.promo.label} ({qtyText(freeQty(l))} free)</span><span>-{money(l.price * freeQty(l))}</span></div>}
                                {l.basket && (l.contents || []).map((c) => <div key={c.name}>&nbsp;&nbsp;{qtyText(c.qty * l.qty)} {c.name}</div>)}
                            </div>
                        ))}
                        <div className="my-2 border-t border-dashed border-[#999]" />
                        {disc > 0 && <div className="flex justify-between"><span>Discount</span><span>-{money(disc)}</span></div>}
                        <div className="flex justify-between text-[15px] font-bold"><span>TOTAL</span><span>{peso(total)}</span></div>
                        <div className="flex justify-between"><span>{METHODS.find((m) => m[0] === method)[1]}</span><span>{peso(tendered)}</span></div>
                        {method === 'cash' && <div className="flex justify-between"><span>CHANGE</span><span>{peso(Math.max(change, 0))}</span></div>}
                        <div className="my-2 border-t border-dashed border-[#999]" />
                        <div className="text-center">Salamat po! Balik kayo.</div>
                    </div>
                </div>
            </form>
        </div>
    );
}

export function DoneDialog({ result, onNext, onScan }) {
    const nextRef = useRef();
    useScanner((code) => onScan(code)); // scanning the next customer's first item starts their sale
    useEffect(() => nextRef.current?.focus(), []);
    const print = () => window.open(result.receipt_url + '?print=1', 'receipt', 'width=380,height=640');
    return (
        <Modal onClose={onNext}>
            <div className="text-center">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-2xl text-brand-700">✓</div>
                <h2 className="mt-3 text-lg font-semibold">Sale complete</h2>
                <p className="text-sm text-slate-500">Order no. {result.receipt_no}</p>
                <div className="mt-4 text-sm text-slate-500">Change</div>
                <div className="text-5xl font-bold text-brand-700">{peso(result.change)}</div>
                <div className="mt-6 flex justify-center gap-2">
                    <button type="button" className="btn btn-secondary" onClick={print}>Print receipt</button>
                    <button ref={nextRef} type="button" className="btn btn-primary px-6" onClick={onNext}>Next customer ↵</button>
                </div>
            </div>
        </Modal>
    );
}
