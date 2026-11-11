import React, { useEffect, useMemo, useRef, useState } from 'react';
import { peso, qtyText } from '../../../lib';
import { CategoryTiles, OrderPanel, PosFrame, ProductGrid, SearchBox, TopBar, filterCategory } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

// Floor plan from the design: round 2-seaters, square 4-seaters and one long party table
const FLOOR = [
    { id: 'T1', shape: 'round', seats: 2 }, { id: 'T2', shape: 'round', seats: 2 }, { id: 'T3', shape: 'round', seats: 2 },
    { id: 'T4', shape: 'rect', seats: 4 }, { id: 'T5', shape: 'rect', seats: 4 },
    { id: 'T6', shape: 'rect', seats: 4 }, { id: 'T7', shape: 'rect', seats: 4 },
    { id: 'T8', shape: 'wide', seats: 10 },
    { id: 'T9', shape: 'round', seats: 2 }, { id: 'T10', shape: 'round', seats: 2 },
];
const STORE_KEY = 'pos-open-tables';

// Table states keep the same meaning in every color scheme. Each table also says its state in words.
const STATUS = {
    free: { bg: '#E6F6EC', fg: '#0A5C37', ring: '#34A56F' },
    seated: { bg: '#FDF2D3', fg: '#664600', ring: '#D9A21B' },
    bill: { bg: '#FBE3DE', fg: '#8A2A17', ring: '#D9573C' },
    sel: { bg: 'var(--pos-brand)', fg: 'var(--pos-on-brand)', ring: 'var(--pos-heading)' },
};

function loadTables() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY)) || {}; } catch { return {}; }
}

/**
 * Style 03 · Table Service: starts from the floor plan. Each table keeps its own open order
 * (kept on this device) until it is paid; "Bill out" marks it as waiting for the bill.
 */
