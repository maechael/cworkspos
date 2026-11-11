import React, { useEffect, useRef, useState } from 'react';
import useLive from '../useLive';
import LiveBadge from './LiveBadge';
import Pager from './Pager';
import { Bone } from './pos/Parts';
import { baseUrl, peso } from '../lib';

// Placeholder width for each table column, in order
const SKELETON_COLUMNS = ['w-32', 'w-36', 'w-20', 'w-10', 'ml-auto w-16', 'w-12', 'ml-auto w-8'];

export default function Sales({ from: startFrom, to: startTo, isAdmin, cashiers }) {
    const [from, setFrom] = useState(startFrom);
    const [to, setTo] = useState(startTo);
    const [cashier, setCashier] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);

    const { data, online, loading } = useLive('/sales', { from, to, cashier, q, page }, { interval: 4000 });

    // Highlight sales that arrive while the page is open
    const seen = useRef(null);
    const [fresh, setFresh] = useState([]);
    useEffect(() => { seen.current = null; }, [from, to, cashier, q, page]);
    useEffect(() => {
        if (!data) return;
        const ids = data.data.map((s) => s.id);
        if (seen.current) {
            const added = ids.filter((id) => !seen.current.has(id));
            if (added.length) {
                setFresh(added);
                setTimeout(() => setFresh([]), 4000);
            }
        }
        seen.current = new Set([...(seen.current || []), ...ids]);
    }, [data]);

    const change = (setter) => (e) => { setter(e.target.value); setPage(1); };

    return (
        <div>
            <div className="card mb-4 flex flex-wrap items-end gap-3 p-4">
                <div><label className="label">From</label><input type="date" value={from} onChange={change(setFrom)} className="input" /></div>
                <div><label className="label">To</label><input type="date" value={to} onChange={change(setTo)} className="input" /></div>
                {isAdmin && (
                    <div>
                        <label className="label">Cashier</label>
                        <select value={cashier} onChange={change(setCashier)} className="input">
                            <option value="">All</option>
                            {cashiers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                )}
                <div><label className="label">Receipt no.</label><input value={q} onChange={change(setQ)} className="input" /></div>
                <div className="ml-auto text-right">
                    <LiveBadge online={online} updatedAt={data?.updated_at} />
                    {data ? (
                        <>
                            <div className="mt-2 text-sm text-slate-500">{data.totals.count} completed sales · cash {peso(data.totals.cash)}</div>
                            <div className="text-2xl font-bold">{peso(data.totals.total)}</div>
                        </>
                    ) : (
                        <>
                            <div className="mt-2 flex h-5 items-center justify-end"><Bone className="h-3 w-44" /></div>
                            <div className="flex h-8 items-center justify-end"><Bone className="h-5 w-28" /></div>
                        </>
                    )}
                </div>
            </div>

            <div className="card overflow-x-auto">
                <table className="table">
                    <thead><tr><th>Receipt</th><th>Date &amp; time</th><th>Cashier</th><th>Payment</th><th className="text-right">Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        {loading && !data && Array.from({ length: 5 }, (_, row) => (
                            <tr key={row} aria-hidden="true">
                                {SKELETON_COLUMNS.map((width, col) => <td key={col}><Bone className={`h-3.5 ${width}`} /></td>)}
                            </tr>
                        ))}
                        {data?.data.length === 0 && <tr><td colSpan="7" className="py-8 text-center text-slate-400">No sales for these dates.</td></tr>}
                        {data?.data.map((s) => (
                            <tr key={s.id} className={`transition-colors duration-1000 ${fresh.includes(s.id) ? 'bg-brand-100' : ''}`}>
                                <td className="font-mono text-sm">{s.receipt_no}</td>
                                <td>{s.time}</td>
                                <td>{s.cashier}</td>
                                <td className="text-xs uppercase">{s.payment}</td>
                                <td className="text-right font-semibold">{peso(s.total)}</td>
                                <td>{s.voided
                                    ? <span className="badge bg-red-100 text-red-700">Voided</span>
                                    : <span className="badge bg-brand-100 text-brand-700">Paid</span>}</td>
                                <td className="text-right"><a href={`${baseUrl}/sales/${s.id}`} className="tap text-sm text-brand-700 hover:underline">View</a></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {data && <div className="mt-3 flex justify-end"><Pager page={data.page} lastPage={data.last_page} total={data.total} onPage={setPage} /></div>}
        </div>
    );
}
