import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { errorMessage, lineTotal, round2 } from '../lib';
import { STYLES } from './Pos';
import { SCHEMES } from './pos/schemes';
import { getScheme, themeClass, themeStyle } from './pos/theme';

// Look of the "Store setup & appearance" design screen
const C = { accent: 'var(--color-brand-600)', accentSoft: 'var(--color-brand-50)', ink: 'var(--color-slate-900)', sub: 'var(--color-slate-600)', muted: 'var(--color-slate-500)', line: 'var(--color-slate-200)', bg: 'var(--color-slate-100)', card: 'var(--color-white)' };

const ICONS = {
    cart: 'M3 4h2l2.4 10.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 8H6.2M9 20h.01M17 20h.01',
    store: 'M4 9l1-5h14l1 5M4 9h16v11H4V9Zm0 0a3 3 0 0 0 5 1 3 3 0 0 0 6 0 3 3 0 0 0 5-1M10 20v-5h4v5',
    pill: 'M10.5 20.5a5 5 0 0 1-7-7l6-6a5 5 0 0 1 7 7l-6 6ZM7 10l7 7',
    wrench: 'M14.7 6.3a4 4 0 0 0 5 5L13 18l-3 3-4-4 3-3 6.7-6.7Zm0 0L18 3',
    box: 'M4 8l8-4 8 4v8l-8 4-8-4V8Zm0 0 8 4 8-4m-8 4v8',
    cup: 'M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5V9Zm11 1h1.5a2.5 2.5 0 0 1 0 5H16M8 3v3M12 3v3',
    bread: 'M4 12a8 5 0 0 1 16 0v6H4v-6Zm4-3v4m4-5v5m4-4v4',
    dish: 'M3 17h18M5 17a7 7 0 0 1 14 0M12 7V5m-2 0h4',
    bowl: 'M4 11h16a8 8 0 0 1-16 0Zm4-3c0-2 2-3 4-3s4 1 4 3',
    burger: 'M4 10a8 5 0 0 1 16 0H4Zm0 3h16M5 16h14v1a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-1Z',
    truck: 'M3 6h11v10H3V6Zm11 4h4l3 3v3h-7v-6ZM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    shirt: 'M8 3 4 6l2 4 2-1v12h8V9l2 1 2-4-4-3a4 4 0 0 1-8 0Z',
    gift: 'M4 10h16v10H4V10Zm-1-3h18v3H3V7Zm9 0v13M12 7c-1-3-5-3-5 0m5 0c1-3 5-3 5 0',
    scissors: 'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8.5 7.5 20 19M8.5 16.5 20 5',
    paw: 'M12 20c-3 0-5-2-5-4s2-4 5-4 5 2 5 4-2 4-5 4ZM6 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm12 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM9 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm6 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    drop: 'M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11Z',
};
const Glyph = ({ name, size = 20 }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={ICONS[name] || ICONS.box} /></svg>
);

function fitFor(b, styleKey) {
    if (b.best === styleKey) return 'best';
    return b.good.includes(styleKey) ? 'good' : null;
}

function FitBadge({ fit, color = C.ink }) {
    if (fit === 'best') return <span className="rounded-full px-2.5 py-0.5 text-xs font-bold" style={{ background: color, color: C.card }}>Best</span>;
    if (fit === 'good') return <span className="rounded-full border px-2.5 py-0.5 text-xs font-semibold" style={{ borderColor: C.muted, color: C.sub }}>Good</span>;
    return <span style={{ color: C.muted }}>–</span>;
}

/**
 * Setup → POS style. Pick the kind of business, get the recommended counter style and colors
 * ("Which style fits which business?"), and see the real cashier screen live before saving.
 */
