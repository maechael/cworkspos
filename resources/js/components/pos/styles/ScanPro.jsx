import React, { useEffect, useMemo, useState } from 'react';
import { beep, errorMessage, lineTotal, lookupBarcode, peso, qtyText, scanQty } from '../../../lib';
import { CategoryChips, Icon, LineNote, PosFrame, ProductGrid, PromoTag, SearchBox, TopBar, filterCategory, itemsText, money, useNewestInView } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/** Style 01 · Scan Pro: barcode-first lane. Scans land in the order table, with a key for every button. */
export default function ScanPro({ pos, cashier }) {
    const { products, categories, error, loading } = useCatalog(pos.saleNo);
    const [category, setCategory] = useState('All');
    const [priceCheck, setPriceCheck] = useState(false);
    const [checked, setChecked] = useState(null);
    // A code the list doesn't have, such as a box barcode, is looked up too. A price check must never add to the sale.
    const checkCode = async (code) => {
        setPriceCheck(false);
        try {
            setChecked(await lookupBarcode(code, { baskets: true }));
        } catch (err) {
            beep(false);
            pos.flash('error', errorMessage(err));
        }
    };
    const lookupPos = priceCheck ? { ...pos, handleProduct: (p) => { setChecked(p); setPriceCheck(false); }, onScan: checkCode } : pos;
    const lk = useLookup(lookupPos, products);
    const shown = useMemo(() => filterCategory(products, category), [products, category]);
    const list = useNewestInView(pos.cart.length);
    const last = pos.cart[pos.cart.length - 1];

    const focusQty = () => {
        const id = pos.selected || last?.id;
        if (id) document.querySelector(`[data-qty="${id}"]`)?.select();
    };
    const startPriceCheck = () => { setPriceCheck(true); setChecked(null); pos.focusScan(); };

    useEffect(() => {
        const onKey = (e) => {
            const keys = {
                F3: focusQty,
                F4: startPriceCheck,
                F5: () => pos.held[0] && pos.resume(pos.held[0].id),
                F6: () => pos.canPay && pos.setPaying(true),
                F8: pos.hold,
                F9: pos.voidSelected,
            };
            if (keys[e.key] && !pos.busy) { e.preventDefault(); keys[e.key](); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const onKeyDown = (e) => {
        // Enter on an empty scan box pays
        if (e.key === 'Enter' && !lk.value && pos.canPay) { e.preventDefault(); pos.setPaying(true); return; }
        lk.inputProps.onKeyDown(e);
    };

    const fn = (title, key, onClick, disabled = false, tone = '') => (
        <button type="button" onClick={onClick} disabled={disabled} className={`pos-btn pos-btn-line flex-col gap-0 rounded-xl py-1.5 text-sm ${tone}`}>
            {title}
            <span className="pos-muted pos-mono text-xs font-normal">{key}</span>
        </button>
    );

    const status = (
        <span className={`hidden shrink-0 items-center gap-2 text-sm font-medium sm:flex ${priceCheck ? 'pos-warn-text' : ''}`} style={priceCheck ? undefined : { color: 'var(--pos-soft-ink)' }}>
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: priceCheck ? 'currentColor' : 'var(--pos-brand)' }} />
            {priceCheck ? 'Price check' : 'Scanner ready'}
        </span>
    );

    const order = (
        <>
            <div className="flex items-center justify-between gap-3 px-5 pt-4 pb-3">
                <div className="min-w-0">
                    <div className="pos-heading text-base font-semibold">Current order</div>
                    <div className="pos-muted truncate text-xs">Lane 1 · {cashier}{pos.held.length > 0 && ` · ${pos.held.length} on hold`}</div>
                </div>
                <span className="pos-muted shrink-0 text-xs">{pos.cart.length} line{pos.cart.length === 1 ? '' : 's'}</span>
            </div>
            <div ref={list} className="min-h-0 flex-1 overflow-auto">
                <table className="w-full text-left text-sm">
                    <thead className="pos-muted sticky top-0 z-10 text-xs" style={{ background: 'var(--pos-panel)' }}>
                        <tr>
                            <th className="py-2 pl-5 font-medium">Item</th>
                            <th className="py-2 text-right font-medium">Qty</th>
                            <th className="py-2 pr-2 pl-3 text-right font-medium">Amount</th>
                            <th className="w-10 pr-3"><span className="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {pos.cart.map((l) => (
                            <tr key={l.id} onClick={() => pos.setSelected(l.id)} className={`pos-table-row ${pos.selected === l.id ? 'active' : ''}`}>
                                <td className="py-2.5 pl-5">
                                    <div className="font-medium leading-snug">{l.name}</div>
                                    <div className={`text-xs ${l.qty > l.stock ? 'pos-warn-text font-medium' : 'pos-muted'}`}>
                                        {l.qty > l.stock ? `Only ${qtyText(l.stock)} ${l.unit} on record` : `${money(l.price)} / ${l.unit}${l.id === last?.id ? ' · just scanned' : ''}`}
                                    </div>
                                    <LineNote line={l} />
                                </td>
                                <td className="py-2.5 text-right whitespace-nowrap">
                                    <input data-qty={l.id} type="number" min="0" step={l.allows_decimal ? '0.01' : '1'} value={l.qty} aria-label={`Quantity of ${l.name}`}
                                        onChange={(e) => pos.setQty(l.id, parseFloat(e.target.value) || 0)}
                                        onKeyDown={(e) => e.key === 'Enter' && pos.focusScan()}
                                        onClick={(e) => e.stopPropagation()} className="pos-qty-input" />
                                </td>
                                <td className="py-2.5 pr-2 pl-3 text-right font-semibold whitespace-nowrap">{money(lineTotal(l))}</td>
                                <td className="pr-3">
                                    <button type="button" aria-label={`Remove ${l.name}`} className="pos-icon-btn h-7 w-7" onClick={(e) => { e.stopPropagation(); pos.remove(l.id); }}><Icon name="trash" size={16} /></button>
                                </td>
                            </tr>
                        ))}
                        {!pos.cart.length && (
                            <tr><td colSpan="4" className="pos-muted px-5 py-12 text-center">
                                <div className="pos-heading text-base font-semibold">Ready for the next customer</div>
                                <div className="mt-1 text-xs">Scan an item, or type a name. Type <b className="pos-mono">3*</b> before a barcode to add 3.</div>
                            </td></tr>
                        )}
                    </tbody>
                </table>
            </div>
            <div className="pos-rule space-y-2.5 border-t px-5 pt-3 pb-4">
                <div className="flex items-baseline justify-between">
                    <span className="pos-heading text-base font-semibold">Total <span className="pos-muted text-xs font-normal">· {itemsText(pos.itemCount)}</span></span>
                    <span className="pos-heading text-4xl font-bold tracking-tight">{peso(pos.subtotal)}</span>
                </div>
                <div className="grid grid-cols-3 gap-2">
                    {fn('Qty', 'F3', focusQty, !pos.cart.length)}
                    {fn('Price check', 'F4', startPriceCheck)}
                    {fn(pos.held.length ? `Resume (${pos.held.length})` : 'Resume', 'F5', () => pos.held[0] && pos.resume(pos.held[0].id), !pos.held.length)}
                    {fn('Discount', 'F6', () => pos.setPaying(true), !pos.canPay)}
                    {fn('Hold', 'F8', pos.hold, !pos.cart.length)}
                    {fn('Void', 'F9', pos.voidSelected, !pos.selected, 'pos-danger-text')}
                </div>
                <button type="button" disabled={!pos.canPay} onClick={() => pos.setPaying(true)} className="pos-btn pos-btn-primary w-full py-3.5 text-lg">
                    Pay {peso(pos.subtotal)}
                    <span className="pos-mono text-xs font-medium opacity-80">F12 · Enter</span>
                </button>
            </div>
        </>
    );

    return (
        <PosFrame pos={pos} order={order} orderWidth="lg:grid-cols-[minmax(0,1fr)_380px] xl:grid-cols-[minmax(0,1fr)_440px]"
            top={(
                <TopBar pos={pos} cashier={cashier}>
                    <SearchBox big lk={lk} onKeyDown={onKeyDown} status={status} placeholder={priceCheck ? 'Price check: scan or type an item…' : 'Scan barcode or type a name…'} />
                </TopBar>
            )}>
            <div className="pos-scroll-page gap-3 p-4">
                {checked && (
                    <div className="pos-card flex items-center justify-between gap-3 px-4 py-3">
                        <div className="min-w-0">
                            <div className="truncate font-semibold">{checked.name} <PromoTag product={checked} className="ml-1 align-middle" /></div>
                            <div className="pos-muted text-sm">
                                {checked.basket ? `Stock for ${qtyText(checked.stock)} · bought separately ${peso(checked.regular)}` : `${qtyText(checked.stock)} ${checked.unit} in stock`}
                                {checked.scan_pieces > 1 && ` · ${checked.scan_label || 'This barcode'}: ${qtyText(checked.scan_pieces)} ${checked.unit} per scan`}
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-3">
                            <span className="pos-heading text-2xl font-bold">{peso(checked.price)}</span>
                            <button type="button" className="pos-btn pos-btn-primary px-4 py-1.5 text-sm" onClick={() => { pos.handleProduct(checked, scanQty(checked, null)); setChecked(null); }}>+ Add</button>
                            <button type="button" className="pos-icon-btn h-8 w-8" aria-label="Close price check" onClick={() => setChecked(null)}>✕</button>
                        </div>
                    </div>
                )}
                <CategoryChips categories={categories} value={category} onChange={setCategory} loading={loading} />
                <div className="pos-scroll-list">
                    <ProductGrid products={products} shown={shown} error={error} pos={pos} onAdd={lookupPos.handleProduct} />
                </div>
            </div>
        </PosFrame>
    );
}
