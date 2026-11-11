import React, { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';

function Label({ product }) {
    const svg = useRef();
    useEffect(() => {
        JsBarcode(svg.current, product.barcode, { format: 'CODE128', height: 40, width: 1.6, fontSize: 12, margin: 0 });
    }, [product.barcode]);
    return (
        <div className="flex break-inside-avoid flex-col items-center justify-center rounded border border-dashed border-slate-300 p-2 text-center">
            <div className="line-clamp-2 text-[11px] font-semibold leading-tight">{product.name}</div>
            <svg ref={svg} className="mt-1 max-w-full" />
            <div className="text-sm font-bold">₱{Number(product.price).toFixed(2)}</div>
        </div>
    );
}

export default function BarcodeLabels({ products, copies }) {
    const labels = products.flatMap((p) => Array.from({ length: copies }, (_, i) => ({ ...p, key: `${p.id}-${i}` })));
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 print:grid-cols-4">
            {labels.map((p) => <Label key={p.key} product={p} />)}
        </div>
    );
}