export default function PosSettings({ styles, businessTypes, questions, current, storeName, saveUrl, previewUrl }) {
    const [business, setBusiness] = useState(current.business_type);
    const [style, setStyle] = useState(current.style);
    const [scheme, setScheme] = useState(current.scheme);
    const [saved, setSaved] = useState(current);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);

    const b = businessTypes[business];
    const st = styles[style];
    const isRec = style === b.best;
    const dirty = saved.business_type !== business || saved.style !== style || saved.scheme !== scheme;

    const chooseBusiness = (key) => {
        setBusiness(key);
        setStyle(businessTypes[key].best);
        setMessage(null);
    };
    const reset = () => {
        setBusiness(saved.business_type);
        setStyle(saved.style);
        setScheme(saved.scheme);
        setMessage(null);
    };
    const save = async () => {
        setSaving(true);
        setMessage(null);
        try {
            const { data } = await axios.put(saveUrl, { business_type: business, style, scheme });
            setSaved(data.current);
            setMessage({ ok: true, text: 'Saved. Every counter shows this style the next time the cashier screen opens.' });
        } catch (err) {
            setMessage({ ok: false, text: errorMessage(err) });
        } finally {
            setSaving(false);
        }
    };

    const step = { fontSize: 12, fontWeight: 700, letterSpacing: 1.4, color: C.muted };
    const card = { background: C.card, border: `1px solid ${C.line}`, borderRadius: 20 };

    return (
        <div style={{ color: C.ink }} className="space-y-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 className="text-2xl font-extrabold tracking-tight sm:text-3xl">Store setup &amp; appearance</h2>
                    <p className="mt-1" style={{ color: C.sub }}>Pick your kind of business, then a counter style and colors. The preview shows the real cashier screen.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={reset} disabled={!dirty} className="rounded-xl border px-5 py-3 font-bold disabled:opacity-40" style={{ borderColor: C.line, background: C.card }}>Reset</button>
                    <a href={`${previewUrl}?style=${style}&scheme=${scheme}`} target="_blank" rel="noreferrer" className="rounded-xl border px-5 py-3 font-bold" style={{ borderColor: C.line, background: C.card }}>Try it live ↗</a>
                    <button type="button" onClick={save} disabled={saving || !dirty} className="rounded-xl px-6 py-3 font-bold text-white disabled:opacity-50" style={{ background: C.accent }}>
                        {saving ? 'Saving…' : dirty ? 'Save & apply' : 'Saved'}
                    </button>
                </div>
            </div>
            {message && <div className="rounded-xl px-4 py-3 text-sm font-semibold" style={{ background: message.ok ? 'var(--color-green-50)' : 'var(--color-red-50)', color: message.ok ? 'var(--color-green-700)' : 'var(--color-red-700)' }}>{message.text}</div>}

            {/* minmax(0, …) lets the columns shrink below the 1280px-wide preview inside them, which is scaled down to fit */}
            <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-6 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="min-w-0 space-y-6">
                    {/* Step 1 */}
                    <section className="p-4 sm:p-6" style={card}>
                        <div style={step}>STEP 1</div>
                        <h3 className="text-xl font-extrabold">What kind of business?</h3>
                        <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3">
                            {Object.entries(businessTypes).map(([key, bt]) => {
                                const on = business === key;
                                return (
                                    <button key={key} type="button" onClick={() => chooseBusiness(key)} className="rounded-2xl p-4 text-left transition"
                                        style={{ border: `2px solid ${on ? C.accent : C.line}`, background: on ? C.accentSoft : C.card }}>
                                        <span className="flex h-10 w-10 items-center justify-center rounded-xl" style={{ background: on ? C.accent : C.bg, color: on ? '#fff' : C.sub }}><Glyph name={bt.icon} /></span>
                                        <span className="mt-3 block font-bold leading-snug">{bt.name}</span>
                                        <span className="mt-0.5 block text-sm" style={{ color: C.muted }}>{bt.sub}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <div className="mt-5 rounded-2xl p-5" style={{ background: C.bg }}>
                            <div className="text-sm font-bold">Our pick for {b.name}</div>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" onClick={() => chooseBusiness(business)} className="flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-bold" style={{ background: C.ink, color: C.card }}>
                                    ★ {styles[b.best].name}
                                </button>
                                {b.good.map((g) => (
                                    <button key={g} type="button" onClick={() => setStyle(g)} className="rounded-full border px-3 py-1.5 text-sm font-semibold" style={{ borderColor: C.muted, color: C.sub, background: C.card }}>
                                        Also works: {styles[g].name}
                                    </button>
                                ))}
                            </div>
                            <ul className="mt-3 space-y-1 text-sm" style={{ color: C.sub }}>
                                {styles[b.best].why.map((w) => <li key={w}>✓ {w}</li>)}
                            </ul>
                        </div>
                    </section>

                    {/* Step 2 */}
                    <section className="p-4 sm:p-6" style={card}>
                        <div style={step}>STEP 2</div>
                        <h3 className="text-xl font-extrabold">Counter style</h3>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            {Object.entries(styles).map(([key, s]) => {
                                const on = style === key;
                                const k = getScheme(scheme);
                                return (
                                    <button key={key} type="button" onClick={() => setStyle(key)} className="flex items-center gap-3 rounded-2xl p-3 text-left"
                                        style={{ border: `2px solid ${on ? C.accent : C.line}`, background: on ? C.accentSoft : C.card }}>
                                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-sm font-bold" style={{ background: k.soft, color: k.softInk, border: `2px solid ${k.brand}` }}>{s.number}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-bold">{s.name}</span>
                                            <span className="block truncate text-sm" style={{ color: C.muted }}>{s.kicker}</span>
                                        </span>
                                        <FitBadge fit={fitFor(b, key)} />
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    {/* Step 3 */}
                    <section className="p-4 sm:p-6" style={card}>
                        <div style={step}>STEP 3</div>
                        <h3 className="text-xl font-extrabold">Brand green</h3>
                        <p className="mt-1 text-sm" style={{ color: C.sub }}>All 8 styles share these colors.</p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            {Object.entries(SCHEMES).map(([key, k]) => {
                                const on = scheme === key;
                                return (
                                    <button key={key} type="button" onClick={() => setScheme(key)} aria-pressed={on} className="rounded-2xl p-3 text-left"
                                        style={{ border: `2px solid ${on ? C.accent : C.line}`, background: C.card }}>
                                        <span className="flex h-10 overflow-hidden rounded-lg" style={{ border: `1px solid ${C.line}` }}>
                                            {k.pal.map(([n, hex]) => <span key={n} className="flex-1" style={{ background: hex }} title={`${n} ${hex}`} />)}
                                        </span>
                                        <span className="mt-2 block font-bold">{k.name}</span>
                                        <span className="block text-xs" style={{ color: C.muted }}>{k.note}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>
                </div>

                {/* Live preview */}
                <div className="min-w-0 space-y-4 2xl:sticky 2xl:top-4">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 text-sm">
                        <span style={step}>LIVE PREVIEW · COUNTER SCREEN</span>
                        <span style={{ color: C.muted }}>{st.name} · {getScheme(scheme).name}{isRec ? ' · recommended' : ''}</span>
                    </div>
                    <LivePreview style={style} schemeKey={scheme} storeName={storeName} />
                    <div className="p-5" style={card}>
                        <div className="text-xs font-bold tracking-widest" style={{ color: C.muted }}>STYLE {st.number} · {st.kicker.toUpperCase()}</div>
                        <div className="text-2xl font-extrabold">{st.name}</div>
                        <p className="mt-1 text-sm" style={{ color: C.sub }}>{st.description}</p>
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {st.best_for.map((t) => <span key={t} className="rounded-full px-3 py-1 text-xs font-bold" style={{ background: C.ink, color: C.card }}>{t}</span>)}
                        </div>
                        <p className="mt-3 text-sm"><b>Also works:</b> <span style={{ color: C.sub }}>{st.also}</span></p>
                        <p className="text-sm"><b style={{ color: 'var(--color-red-700)' }}>Not ideal:</b> <span style={{ color: C.sub }}>{st.not_ideal}</span></p>
                        <p className="mt-3 text-xs" style={{ color: C.muted }}><b>HARDWARE</b> · {st.hardware}</p>
                    </div>
                </div>
            </div>

            <Matrix styles={styles} businessTypes={businessTypes} questions={questions} business={business} onPick={chooseBusiness} card={card} />
        </div>
    );
}

/** The actual cashier layout, drawn at 1280×800 and scaled to fit, with a sample cart. */
function LivePreview({ style, schemeKey, storeName }) {
    const box = useRef();
    const [scale, setScale] = useState(0.5);
    const [products, setProducts] = useState([]);
    useEffect(() => { axios.get('/api/products/catalog').then(({ data }) => setProducts(data)).catch(() => {}); }, []);
    useLayoutEffect(() => {
        const ro = new ResizeObserver(([e]) => setScale(e.contentRect.width / 1280));
        ro.observe(box.current);
        return () => ro.disconnect();
    }, []);

    const pos = useMemo(() => {
        const cart = products.slice(0, 4).map((p, i) => ({ ...p, qty: p.allows_decimal ? 2.5 : [2, 1, 3, 1][i] }));
        const subtotal = round2(cart.reduce((s, l) => s + lineTotal(l), 0));
        const noop = () => {};
        return {
            cart, subtotal, itemCount: cart.reduce((s, l) => s + (l.allows_decimal ? 1 : l.qty), 0), canPay: true, busy: true,
            selected: cart[1]?.id, held: [], notice: null, saleNo: 0, isPreview: true, scanRef: { current: null }, payKey: 'F8',
            setCart: noop, setSelected: noop, hold: noop, resume: noop, voidSelected: noop, flash: noop, focusScan: noop,
            handleProduct: noop, onScan: noop, setQty: noop, step: noop, remove: noop, clearOrder: noop, undo: null, undoLast: noop, dismissUndo: noop, newSale: noop, setPaying: noop,
        };
    }, [products]);

    const Layout = STYLES[style];
    return (
        <div ref={box} className="overflow-hidden rounded-[22px] shadow-xl" style={{ height: 800 * scale, background: '#000' }}>
            <div className={themeClass} style={{ ...themeStyle(getScheme(schemeKey)), width: 1280, height: 800, transform: `scale(${scale})`, transformOrigin: 'top left', pointerEvents: 'none' }} aria-hidden="true">
                <Layout pos={pos} cashier="Liza R." storeName={storeName} />
            </div>
        </div>
    );
}

function Matrix({ styles, businessTypes, questions, business, onPick, card }) {
    const keys = Object.keys(styles);
    return (
        <section className="overflow-hidden" style={card}>
            <div className="p-6">
                <div className="text-xs font-bold tracking-[0.2em]" style={{ color: C.accent }}>RECOMMENDATION GUIDE</div>
                <h3 className="text-2xl font-extrabold tracking-tight">Which style fits which business?</h3>
                <p className="text-sm" style={{ color: C.sub }}>All 8 styles are the same POS. Pick one here and switch any time. Tap a row to use its pick.</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[960px] text-sm">
                    <thead>
                        <tr style={{ borderTop: `1px solid ${C.line}`, borderBottom: `1px solid ${C.line}` }}>
                            <th className="px-6 py-3 text-left text-xs font-bold tracking-widest" style={{ color: C.muted }}>BUSINESS</th>
                            {keys.map((s) => (
                                <th key={s} className="px-2 py-3 text-center font-bold">
                                    {styles[s].name}
                                    <span className="block text-xs font-normal" style={{ color: C.muted }}>Style {styles[s].number}</span>
                                </th>
                            ))}
                            <th className="px-4 py-3 text-left text-xs font-bold tracking-widest" style={{ color: C.muted }}>OUR PICK</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(businessTypes).map(([key, bt]) => (
                            <tr key={key} onClick={() => onPick(key)} className="cursor-pointer hover:bg-slate-50"
                                style={{ borderBottom: `1px solid ${C.line}`, background: key === business ? C.accentSoft : undefined }}>
                                <td className="px-6 py-3"><div className="font-bold">{bt.name}</div><div className="text-xs" style={{ color: C.muted }}>{bt.sub}</div></td>
                                {keys.map((s) => <td key={s} className="px-2 py-3 text-center"><FitBadge fit={fitFor(bt, s)} /></td>)}
                                <td className="px-4 py-3 font-bold">{styles[bt.best].name}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="grid gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                {questions.map((q) => (
                    <div key={q.q} className="rounded-2xl p-5" style={{ border: `1px solid ${C.line}` }}>
                        <div className="font-bold">{q.q}</div>
                        <p className="mt-2 text-sm" style={{ color: C.sub }}>{q.a}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}
