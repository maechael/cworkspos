import React, { useEffect, useMemo, useRef, useState } from 'react';
import { freeQty, lineTotal, peso, promoOwed, qtyText } from '../../lib';

/** Floats over the screen so a scan warning never moves the cards under the cashier's finger. */
export function Notice({ notice }) {
    if (!notice) return null;
    return (
        <div role="status" className={`absolute top-3 left-1/2 z-30 w-max max-w-[90%] -translate-x-1/2 rounded-full px-5 py-2 text-sm font-medium shadow-lg ${notice.type === 'error' ? 'pos-error' : notice.type === 'promo' ? '' : 'pos-warn'}`}
            style={notice.type === 'promo' ? { background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)', border: '1px solid var(--pos-brand)' } : undefined}>
            {notice.text}
        </div>
    );
}

/** The promo or basket under an order line's name: what's free, what's still owed, what's inside. */
export function LineNote({ line, className = 'text-xs' }) {
    if (line.basket) {
        const inside = (line.contents || []).map((c) => `${qtyText(c.qty * line.qty)} ${c.name}`).join(', ');
        return <div className={`pos-muted ${className}`} title={inside}><span className="font-semibold" style={{ color: 'var(--pos-soft-ink)' }}>Basket</span> · {inside}</div>;
    }
    if (!line.promo) return null;
    const free = freeQty(line);
    const owed = promoOwed(line);
    return (
        <div className={`font-medium ${className}`} style={{ color: 'var(--pos-soft-ink)' }}>
            {line.promo.label}
            {free > 0 && ` · ${qtyText(free)} free, −${peso(free * line.price)}`}
            {owed > 0 && ` · scan ${owed} more, free`}
        </div>
    );
}

/** A small tag on product cards and lists. */
export function PromoTag({ product, className = '' }) {
    const text = product.basket ? 'Basket' : product.promo?.label;
    if (!text) return null;
    return <span className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold leading-tight whitespace-nowrap ${className}`} style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}>{text}</span>;
}

/** How long the Undo toast stays up. It stops counting while the pointer or keyboard is on it. */
const UNDO_MS = 8000;

function UndoToastBody({ pos, className }) {
    const [paused, setPaused] = useState(false);
    const left = useRef(UNDO_MS);

    useEffect(() => {
        if (paused) return undefined;
        const started = Date.now();
        const timer = setTimeout(pos.dismissUndo, left.current);
        return () => { clearTimeout(timer); left.current -= Date.now() - started; };
    }, [paused]);

    return (
        <div role="status" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocus={() => setPaused(true)} onBlur={() => setPaused(false)}
            className={`pos-toast absolute z-30 flex w-max max-w-[92%] items-center gap-2 overflow-hidden rounded-2xl py-2 pr-2 pl-4 ${className}`}>
            <span className="min-w-0 truncate text-sm font-medium">{pos.undo.text}</span>
            <button type="button" onClick={pos.undoLast} className="pos-toast-undo ml-2">
                Undo <span className="pos-mono hidden text-xs font-normal opacity-80 lg:inline">Ctrl+Z</span>
            </button>
            <button type="button" onClick={pos.dismissUndo} aria-label="Dismiss" className="pos-toast-close">✕</button>
            <span aria-hidden="true" className="pos-toast-time" style={{ animationDuration: `${UNDO_MS}ms`, animationPlayState: paused ? 'paused' : 'running' }} />
        </div>
    );
}

/** Offers to bring back what was just removed. The key restarts the countdown for each new removal. */
export function UndoToast({ pos, className = '' }) {
    if (!pos.undo) return null;
    return <UndoToastBody key={pos.undo.id} pos={pos} className={className} />;
}

/** A grey placeholder block. Rounded corners and size come from the classes passed in. */
export function Bone({ className = '' }) {
    return <span aria-hidden="true" className={`pos-skel block ${className}`} />;
}

/** The product card's shape, line for line, so nothing jumps when the real cards arrive. */
export function SkeletonCard({ large, compact }) {
    return (
        <div aria-hidden="true" className="pos-product pointer-events-none">
            <span className="pos-thumb pos-skel" />
            <span className={`flex w-full flex-col items-center justify-center gap-1.5 ${compact ? 'min-h-8' : large ? 'min-h-12' : 'min-h-10'}`}>
                <Bone className="h-2.5 w-4/5" />
                <Bone className="h-2.5 w-1/2" />
            </span>
            <span className={`flex items-center ${compact ? 'h-5' : large ? 'h-7' : 'h-6'}`}><Bone className="h-3.5 w-14" /></span>
            {!compact && (
                <>
                    <span className="flex h-4 items-center"><Bone className="h-2.5 w-16" /></span>
                    <Bone className={`mt-1 w-full max-w-32 rounded-full ${large ? 'h-11' : 'h-8'}`} />
                </>
            )}
        </div>
    );
}

export function SkeletonGrid({ large, count = 18 }) {
    return <div className={`pos-grid ${large ? 'large' : ''}`}>{Array.from({ length: count }, (_, i) => <SkeletonCard key={i} large={large} />)}</div>;
}

/** Shows the placeholders passed as children while products load, or the error if they couldn't load. */
export function Loading({ error, className = '', children }) {
    if (error) return <div className="pos-muted p-8 text-center text-sm">{error}</div>;
    return (
        <div role="status" className={className}>
            <span className="sr-only">Loading products…</span>
            {children}
        </div>
    );
}

/** Appears in the top bar only while the cashier's menu is hidden (see app.css). The layout's script handles the click. */
export function NavRestore({ onBrand }) {
    return (
        <button type="button" data-pos-nav-set="open" aria-label="Show the menu" title="Show the menu (Ctrl+B)" className={`pos-icon-btn pos-nav-restore h-10 w-10 ${onBrand ? 'on-brand' : ''}`}>
            <Icon name="menu" size={22} />
        </button>
    );
}

const PATHS = {
    menu: 'M4 6h16M4 12h16M4 18h16',
    cup: 'M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5V9Zm11 1h1.5a2.5 2.5 0 0 1 0 5H16M8 3v3M12 3v3',
    iced: 'M6 7h12l-1.4 13H7.4L6 7Zm-1 0h14M10 3l1 4',
    leaf: 'M5 19C5 10 11 5 19 5c0 8-5 14-14 14Zm0 0 7-7',
    bread: 'M4 12a8 5 0 0 1 16 0v6H4v-6Zm4-3v4m4-5v5m4-4v4',
    burger: 'M4 10a8 5 0 0 1 16 0H4Zm0 3h16M5 16h14a0 0 0 0 1 0 0v1a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-1Z',
    fries: 'M6 10h12l-1.5 10h-9L6 10Zm2 0V4m3 6V3m3 7V4m3 6V5',
    drumstick: 'M14 4a6 6 0 0 1 3 11l-4-1-1-4A6 6 0 0 1 14 4Zm-2 10-6 6m-1-3 3 3',
    bottle: 'M10 3h4v3l2 3v11a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V9l2-3V3Zm-2 9h8',
    can: 'M7 5a5 2 0 0 1 10 0v14a5 2 0 0 1-10 0V5Zm0 0a5 2 0 0 0 10 0',
    rice: 'M4 11h16a8 8 0 0 1-16 0Zm4-3c0-2 2-3 4-3s4 1 4 3',
    soap: 'M5 10h14v9a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-9Zm3 0V7h8v3m-4-3V3',
    snack: 'M7 3h10l-1 18H8L7 3Zm1 5h8m-8 8h8',
    ice: 'M12 3v18M4 8l16 8M4 16l16-8',
    tag: 'M3 12V4h8l10 10-8 8L3 12Zm5-4h.01',
    box: 'M4 8l8-4 8 4v8l-8 4-8-4V8Zm0 0 8 4 8-4m-8 4v8',
    grid: 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z',
    search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm9 2-4-4',
    scan: 'M3 7V4h3M21 7V4h-3M3 17v3h3M21 17v3h-3M7 8v8M10 8v8M13 8v8M16 8v8',
    bell: 'M6 17v-6a6 6 0 1 1 12 0v6l1.5 2h-15L6 17Zm4 4.5h4',
    trash: 'M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5',
    bag: 'M5 8h14l1 13H4L5 8Zm4 0V6a3 3 0 0 1 6 0v2',
    user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7.5 9a7.5 7.5 0 0 1 15 0',
    users: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5a6.5 6.5 0 0 1 3.5 5.5',
};

const ICON_RULES = [
    [/frozen/, 'drumstick'], [/\bice\b|iced|cold/, 'iced'], [/coffee|latte|mocha|espresso|americano|cafe|café/, 'cup'], [/\btea\b|matcha/, 'leaf'],
    [/bread|pastry|bakery|ensaymada|croissant|pandesal|cake/, 'bread'], [/burger|sandwich/, 'burger'], [/fries/, 'fries'],
    [/canned|sardine|corned|tuna|loaf/, 'can'], [/chicken|meat|hotdog|nugget|pork|beef/, 'drumstick'],
    [/beverage|water|cola|drink|juice|soda|milk/, 'bottle'], [/rice|noodle|pancit|mami/, 'rice'], [/toilet|soap|shampoo|detergent|laundry/, 'soap'],
    [/snack|chips|cracker|candy/, 'snack'], [/condiment|sauce|vinegar|oil/, 'bottle'],
];

/** Picks a line icon for a product. The category decides first, so Instant Mami Chicken stays a noodle. */
export function iconFor(p) {
    const find = (text) => ICON_RULES.find(([re]) => re.test(text.toLowerCase()))?.[1];
    return find(p.category || '') || find(p.name) || 'box';
}

export function Icon({ name, size = 24, color = 'currentColor', stroke = 1.8 }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="shrink-0">
            <path d={PATHS[name] || PATHS.box} />
        </svg>
    );
}

/** Soft background and dark icon color per category. They stand in for product photos, so aisles stay easy to tell apart. */
const TINTS = [
    { bg: '#E3F4EA', ink: '#0F6B45' }, { bg: '#FBF3CF', ink: '#6F5300' }, { bg: '#FDE9DC', ink: '#8F4318' }, { bg: '#E1F0FA', ink: '#1C5E8A' },
    { bg: '#FBE6EA', ink: '#94294A' }, { bg: '#F3EDE1', ink: '#65502F' }, { bg: '#EEE8F8', ink: '#56408F' },
];
export function tintFor(name = '') {
    let h = 0;
    for (const ch of name) h = (h * 37 + ch.charCodeAt(0)) % 997;
    return TINTS[h % TINTS.length];
}

function firstName(name = '') {
    return name.split(/\s+/)[0];
}

function initials(name = '') {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

const inCategory = (p, category) => category === 'All' || (p.category || 'Others') === category;
export const filterCategory = (products, category) => (products || []).filter((p) => inCategory(p, category));

/** − qty + for the selected line. */
export function Stepper({ pos, line }) {
    return (
        <div className="pos-stepper flex items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
            <button type="button" onClick={() => pos.step(line, -1)} aria-label={`Less ${line.name}`}>−</button>
            <input data-qty={line.id} type="number" min="0" step={line.allows_decimal ? '0.01' : '1'} value={line.qty} aria-label={`Quantity of ${line.name}`}
                onChange={(e) => pos.setQty(line.id, parseFloat(e.target.value) || 0)}
                onKeyDown={(e) => e.key === 'Enter' && pos.focusScan()} />
            <button type="button" onClick={() => pos.step(line, 1)} aria-label={`More ${line.name}`}>+</button>
        </div>
    );
}

/** Search and scan box. The list under it works with the arrow keys and Enter (see useLookup). */
export function SearchBox({ lk, placeholder, big = false, disabled, onKeyDown, status, onPick, icon }) {
    const pick = onPick || lk.pick;
    const input = lk.inputProps;
    return (
        <div className="relative min-w-0 flex-1">
            <label className={`pos-field flex items-center gap-3 px-4 ${big ? 'py-3' : 'py-2.5'}`}>
                <Icon name={icon || (big ? 'scan' : 'search')} size={big ? 26 : 20} color={big ? 'var(--pos-brand)' : 'var(--pos-muted)'} />
                <input {...input} disabled={disabled ?? input.disabled} onKeyDown={onKeyDown || input.onKeyDown} placeholder={placeholder} aria-label={placeholder}
                    className={big ? 'text-xl' : 'text-sm'} />
                {status}
            </label>
            {lk.matches.length > 0 && (
                <ul className="pos-card absolute z-30 mt-1.5 max-h-80 w-full overflow-auto p-1.5">
                    {lk.matches.map((p, i) => (
                        <li key={p.id}>
                            <button type="button" onMouseDown={(e) => { e.preventDefault(); pick(p); }} onMouseEnter={() => lk.setActive(i)}
                                className="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm"
                                style={i === lk.active ? { background: 'var(--pos-soft)' } : undefined}>
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{p.name}</span>
                                    <span className="pos-muted pos-mono block truncate text-xs">{p.barcode} <PromoTag product={p} className="ml-1 font-sans" /></span>
                                </span>
                                <span className="shrink-0 text-right">
                                    <span className="block font-semibold">{peso(p.price)}</span>
                                    <span className="pos-muted block text-xs">{stockText(p)}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Bell in the top bar. Each sale on hold is listed here and comes back with one tap. */
function HeldBell({ pos }) {
    const [open, setOpen] = useState(false);
    const count = pos.held.length;
    const close = () => { setOpen(false); pos.focusScan(); };
    const totalOf = (sale) => sale.cart.reduce((s, l) => s + lineTotal(l), 0);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => e.key === 'Escape' && close();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    return (
        <div className="relative shrink-0">
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} aria-label={`Sales on hold: ${count}`} className="pos-icon-btn relative h-10 w-10">
                <Icon name="bell" size={22} />
                {count > 0 && <span className="pos-count" style={{ top: -2, right: -2, height: 20, minWidth: 20 }}>{count}</span>}
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-30" onClick={close} />
                    <div className="pos-card absolute right-0 z-40 mt-2 w-72 p-2 text-sm">
                        <div className="pos-muted px-3 py-1.5 text-xs font-medium">Sales on hold</div>
                        {!count && <p className="px-3 pt-1 pb-3">No sales on hold.</p>}
                        {pos.held.map((sale) => (
                            <button key={sale.id} type="button" onClick={() => { pos.resume(sale.id); setOpen(false); }}
                                className="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left hover:bg-[var(--pos-soft)]">
                                <span>
                                    <span className="block font-medium">{sale.cart.length} line{sale.cart.length === 1 ? '' : 's'}</span>
                                    <span className="pos-muted block text-xs">Held at {sale.at.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}</span>
                                </span>
                                <span className="font-semibold">{peso(totalOf(sale))}</span>
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

function useClock() {
    const fmt = () => new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    const [t, setT] = useState(fmt);
    useEffect(() => {
        const id = setInterval(() => setT(fmt()), 15000);
        return () => clearInterval(id);
    }, []);
    return t;
}

/** Search on the left; the bell, clock and greeting on the right. */
export function TopBar({ pos, cashier, children, showHeld = true }) {
    const now = useClock();
    return (
        <header className="pos-topbar relative z-20 flex items-center gap-3 px-4 py-3 sm:gap-4">
            <NavRestore />
            {children}
            <span className="pos-muted hidden text-sm whitespace-nowrap xl:block">{now}</span>
            {showHeld && <HeldBell pos={pos} />}
            <div className="hidden items-center gap-2.5 sm:flex">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold" style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}>{initials(cashier)}</span>
                <span className="text-sm leading-tight whitespace-nowrap">
                    <span className="pos-muted block text-xs">Hello,</span>
                    <span className="block max-w-28 truncate font-semibold">{firstName(cashier)}</span>
                </span>
            </div>
        </header>
    );
}

/** The row of icon tiles above the products. */
export function CategoryTiles({ categories, value, onChange, loading }) {
    return (
        <div className="flex shrink-0 gap-2.5 overflow-x-auto p-0.5 pb-1">
            {['All', ...categories].map((c) => (
                <button key={c} type="button" onClick={() => onChange(c)} aria-pressed={value === c} className={`pos-tile ${value === c ? 'active' : ''}`}>
                    <Icon name={c === 'All' ? 'grid' : iconFor({ category: c, name: '' })} size={24} />
                    <span className="line-clamp-2">{c}</span>
                </button>
            ))}
            {loading && Array.from({ length: 7 }, (_, i) => (
                <span key={i} aria-hidden="true" className="pos-tile"><Bone className="h-6 w-6 rounded-full" /><Bone className="h-2.5 w-12" /></span>
            ))}
        </div>
    );
}

export function CategoryChips({ categories, value, onChange, loading }) {
    return (
        <div className="flex shrink-0 gap-2 overflow-x-auto p-0.5 pb-1">
            {['All', ...categories].map((c) => (
                <button key={c} type="button" onClick={() => onChange(c)} aria-pressed={value === c} className={`pos-chip ${value === c ? 'active' : ''}`}>{c}</button>
            ))}
            {loading && Array.from({ length: 7 }, (_, i) => (
                <span key={i} aria-hidden="true" className="pos-chip"><Bone className="my-1 h-3 w-16" /></span>
            ))}
        </div>
    );
}

export function Thumb({ product, size = 40, className = '', children }) {
    const tint = tintFor(product.category || product.name);
    return (
        <span className={`pos-thumb ${className}`} style={{ background: tint.bg }}>
            <Icon name={iconFor(product)} size={size} color={tint.ink} stroke={1.5} />
            {children}
        </span>
    );
}

function stockText(p) {
    if (p.stock <= 0) return p.basket ? 'Items ran out' : 'Out of stock';
    if (p.basket) return `Stock for ${qtyText(p.stock)}`;
    return `${qtyText(p.stock)} ${p.unit || ''} left`.replace(/\s+/g, ' ');
}

/** Icon, name, price and the green "+ Add" pill. The whole card is the button. */
export function ProductCard({ product: p, qty = 0, onAdd, disabled, large, label = '+ Add' }) {
    const low = p.stock > 0 && p.reorder > 0 && p.stock <= p.reorder;
    return (
        <button type="button" disabled={disabled} onClick={() => onAdd(p)} aria-label={`${label === '+ Add' ? 'Add' : label} ${p.name}, ${peso(p.price)}`}
            className={`pos-product ${qty > 0 ? 'in-cart' : ''} ${p.stock <= 0 ? 'sold-out' : ''}`}>
            <Thumb product={p} size={large ? 56 : 38}>
                <PromoTag product={p} className="absolute top-1.5 left-1.5 shadow-sm" />
                {qty > 0 && <span className="pos-count">{qtyText(qty)}</span>}
            </Thumb>
            <span className={`line-clamp-2 font-medium leading-snug ${large ? 'min-h-12 text-base' : 'min-h-10 text-sm'}`}>{p.name}</span>
            <span className={`font-semibold ${large ? 'text-xl' : 'text-base'}`}>{peso(p.price)}{p.allows_decimal && <span className="pos-muted text-xs font-normal"> / {p.unit}</span>}</span>
            <span className={`text-xs ${p.stock <= 0 ? 'pos-danger-text font-medium' : low ? 'pos-warn-text font-medium' : 'pos-muted'}`}>{stockText(p)}</span>
            <span className={`pos-add ${large ? 'py-2.5 text-base' : ''}`}>{label}</span>
        </button>
    );
}

export function ProductGrid({ products, shown, error, pos, onAdd, disabled, large, label, empty = 'No products here yet.' }) {
    const inCart = useMemo(() => Object.fromEntries(pos.cart.map((l) => [l.id, l.qty])), [pos.cart]);
    if (!products) return <Loading error={error}><SkeletonGrid large={large} /></Loading>;
    if (!shown.length) return <p className="pos-muted p-8 text-center text-sm">{empty}</p>;
    return (
        <div className={`pos-grid ${large ? 'large' : ''}`}>
            {shown.map((p) => <ProductCard key={p.id} product={p} qty={inCart[p.id] || 0} onAdd={onAdd || pos.handleProduct} disabled={disabled} large={large} label={label} />)}
        </div>
    );
}

/** Put the returned ref on a scrolling list so the newest scan stays in view. */
export function useNewestInView(count) {
    const list = useRef();
    useEffect(() => { list.current?.scrollTo({ top: list.current.scrollHeight }); }, [count]);
    return list;
}

/** The order lines. The quantity sits in the badge; the selected line opens its − + control. */
export function OrderLines({ pos, empty }) {
    const list = useNewestInView(pos.cart.length);

    return (
        <div ref={list} className="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2.5">
            {!pos.cart.length && (
                <div className="pos-muted flex flex-col items-center gap-3 px-5 py-12 text-center text-sm">
                    <span className="flex h-14 w-14 items-center justify-center rounded-full" style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}><Icon name="bag" size={26} /></span>
                    {empty}
                </div>
            )}
            {pos.cart.map((l) => {
                const on = pos.selected === l.id;
                return (
                    <div key={l.id} onClick={() => pos.setSelected(l.id)} className={`pos-line ${on ? 'active' : ''}`}>
                        <div className="flex items-start gap-2.5">
                            <span className="pos-qty">{qtyText(l.qty)}</span>
                            <div className="min-w-0 flex-1">
                                <div className="text-sm leading-snug font-medium">{l.name}</div>
                                <div className="pos-muted text-xs">{peso(l.price)} / {l.unit}</div>
                                <LineNote line={l} />
                                {l.qty > l.stock && <div className="pos-warn-text text-xs font-medium">Only {qtyText(l.stock)} {l.unit} on record</div>}
                            </div>
                            <span className="text-sm font-semibold whitespace-nowrap">{peso(lineTotal(l))}</span>
                            <button type="button" aria-label={`Remove ${l.name}`} className="pos-icon-btn -mt-1 -mr-1 h-7 w-7" onClick={(e) => { e.stopPropagation(); pos.remove(l.id); }}><Icon name="trash" size={16} /></button>
                        </div>
                        {on && <div className="mt-2 pl-8"><Stepper pos={pos} line={l} /></div>}
                    </div>
                );
            })}
        </div>
    );
}

/** Items, discount note and the big total. */
export function OrderSummary({ pos, discountNote = 'Set at payment' }) {
    return (
        <div>
            <div className="pos-heading text-sm font-semibold">Summary</div>
            <dl className="mt-1.5 space-y-1 text-sm">
                <div className="flex justify-between"><dt className="pos-muted">Items</dt><dd>{qtyText(pos.itemCount)}</dd></div>
                <div className="flex justify-between"><dt className="pos-muted">Discount</dt><dd className="pos-muted">{discountNote}</dd></div>
            </dl>
            <div className="pos-rule mt-2.5 flex items-baseline justify-between border-t pt-2.5">
                <span className="pos-heading text-base font-semibold">Total</span>
                <span className="pos-heading text-3xl font-bold tracking-tight">{peso(pos.subtotal)}</span>
            </div>
        </div>
    );
}

export function HoldButton({ pos, className = '' }) {
    if (!pos.cart.length && pos.held.length) {
        return <button type="button" onClick={() => pos.resume(pos.held[0].id)} className={`pos-btn pos-btn-soft py-3 ${className}`}>Resume held sale ({pos.held.length})</button>;
    }
    return <button type="button" disabled={!pos.cart.length} onClick={pos.hold} className={`pos-btn pos-btn-soft py-3 ${className}`}>Hold sale</button>;
}

export function PayButton({ pos, label, hint = true, className = '' }) {
    return (
        <button type="button" disabled={!pos.canPay} onClick={() => pos.setPaying(true)} className={`pos-btn pos-btn-primary py-3.5 text-lg ${className}`}>
            {label || `Pay ${peso(pos.subtotal)}`}
            {hint && <span className="hidden text-xs font-medium opacity-80 xl:inline">{pos.payKey}</span>}
        </button>
    );
}

/** The order side of the screen, shared by the styles. */
export function OrderPanel({ pos, eyebrow = 'Current order', title = 'Walk-in customer', subtitle, tag, icon = 'user', empty = 'Scan an item or tap + Add to start.', discountNote, actions, payLabel, payHint }) {
    return (
        <>
            <div className="flex items-start justify-between gap-3 px-5 pt-5 pb-3">
                <div className="min-w-0">
                    <div className="flex items-baseline gap-2">
                        <span className="pos-heading text-sm font-semibold">{eyebrow}</span>
                        {pos.cart.length > 0 && <button type="button" onClick={pos.clearOrder} className="pos-muted text-xs underline">Clear</button>}
                    </div>
                    <div className="mt-2 truncate text-xl font-semibold">{title}</div>
                    {subtitle && <div className="pos-muted truncate text-xs">{subtitle}</div>}
                </div>
                {tag || <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full" style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}><Icon name={icon} size={22} /></span>}
            </div>
            <OrderLines pos={pos} empty={empty} />
            <div className="pos-rule space-y-3 border-t px-5 pt-3 pb-5">
                <OrderSummary pos={pos} discountNote={discountNote} />
                {actions === undefined ? <HoldButton pos={pos} className="w-full" /> : actions}
                <PayButton pos={pos} label={payLabel} hint={payHint} className="w-full" />
            </div>
        </>
    );
}

/**
 * Screen frame for the styles: top bar, main area, order on the right. On phones and small tablets the
 * order folds into a bar at the bottom so the total and Pay stay in reach.
 */
export function PosFrame({ pos, top, order, footer, children, orderWidth = 'lg:grid-cols-[minmax(0,1fr)_340px] xl:grid-cols-[minmax(0,1fr)_380px]' }) {
    const [sheet, setSheet] = useState(false);
    useEffect(() => { if (!pos.cart.length) setSheet(false); }, [pos.cart.length]);

    return (
        <div className="relative flex h-full flex-col overflow-hidden">
            {top}
            <div className={`grid min-h-0 flex-1 ${orderWidth}`}>
                <section className="relative flex min-h-0 min-w-0 flex-col">
                    <Notice notice={pos.notice} />
                    {!sheet && <UndoToast pos={pos} className="bottom-4 left-1/2 -translate-x-1/2" />}
                    {children}
                </section>
                <aside className={`pos-order min-h-0 flex-col ${sheet ? 'fixed inset-0 z-40 flex' : 'hidden lg:flex'}`}>
                    {sheet && <button type="button" onClick={() => setSheet(false)} className="pos-btn pos-btn-line mx-5 mt-4 py-2 text-sm lg:hidden">‹ Back to products</button>}
                    {/* Near the top here, because Pay sits at the bottom of the phone's order sheet */}
                    {sheet && <UndoToast pos={pos} className="top-16 left-1/2 -translate-x-1/2" />}
                    {order}
                </aside>
            </div>
            {footer}
            <div className="pos-topbar flex items-center justify-between gap-3 border-t px-4 py-3 lg:hidden" style={{ borderBottom: 0 }}>
                <div>
                    <div className="pos-muted text-xs">{itemsText(pos.itemCount)}</div>
                    <div className="pos-heading text-xl font-bold">{peso(pos.subtotal)}</div>
                </div>
                <button type="button" disabled={!pos.cart.length} onClick={() => setSheet(true)} className="pos-btn pos-btn-primary px-6 py-3">View order</button>
            </div>
        </div>
    );
}

export const money = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const itemsText = (n) => `${qtyText(n)} item${n === 1 ? '' : 's'}`;
