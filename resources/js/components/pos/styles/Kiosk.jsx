import React, { useMemo, useState } from 'react';
import { CategoryTiles, NavRestore, OrderPanel, PosFrame, ProductGrid, SearchBox, filterCategory, itemsText } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/** Style 04 · Self-Order Kiosk: customer-facing. Huge cards, category tiles, one clear Pay step. */
export default function Kiosk({ pos, storeName }) {
    const { products, categories, error, loading } = useCatalog(pos.saleNo);
    const [category, setCategory] = useState('All');
    const lk = useLookup(pos, products);
    const shown = useMemo(() => filterCategory(products, category), [products, category]);

    return (
        <PosFrame pos={pos}
            top={(
                <header className="relative z-20 px-6 py-5" style={{ background: 'var(--pos-brand)', color: 'var(--pos-on-brand)' }}>
                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                        <div className="flex items-center gap-3">
                            <NavRestore onBrand />
                            <div>
                                <div className="text-sm font-medium opacity-90">{storeName} · Order here</div>
                                <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">What are you craving?</h1>
                            </div>
                        </div>
                        <div className="flex w-full max-w-xs" style={{ color: 'var(--pos-text)' }}><SearchBox lk={lk} placeholder="Search or scan" /></div>
                    </div>
                </header>
            )}
            order={(
                <OrderPanel pos={pos} eyebrow="Your order" title={itemsText(pos.itemCount)} icon="bag"
                    empty="Your tray is empty. Tap something tasty!" actions={null} payHint={false} />
            )}>
            <div className="pos-scroll-page gap-4 p-5">
                <CategoryTiles categories={categories} value={category} onChange={setCategory} loading={loading} />
                <div className="pos-scroll-list">
                    <ProductGrid large products={products} shown={shown} error={error} pos={pos} />
                </div>
            </div>
        </PosFrame>
    );
}
