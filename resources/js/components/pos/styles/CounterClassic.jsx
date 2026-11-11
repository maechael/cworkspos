import React, { useEffect } from 'react';
import { lineTotal, peso, qtyText } from '../../../lib';
import { Bone, LineNote, PosFrame, SearchBox, TopBar, money, useNewestInView } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

// Placeholder width for each table column, in order
const SKELETON_COLUMNS = ['w-4', 'w-28', 'w-48', 'ml-auto w-8', 'w-6', 'ml-auto w-12', 'ml-auto w-14'];

/** Style 08 · Counter Classic: dense keyboard workhorse for big catalogs, with an F-key bar. */
export default function CounterClassic({ pos, cashier }) {
    const { products, error, loading } = useCatalog(pos.saleNo);
    const lk = useLookup(pos, products, 6);
    const list = useNewestInView(pos.cart.length);

    const focusQty = () => {
        const id = pos.selected || pos.cart[pos.cart.length - 1]?.id;
        if (id) document.querySelector(`[data-qty="${id}"]`)?.select();
    };

    useEffect(() => {
        const onKey = (e) => {
            const keys = { F3: focusQty, F6: () => pos.canPay && pos.setPaying(true), F7: () => pos.held[0] && pos.resume(pos.held[0].id), F8: pos.hold, F9: pos.voidSelected };
            if (keys[e.key] && !pos.busy) { e.preventDefault(); keys[e.key](); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const fkeys = [
        ['F2', 'Lookup', () => pos.focusScan()], ['F3', 'Qty', focusQty], ['F6', 'Discount', () => pos.canPay && pos.setPaying(true)],
        ['F7', pos.held.length ? `Resume (${pos.held.length})` : 'Resume', () => pos.held[0] && pos.resume(pos.held[0].id)],
        ['F8', 'Hold', pos.hold], ['F9', 'Void line', pos.voidSelected], ['F10', 'Pay', () => pos.canPay && pos.setPaying(true)],
    ];
    const th = 'px-2 py-2.5 font-medium';
    const td = 'px-2 py-2.5';

    const order = (
        <div className="flex min-h-0 flex-1 flex-col px-5 pt-5 pb-5">
            <div className="pos-heading text-sm font-semibold">Summary</div>
            <dl className="mt-2 space-y-1.5 text-sm">
                <div className="flex justify-between"><dt className="pos-muted">Lines</dt><dd className="font-semibold">{pos.cart.length}</dd></div>
                <div className="flex justify-between"><dt className="pos-muted">Items</dt><dd>{qtyText(pos.itemCount)}</dd></div>
                <div className="flex justify-between"><dt className="pos-muted">Discount</dt><dd className="pos-muted">At payment (F6)</dd></div>
            </dl>
            <div className="pos-rule mt-3 border-t pt-3">
                <div className="pos-heading text-sm font-semibold">Amount due</div>
                <div className="pos-heading text-4xl font-bold tracking-tight">{peso(pos.subtotal)}</div>
            </div>
            <div className="flex-1" />
            <div className="space-y-2">
                <button type="button" disabled={!pos.cart.length} onClick={pos.hold} className="pos-btn pos-btn-soft w-full py-3">Hold sale <span className="pos-mono text-xs font-medium">F8</span></button>
                <button type="button" disabled={!pos.held.length} onClick={() => pos.resume(pos.held[0].id)} className="pos-btn pos-btn-line w-full py-3">
                    Resume held{pos.held.length ? ` (${pos.held.length})` : ''} <span className="pos-mono text-xs font-medium">F7</span>
                </button>
                <button type="button" disabled={!pos.canPay} onClick={() => pos.setPaying(true)} className="pos-btn pos-btn-primary w-full py-3.5 text-lg">Pay <span className="pos-mono text-xs font-medium">F10</span></button>
            </div>
        </div>
    );

    return (
        <PosFrame pos={pos} order={order} orderWidth="lg:grid-cols-[minmax(0,1fr)_300px]"
            top={<TopBar pos={pos} cashier={cashier}><SearchBox big lk={lk} placeholder="Scan a barcode, or type a name (F2)…" /></TopBar>}
            footer={(
                <footer className="hidden grid-cols-7 gap-1.5 p-2 lg:grid" style={{ background: 'var(--pos-heading)' }}>
                    {fkeys.map(([key, name, fn]) => (
                        <button key={key} type="button" onClick={fn} className="rounded-lg py-2 text-sm text-white transition hover:bg-white/20" style={{ background: 'rgb(255 255 255 / 0.1)' }}>
                            <b className="pos-mono">{key}</b> {name}
                        </button>
                    ))}
                </footer>
            )}>
            <div ref={list} className="min-h-0 flex-1 overflow-auto" style={{ background: 'var(--pos-surface)' }}>
                {loading && <span role="status" className="sr-only">Loading products…</span>}
                <table className="w-full text-left text-sm">
                    <thead className="pos-muted sticky top-0 z-10 text-xs" style={{ background: 'var(--pos-bg)' }}>
                        <tr>
                            <th className={`w-12 pl-4 ${th}`}>Ln</th><th className={th}>Barcode</th><th className={th}>Description</th>
                            <th className={`text-right ${th}`}>Qty</th><th className={th}>Unit</th><th className={`text-right ${th}`}>Price</th><th className={`pr-4 text-right ${th}`}>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pos.cart.map((l, i) => (
                            <tr key={l.id} onClick={() => pos.setSelected(l.id)} className={`pos-table-row ${pos.selected === l.id ? 'active' : ''}`}>
                                <td className={`pos-muted pos-mono pl-4 ${td}`}>{i + 1}</td>
                                <td className={`pos-mono whitespace-nowrap ${td}`}>{l.barcode}</td>
                                <td className={td}><span className="font-medium">{l.name}</span>{l.qty > l.stock && <span className="pos-warn-text ml-2 text-xs font-semibold">only {qtyText(l.stock)}</span>}<LineNote line={l} /></td>
                                <td className={`text-right ${td}`}>
                                    <input data-qty={l.id} type="number" min="0" step={l.allows_decimal ? '0.01' : '1'} value={l.qty} aria-label={`Quantity of ${l.name}`}
                                        onChange={(e) => pos.setQty(l.id, parseFloat(e.target.value) || 0)} onKeyDown={(e) => e.key === 'Enter' && pos.focusScan()}
                                        onClick={(e) => e.stopPropagation()} className="pos-qty-input" />
                                </td>
                                <td className={td}>{l.unit}</td>
                                <td className={`text-right ${td}`}>{money(l.price)}</td>
                                <td className={`pr-4 text-right font-semibold ${td}`}>{money(lineTotal(l))}</td>
                            </tr>
                        ))}
                        {!pos.cart.length && loading && Array.from({ length: 8 }, (_, row) => (
                            <tr key={row} aria-hidden="true" className="pos-table-row">
                                {SKELETON_COLUMNS.map((width, col) => (
                                    <td key={col} className={`${td} ${col === 0 ? 'pl-4' : ''} ${col === SKELETON_COLUMNS.length - 1 ? 'pr-4' : ''}`}><Bone className={`h-3.5 ${width}`} /></td>
                                ))}
                            </tr>
                        ))}
                        {!pos.cart.length && !loading && (
                            <tr><td colSpan="7" className="pos-muted px-4 py-14 text-center">{!products && error ? error : 'Scan a barcode, or type a few letters and pick an item with the arrow keys.'}</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </PosFrame>
    );
}
