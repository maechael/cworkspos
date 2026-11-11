import React, { useEffect, useMemo, useState } from 'react';
import { peso, qtyText } from '../../../lib';
import { Loading, OrderPanel, PosFrame, ProductGrid, SearchBox, SkeletonGrid, Thumb, TopBar } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/** Style 05 · Boutique: one product at a time with its stock, "goes well with" suggestions, calm checkout. */
export default function Boutique({ pos, cashier }) {
    const { products, error, loading } = useCatalog(pos.saleNo);
    const [focus, setFocus] = useState(null);
    const lk = useLookup({ ...pos, handleProduct: (p, q) => { setFocus(p); pos.handleProduct(p, q); } }, products);

    // Tapping a line in the order puts that piece on show
    useEffect(() => {
        const line = pos.cart.find((l) => l.id === pos.selected);
        if (line) setFocus(line);
    }, [pos.selected]);

    const current = focus && products ? products.find((p) => p.id === focus.id) || focus : focus;
    const related = useMemo(() => {
        if (!products) return [];
        if (!current) return products.slice(0, 9);
        return products.filter((p) => p.id !== current.id && p.category === current.category).slice(0, 6);
    }, [products, current]);
    const inBag = current && pos.cart.find((l) => l.id === current.id);

    return (
        <PosFrame pos={pos}
            top={(
                <TopBar pos={pos} cashier={cashier}>
                    <SearchBox lk={lk} placeholder="Scan tag or search name, code" onPick={(p) => { setFocus(p); lk.setValue(''); }} />
                </TopBar>
            )}
            order={<OrderPanel pos={pos} subtitle={`${qtyText(pos.itemCount)} piece${pos.itemCount === 1 ? '' : 's'} · ${cashier}`} empty="The bag is empty." />}>
            <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                {!products && !loading ? <Loading error={error} /> : current ? (
                    <div className="pos-card grid gap-6 p-5 md:grid-cols-[minmax(0,240px)_1fr]">
                        <Thumb product={current} size={80} className="mb-0 aspect-square" />
                        <div>
                            <div className="pos-muted text-xs">{current.category || 'Collection'} · <span className="pos-mono">{current.barcode || '—'}</span></div>
                            <h2 className="pos-heading mt-1 text-3xl leading-tight font-semibold">{current.name}</h2>
                            <div className="mt-2 text-2xl font-semibold">{peso(current.price)}{current.allows_decimal && <span className="pos-muted text-sm font-normal"> / {current.unit}</span>}</div>
                            <div className="mt-3 inline-flex rounded-full px-3 py-1 text-sm font-medium" style={current.stock > 0 ? { background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' } : { background: '#FBE9E7', color: '#A12A1C' }}>
                                {current.stock > 0 ? `${qtyText(current.stock)} ${current.unit} in stock` : 'Sold out'}
                            </div>
                            <div className="mt-5 flex flex-wrap gap-2.5">
                                <button type="button" onClick={() => pos.handleProduct(current)} className="pos-btn pos-btn-primary px-7 py-3">{inBag ? 'Add another' : 'Add to bag'}</button>
                                <button type="button" onClick={() => setFocus(null)} className="pos-btn pos-btn-line px-6 py-3">Browse</button>
                            </div>
                            {inBag && <p className="mt-3 text-sm font-medium" style={{ color: 'var(--pos-soft-ink)' }}>{qtyText(inBag.qty)} in the bag</p>}
                        </div>
                    </div>
                ) : (
                    <h2 className="pos-heading text-2xl font-semibold">Today’s pieces</h2>
                )}

                {loading && (
                    <Loading>
                        <div className="pos-heading mt-6 mb-3 text-base font-semibold">{current ? 'Goes well with' : 'Tap a piece to see its stock'}</div>
                        <SkeletonGrid count={9} />
                    </Loading>
                )}
                {products && related.length > 0 && (
                    <>
                        <div className="pos-heading mt-6 mb-3 text-base font-semibold">{current ? 'Goes well with' : 'Tap a piece to see its stock'}</div>
                        <ProductGrid products={products} shown={related} pos={pos} onAdd={setFocus} label="View" />
                    </>
                )}
            </div>
        </PosFrame>
    );
}
