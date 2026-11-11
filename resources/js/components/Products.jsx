import React, { useEffect, useRef, useState } from 'react';
import useLive from '../useLive';
import LiveBadge from './LiveBadge';
import Pager from './Pager';
import { baseUrl, peso, qtyText } from '../lib';

/** Product ids whose stock changed since the last refresh. */
function useChangedStock(rows) {
    const prev = useRef(new Map());
    const [changed, setChanged] = useState({});
    useEffect(() => {
        if (!rows) return;
        const diff = {};
        rows.forEach((p) => {
            const before = prev.current.get(p.id);
            if (before !== undefined && before !== p.stock) diff[p.id] = p.stock < before ? 'down' : 'up';
            prev.current.set(p.id, p.stock);
        });
        if (Object.keys(diff).length) {
            setChanged(diff);
            setTimeout(() => setChanged({}), 4000);
        }
    }, [rows]);
    return changed;
}

export default function Products({ categories, filters: start }) {
    const [q, setQ] = useState(start.q || '');
    const [search, setSearch] = useState(start.q || '');
    const [category, setCategory] = useState(start.category || '');
    const [filter, setFilter] = useState(start.filter || '');
    const [page, setPage] = useState(1);
    const [picked, setPicked] = useState(new Set());
    const [copies, setCopies] = useState(1);

    // Wait for typing (or a scan) to finish before searching
    useEffect(() => {
        const t = setTimeout(() => { setSearch(q.trim()); setPage(1); }, 300);
        return () => clearTimeout(t);
    }, [q]);

    const params = { q: search, category, filter, page };
    useEffect(() => {
        const qs = new URLSearchParams(Object.entries({ q: search, category, filter }).filter(([, v]) => v)).toString();
        window.history.replaceState(null, '', qs ? `?${qs}` : window.location.pathname);
    }, [search, category, filter]);

    const { data, online, loading } = useLive('/products', params, { interval: 5000 });
    const rows = data?.data;
    const changed = useChangedStock(rows);

    const toggle = (id) => setPicked((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });
    const allOnPage = rows?.length > 0 && rows.every((p) => picked.has(p.id));
    const toggleAll = () => setPicked((s) => {
        const n = new Set(s);
        rows.forEach((p) => (allOnPage ? n.delete(p.id) : n.add(p.id)));
        return n;
    });

    const printLabels = () => {
        const qs = new URLSearchParams([...[...picked].map((id) => ['ids[]', id]), ['copies', copies]]);
        window.open(`${baseUrl}/products/labels?${qs}`, '_blank');
    };

    return (
        <div>
            <div className="card mb-4 flex flex-wrap items-end gap-3 p-4">
                <div className="min-w-56 flex-1">
                    <label className="label">Search or scan</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} className="input" placeholder="Name or barcode" autoFocus />
                </div>
                <div>
                    <label className="label">Category</label>
                    <select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }} className="input">
                        <option value="">All</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label">Show</label>
                    <select value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }} className="input">
                        <option value="">Active products</option>
                        <option value="low">Low stock only</option>
                        <option value="inactive">Deactivated</option>
                    </select>
                </div>
                <div className="ml-auto self-center"><LiveBadge online={online} updatedAt={data?.updated_at} /></div>
            </div>

            <div className="card overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th className="w-8"><input type="checkbox" checked={allOnPage} onChange={toggleAll} /></th>
                            <th>Product</th>
                            <th>Category</th>
                            <th className="text-right">Cost</th>
                            <th className="text-right">Price</th>
                            <th className="text-right">Stock</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading && !rows && <tr><td colSpan="7" className="py-8 text-center text-slate-400">Loading…</td></tr>}
                        {rows?.length === 0 && <tr><td colSpan="7" className="py-8 text-center text-slate-400">No products found.</td></tr>}
                        {rows?.map((p) => (
                            <tr key={p.id}>
                                <td><input type="checkbox" checked={picked.has(p.id)} onChange={() => toggle(p.id)} /></td>
                                <td>
                                    <a href={`${baseUrl}/products/${p.id}`} className="font-medium hover:underline">{p.name}</a>
                                    {p.promo && <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-amber-800">{p.promo}</span>}
                                    <div className="text-xs text-slate-400"><span className="font-mono">{p.barcode}</span>{p.more_barcodes > 0 && ` +${p.more_barcodes} more`}</div>
                                </td>
                                <td className="text-slate-600">{p.category ?? '—'}</td>
                                <td className="text-right text-slate-600">{peso(p.cost)}</td>
                                <td className="text-right font-medium whitespace-nowrap">{peso(p.price)}<span className="text-xs text-slate-400"> /{p.unit}</span></td>
                                <td className={`whitespace-nowrap text-right transition-colors duration-1000 ${changed[p.id] === 'down' ? 'bg-red-50' : changed[p.id] === 'up' ? 'bg-brand-100' : ''}`}>
                                    <span className={`font-semibold ${p.low ? 'text-amber-600' : ''}`}>{qtyText(p.stock)}</span>
                                    <span className="text-xs text-slate-400"> {p.unit}</span>
                                    {p.low && <span className="badge ml-1 bg-amber-100 text-amber-800">Low</span>}
                                </td>
                                <td className="text-right whitespace-nowrap">
                                    <a href={`${baseUrl}/products/${p.id}?adjust=1`} className="tap text-sm text-brand-700 hover:underline">Adjust stock</a>
                                    <a href={`${baseUrl}/products/${p.id}/edit`} className="tap ml-3 text-sm text-brand-700 hover:underline">Edit</a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-slate-500">Print barcode labels for {picked.size} checked item{picked.size === 1 ? '' : 's'},</span>
                    <input type="number" value={copies} min="1" max="100" onChange={(e) => setCopies(e.target.value)} className="input w-20 py-1" />
                    <span className="text-slate-500">each</span>
                    <button type="button" className="btn btn-secondary py-1" disabled={!picked.size} onClick={printLabels}>Print labels</button>
                </div>
                {data && <Pager page={data.page} lastPage={data.last_page} total={data.total} onPage={setPage} />}
            </div>
        </div>
    );
}
