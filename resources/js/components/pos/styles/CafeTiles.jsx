import React, { useMemo, useState } from 'react';
import { CategoryTiles, OrderPanel, PosFrame, ProductGrid, SearchBox, TopBar, filterCategory, itemsText } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/** Style 02 · Café Tiles: tap-to-add product cards under category tiles, with the order on the right. */
export default function CafeTiles({ pos, cashier }) {
    const { products, categories, error, loading } = useCatalog(pos.saleNo);
    const [category, setCategory] = useState('All');
    const lk = useLookup(pos, products);
    const shown = useMemo(() => [...filterCategory(products, category)].sort((a, b) => a.name.localeCompare(b.name)), [products, category]);

    return (
        <PosFrame pos={pos}
            top={<TopBar pos={pos} cashier={cashier}><SearchBox lk={lk} placeholder="Search a product or scan a barcode…" /></TopBar>}
            order={<OrderPanel pos={pos} subtitle={`${itemsText(pos.itemCount)} · ${cashier}`} />}>
            <div className="pos-scroll-page gap-3 p-4 sm:p-5">
                <h2 className="pos-heading text-lg font-semibold">Categories</h2>
                <CategoryTiles categories={categories} value={category} onChange={setCategory} loading={loading} />
                <h2 className="pos-heading mt-1 text-base font-semibold">{category === 'All' ? 'All products' : category}</h2>
                <div className="pos-scroll-list">
                    <ProductGrid products={products} shown={shown} error={error} pos={pos} />
                </div>
            </div>
        </PosFrame>
    );
}