export default function TableService({ pos, cashier }) {
    const { products, categories, error, loading } = useCatalog(pos.saleNo);
    const [tables, setTables] = useState(loadTables);
    const [active, setActive] = useState(null);
    const [view, setView] = useState('floor');
    const [category, setCategory] = useState('All');
    const [, tick] = useState(0);
    const lk = useLookup(pos, products);
    const switching = useRef(false);

    useEffect(() => { const id = setInterval(() => tick((n) => n + 1), 30000); return () => clearInterval(id); }, []);
    useEffect(() => { try { localStorage.setItem(STORE_KEY, JSON.stringify(tables)); } catch { /* optional */ } }, [tables]);

    // Keep the open table's order in step with the cart
    useEffect(() => {
        if (!active || switching.current) { switching.current = false; return; }
        setTables((t) => {
            const next = { ...t };
            if (!pos.cart.length) delete next[active];
            else next[active] = { cart: pos.cart, since: t[active]?.since || Date.now(), bill: t[active]?.bill || false };
            return next;
        });
    }, [pos.cart]);

    const open = (id) => {
        if (id === active) return setView('menu');
        switching.current = true;
        setActive(id);
        pos.setCart(tables[id]?.cart || []);
        pos.setSelected(null);
        pos.dismissUndo(); // a line removed from one table must not come back on another
        if (!tables[id]) setView('menu');
    };

    const billOut = () => active && tables[active] && setTables((t) => ({ ...t, [active]: { ...t[active], bill: !t[active].bill } }));

    const status = (id) => (id === active ? 'sel' : !tables[id] ? 'free' : tables[id].bill ? 'bill' : 'seated');
    const minutes = (id) => (tables[id] ? Math.max(1, Math.round((Date.now() - tables[id].since) / 60000)) : 0);
    const shown = useMemo(() => filterCategory(products, category), [products, category]);
    const t = active && tables[active];

    const tab = (id, label) => (
        <button type="button" onClick={() => setView(id)} aria-pressed={view === id} className={`pos-chip ${view === id ? 'active' : ''}`}>{label}</button>
    );
    const takeout = STATUS[status('Takeout')];

    const order = (
        <OrderPanel pos={pos} eyebrow="Current order"
            title={active ? (active === 'Takeout' ? 'Takeout' : `Table ${active.slice(1)}`) : 'No table'}
            subtitle={`Server: ${cashier}`} icon="users"
            tag={t ? (
                <span className="shrink-0 rounded-full px-3 py-1 text-xs font-semibold" style={{ background: STATUS[t.bill ? 'bill' : 'seated'].bg, color: STATUS[t.bill ? 'bill' : 'seated'].fg }}>
                    {t.bill ? 'Bill out' : 'Seated'} · {minutes(active)} min
                </span>
            ) : null}
            empty={active ? 'No items yet. Add from the Menu tab.' : 'Tap a table on the floor plan to open or continue its order.'}
            actions={(
                <div className="grid grid-cols-2 gap-2">
                    <button type="button" disabled={!t} onClick={billOut} className="pos-btn pos-btn-soft py-3 text-sm">{t?.bill ? 'Reopen' : 'Bill out'}</button>
                    <button type="button" onClick={() => setView(view === 'floor' ? 'menu' : 'floor')} className="pos-btn pos-btn-soft py-3 text-sm">{view === 'floor' ? 'Add items' : 'Floor plan'}</button>
                </div>
            )}
            payLabel={`Pay ${active === 'Takeout' ? 'takeout' : active ? `table ${active.slice(1)}` : ''} · ${peso(pos.subtotal)}`} payHint={false} />
    );

    return (
        <PosFrame pos={pos} order={order}
            top={(
                <TopBar pos={pos} cashier={cashier} showHeld={false}>
                    <div className="flex shrink-0 gap-1.5">{tab('floor', 'Floor')}{tab('menu', active ? `Menu · ${active}` : 'Menu')}</div>
                    <SearchBox lk={lk} disabled={pos.busy || !active} placeholder={active ? 'Search menu or scan…' : 'Pick a table first'} />
                </TopBar>
            )}>
            {view === 'floor' ? (
                <div className="min-h-0 flex-1 overflow-auto p-4 sm:p-5">
                    <div className="mb-4 flex flex-wrap gap-4 text-sm">
                        {[['free', 'Free'], ['seated', 'Seated'], ['bill', 'Waiting bill']].map(([s, label]) => (
                            <span key={s} className="flex items-center gap-1.5"><span className="h-3 w-3 rounded" style={{ background: STATUS[s].ring }} />{label}</span>
                        ))}
                    </div>
                    <div className="pos-card p-6 text-center">
                        <div className="mx-auto grid max-w-3xl grid-cols-6 items-center gap-5">
                            {FLOOR.map((f) => {
                                const s = STATUS[status(f.id)];
                                const sub = status(f.id) === 'sel' ? 'Selected' : !tables[f.id] ? `${f.seats} seats` : tables[f.id].bill ? 'Bill out' : `${minutes(f.id)} min`;
                                const shape = f.shape === 'round' ? 'col-span-2 mx-auto aspect-square w-full max-w-32 rounded-full' : f.shape === 'wide' ? 'col-span-6 h-24 rounded-2xl' : 'col-span-3 h-28 rounded-2xl';
                                return (
                                    <button key={f.id} type="button" onClick={() => open(f.id)}
                                        className={`flex flex-col items-center justify-center transition hover:brightness-95 ${shape}`}
                                        style={{ background: s.bg, color: s.fg, border: `2px solid ${s.ring}` }}>
                                        <span className="text-xl font-bold">{f.id}</span>
                                        <span className="text-sm font-medium">{f.id === 'T8' && !tables[f.id] && status(f.id) !== 'sel' ? 'Party of 10' : sub}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <button type="button" onClick={() => open('Takeout')} className="mt-6 rounded-full px-5 py-2.5 text-sm font-semibold"
                            style={{ background: takeout.bg, color: takeout.fg, border: `2px solid ${takeout.ring}` }}>
                            Takeout counter{tables.Takeout ? ` · ${qtyText(tables.Takeout.cart.length)} lines` : ''}
                        </button>
                    </div>
                </div>
            ) : (
                <div className="pos-scroll-page gap-3 p-4 sm:p-5">
                    {!active && (
                        <div className="pos-card px-4 py-3 text-sm">
                            Pick a table on the Floor first, or <button type="button" className="font-semibold underline" style={{ color: 'var(--pos-soft-ink)' }} onClick={() => open('Takeout')}>start a takeout order</button>.
                        </div>
                    )}
                    <CategoryTiles categories={categories} value={category} onChange={setCategory} loading={loading} />
                    <div className="pos-scroll-list">
                        <ProductGrid products={products} shown={shown} error={error} pos={pos} disabled={!active} />
                    </div>
                </div>
            )}
        </PosFrame>
    );
}
