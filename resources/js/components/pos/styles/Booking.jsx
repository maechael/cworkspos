import React, { useMemo } from 'react';
import { peso, qtyText } from '../../../lib';
import { Bone, Loading, OrderPanel, PosFrame, SearchBox, TopBar, filterCategory, tintFor } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/**
 * Style 06 · Booking & Services: a calm day board with soft tinted cards and a big checkout panel.
 * The board has one column per category (services, products) in the design's staff-column look.
 */
export default function Booking({ pos, cashier }) {
    const { products, categories, error } = useCatalog(pos.saleNo);
    const lk = useLookup(pos, products);
    const today = new Date().toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
    const columns = useMemo(() => categories.map((c) => ({ name: c, tint: tintFor(c), items: filterCategory(products, c) })), [categories, products]);
    const inCart = (id) => pos.cart.find((l) => l.id === id);

    return (
        <PosFrame pos={pos}
            top={(
                <TopBar pos={pos} cashier={cashier}>
                    <span className="pos-btn pos-btn-soft hidden shrink-0 px-4 py-2 text-sm md:inline-flex">{today}</span>
                    <SearchBox lk={lk} placeholder="Find a service or product…" />
                </TopBar>
            )}
            order={(
                <OrderPanel pos={pos} eyebrow="Checking out" subtitle={`Served by ${cashier}`} discountNote="Member or senior, at payment"
                    empty="Tap a service or product to add it to the bill." />
            )}>
            <div className="flex min-h-0 flex-1 flex-col p-4 sm:p-5">
                {!products ? (
                    <Loading error={error} className="flex min-h-0 flex-1 gap-4 overflow-hidden pb-2">
                        {Array.from({ length: 5 }, (_, col) => (
                            <div key={col} className="flex w-60 shrink-0 flex-col">
                                <div className="mb-3 flex items-center gap-2.5 px-1">
                                    <Bone className="h-9 w-9 rounded-full" />
                                    <Bone className="h-3.5 w-24" />
                                </div>
                                <div className="pos-card min-h-0 flex-1 space-y-2 overflow-hidden p-2">
                                    {Array.from({ length: 8 }, (_, row) => <Bone key={row} className="h-[68px] w-full rounded-xl" />)}
                                </div>
                            </div>
                        ))}
                    </Loading>
                ) : (
                    <div className="flex min-h-0 flex-1 gap-4 overflow-x-auto pb-2">
                        {columns.map((col) => (
                            <div key={col.name} className="flex w-60 shrink-0 flex-col">
                                <div className="mb-3 flex items-center gap-2.5 px-1">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold" style={{ background: col.tint.bg, color: col.tint.ink }}>{col.name[0]}</span>
                                    <span className="pos-heading text-sm font-semibold">{col.name}</span>
                                    <span className="pos-muted ml-auto text-xs">{col.items.length}</span>
                                </div>
                                <div className="pos-card min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
                                    {col.items.map((p) => {
                                        const on = inCart(p.id);
                                        return (
                                            <button key={p.id} type="button" onClick={() => pos.handleProduct(p)}
                                                className="block w-full rounded-xl px-3.5 py-3 text-left transition hover:brightness-95 active:scale-[0.98]"
                                                style={on ? { background: 'var(--pos-brand)', color: 'var(--pos-on-brand)' } : { background: col.tint.bg, color: col.tint.ink }}>
                                                <span className="block text-sm font-semibold">{p.name}</span>
                                                <span className="block text-sm">{peso(p.price)}{p.allows_decimal ? ` / ${p.unit}` : ''}{on ? ` · ${qtyText(on.qty)} on bill` : ''}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </PosFrame>
    );
}
