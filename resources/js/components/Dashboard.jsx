import React, { useEffect, useRef, useState } from 'react';
import useLive from '../useLive';
import LiveBadge from './LiveBadge';
import { BarList, ChartPanel, ColumnChart } from './dashboard/Charts';
import { baseUrl, peso, qtyText } from '../lib';

const PERIODS = { today: 'Today', week: 'This week', month: 'This month' };
const PAYMENTS = { cash: 'Cash', gcash: 'GCash', card: 'Card' };

const count = (n) => Number(n).toLocaleString('en-PH', { maximumFractionDigits: 3 });
// Large amounts are shortened to fit the card; the full amount shows on hover
const shortPeso = (n) => (Math.abs(n) >= 100000
    ? '₱' + Number(n).toLocaleString('en-PH', { notation: 'compact', maximumFractionDigits: 1 })
    : peso(n));

const FILTERS = [
    ['', 'All sales'],
    ['completed', 'Completed'],
    ['voided', 'Voided'],
    ['cash', 'Paid by cash'],
    ['gcash', 'Paid by GCash'],
    ['card', 'Paid by card'],
];

const SVG = ({ d, className = 'h-5 w-5', width = 1.9 }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={width} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={d} /></svg>
);

function greeting(name) {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    return `Good ${part}, ${name.split(/\s+/)[0]}`;
}

const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

/** Ids that just appeared in a list, so they can be highlighted for a moment. */
function useNewIds(ids) {
    const seen = useRef(null);
    const [fresh, setFresh] = useState([]);
    const key = ids.join(',');
    useEffect(() => {
        if (seen.current) {
            const added = ids.filter((id) => !seen.current.has(id));
            if (added.length) {
                setFresh(added);
                setTimeout(() => setFresh([]), 4000);
            }
        }
        seen.current = new Set(ids);
    }, [key]);
    return fresh;
}

function Hero({ name, low, expiring }) {
    const clear = low === 0 && expiring === 0;
    return (
        <section className="dash-hero on-color relative overflow-hidden rounded-2xl px-6 py-7 sm:px-8 sm:py-8">
            <div className="relative z-10 max-w-xl">
                <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">{greeting(name)}</h1>
                <p className="mt-2 text-sm leading-relaxed text-white/90">
                    {clear ? 'Nothing needs your attention right now. The shelves are in good shape.' : (
                        <>You have {plural(low, 'item')} at or below reorder level and {plural(expiring, 'lot')} expiring within 30 days.<br />Let’s keep the shelves stocked.</>
                    )}
                </p>
                <a href={clear ? `${baseUrl}/pos` : `${baseUrl}/products?filter=low`} className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-[#1c46ad] transition hover:bg-[#eef3ff]">
                    {clear ? 'Open cashier' : 'Review low stock'} <SVG d="M5 12h14M13 6l6 6-6 6" className="h-4 w-4" />
                </a>
            </div>
            {/* Decoration only: a receipt card and a little sales chart over a ring */}
            <svg className="absolute top-1/2 right-4 hidden h-[190px] w-[270px] -translate-y-1/2 md:block" viewBox="0 0 270 190" aria-hidden="true">
                <path d="M62 120a72 72 0 1 1 112 62" fill="none" stroke="#fff" strokeOpacity=".16" strokeWidth="30" strokeLinecap="round" />
                <rect x="116" y="74" width="16" height="74" rx="8" transform="rotate(24 124 111)" fill="#7fb0ff" />
                <rect x="170" y="14" width="88" height="60" rx="11" fill="#fff" />
                <rect x="182" y="27" width="38" height="5" rx="2.5" fill="#6d8fe8" />
                <circle cx="243" cy="29.5" r="5" fill="#22a06b" />
                <rect x="182" y="41" width="60" height="4" rx="2" fill="#d6e0f7" />
                <rect x="182" y="52" width="46" height="4" rx="2" fill="#d6e0f7" />
                <rect x="24" y="118" width="80" height="60" rx="11" fill="#fff" />
                <rect x="38" y="151" width="9" height="16" rx="2" fill="#7fb0ff" />
                <rect x="53" y="134" width="9" height="33" rx="2" fill="#3468e8" />
                <rect x="68" y="143" width="9" height="24" rx="2" fill="#3468e8" />
                <rect x="83" y="148" width="9" height="19" rx="2" fill="#7fb0ff" />
            </svg>
        </section>
    );
}

function Rail({ week, expiring, expiringCount, lowStock }) {
    const pill = 'hit rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-100';
    return (
        <aside className="card self-start p-5">
            <div className="flex items-center justify-between">
                <h2 className="font-semibold text-slate-900">This week</h2>
                <span className="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">{week.month}</span>
            </div>
            <ul className="mt-3 grid grid-cols-7 gap-1">
                {week.days.map((d) => (
                    <li key={d.date} aria-label={`${d.name} ${d.number}: ${plural(d.deliveries, 'delivery', 'deliveries')}, ${plural(d.expiring, 'lot')} expiring`}
                        className={`flex flex-col items-center gap-0.5 rounded-xl py-2 text-center ${d.today ? 'on-color bg-brand-600 text-white' : 'bg-slate-50 text-slate-800'}`}>
                        <span className={`text-[11px] ${d.today ? 'text-white/85' : 'text-slate-500'}`}>{d.name}</span>
                        <span className="text-sm font-semibold">{d.number}</span>
                        <span className="flex h-2 items-center gap-0.5">
                            {d.deliveries > 0 && <span className={`h-1.5 w-1.5 rounded-full ${d.today ? 'bg-white' : 'bg-brand-500'}`} />}
                            {d.expiring > 0 && <span className="h-1.5 w-1.5 rotate-45 rounded-[1px] bg-amber-500" />}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="mt-2 flex gap-4 text-xs text-slate-500">
                <span className="flex items-center gap-1.5"><span className="h-1.5 w-1.5 rounded-full bg-brand-500" />Deliveries</span>
                <span className="flex items-center gap-1.5"><span className="h-1.5 w-1.5 rotate-45 rounded-[1px] bg-amber-500" />Lots expiring</span>
            </div>

            <div className="mt-6 flex items-center justify-between">
                <h2 className="font-semibold text-slate-900">Expiring soon {expiringCount > 0 && <span className="font-normal text-slate-500">({expiringCount})</span>}</h2>
                <a href={`${baseUrl}/reports/expiry`} className={pill}>View all</a>
            </div>
            <ul className="mt-2 space-y-1">
                {expiring.length === 0 && <li className="py-3 text-sm text-slate-500">Nothing expiring in the next 30 days.</li>}
                {expiring.map((lot) => {
                    const urgent = lot.days_left <= 7;
                    return (
                        <li key={lot.id} className="flex items-center gap-3 py-2">
                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold ${urgent ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'}`}>
                                {lot.days_left < 0 ? '!' : `${lot.days_left}d`}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium text-slate-900" title={lot.name}>{lot.name}</div>
                                <div className="truncate text-xs text-slate-500">{qtyText(lot.remaining)} {lot.unit} left · {lot.days_left < 0 ? 'Expired' : lot.days_left === 0 ? 'Today' : lot.expiry}</div>
                            </div>
                        </li>
                    );
                })}
            </ul>

            <div className="mt-6 flex items-center justify-between">
                <h2 className="font-semibold text-slate-900">Low stock</h2>
                <a href={`${baseUrl}/deliveries/create`} className={pill}>Receive stock</a>
            </div>
            <ul className="mt-2 space-y-2">
                {lowStock.length === 0 && <li className="py-3 text-sm text-slate-500">Everything is well stocked.</li>}
                {lowStock.map((p) => {
                    const share = p.reorder > 0 ? Math.min(100, (p.stock / p.reorder) * 100) : 0;
                    return (
                        <li key={p.id}>
                            <a href={`${baseUrl}/products/${p.id}`} className="block rounded-xl bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-sm font-medium text-slate-900" title={p.name}>{p.name}</span>
                                    <span className="text-xs font-semibold whitespace-nowrap text-red-700">{qtyText(p.stock)} / {qtyText(p.reorder)} {p.unit}</span>
                                </div>
                                <div className="pos-mono truncate text-xs text-slate-500">{p.barcode || 'No barcode'}</div>
                                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200"><div className="h-full rounded-full" style={{ width: `${share}%`, background: share < 34 ? 'var(--status-bad)' : 'var(--status-warn)' }} /></div>
                            </a>
                        </li>
                    );
                })}
            </ul>
        </aside>
    );
}

function StatusPill({ voided }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ${voided ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${voided ? 'bg-red-600' : 'bg-green-600'}`} />
            {voided ? 'Voided' : 'Completed'}
        </span>
    );
}

/** The ⋮ menu. Drawn fixed so the table's scroll area can't cut it off. */
function RowActions({ sale }) {
    const [pos, setPos] = useState(null);

    useEffect(() => {
        if (!pos) return undefined;
        const close = () => setPos(null);
        const onKey = (e) => e.key === 'Escape' && close();
        window.addEventListener('click', close);
        window.addEventListener('scroll', close, true);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('click', close);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('keydown', onKey);
        };
    }, [pos]);

    const toggle = (e) => {
        e.stopPropagation();
        const r = e.currentTarget.getBoundingClientRect();
        setPos(pos ? null : { top: r.bottom + 4, right: window.innerWidth - r.right });
    };

    return (
        <>
            <button type="button" onClick={toggle} aria-haspopup="menu" aria-expanded={!!pos} aria-label={`Actions for ${sale.receipt_no}`}
                className="h-10 w-10 rounded-md text-lg leading-none font-bold text-slate-600 hover:bg-slate-100">⋮</button>
            {pos && (
                <div role="menu" className="fixed z-50 w-40 rounded-xl border border-slate-200 bg-white p-1.5 text-left text-sm shadow-lg" style={pos}>
                    <a role="menuitem" href={`${baseUrl}/sales/${sale.id}`} className="block rounded-lg px-3 py-2 hover:bg-slate-50">View sale</a>
                    <a role="menuitem" href={`${baseUrl}/sales/${sale.id}/receipt`} target="_blank" rel="noreferrer" className="block rounded-lg px-3 py-2 hover:bg-slate-50">Open receipt</a>
                </div>
            )}
        </>
    );
}

function PageNumbers({ page, last, onPage }) {
    // Up to 5 numbers, keeping the current page in view
    const first = Math.max(1, Math.min(page - 2, last - 4));
    const numbers = Array.from({ length: Math.min(5, last) }, (_, i) => first + i);
    const arrow = 'flex h-9 w-9 items-center justify-center rounded-lg text-lg text-slate-700 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent';
    return (
        <nav className="flex items-center gap-1" aria-label="Pages">
            <button type="button" className={arrow} disabled={page <= 1} aria-label="Previous page" onClick={() => onPage(page - 1)}>‹</button>
            {numbers.map((n) => (
                <button key={n} type="button" aria-current={n === page ? 'page' : undefined} onClick={() => onPage(n)}
                    className={`h-9 min-w-9 rounded-lg px-2 text-sm font-medium ${n === page ? 'bg-brand-600 text-white' : 'text-slate-700 hover:bg-slate-100'}`}>{n}</button>
            ))}
            <button type="button" className={arrow} disabled={page >= last} aria-label="Next page" onClick={() => onPage(page + 1)}>›</button>
        </nav>
    );
}

const th = 'px-5 py-3 text-xs font-medium whitespace-nowrap text-slate-500';

export default function Dashboard({ initial, userName }) {
    const [period, setPeriod] = useState(initial.period);
    const [filter, setFilter] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(initial.sales.per_page);

    const params = { period, filter, page, per_page: perPage };
    const { data, online } = useLive('/dashboard', params, { initial, interval: 4000 });
    const sales = data.sales;
    const freshSales = useNewIds(sales.rows.map((s) => s.id));
    const periodName = PERIODS[data.period].toLowerCase();

    // Sales can drop off the last page, such as when a filter or period changes
    useEffect(() => {
        if (page > sales.last_page) setPage(sales.last_page);
    }, [page, sales.last_page]);

    const changePeriod = (v) => { setPeriod(v); setPage(1); };
    const exportUrl = `${baseUrl}/dashboard/export?${new URLSearchParams({ period, filter })}`;

    const topSellers = data.top_products.map((p) => ({ name: p.name, value: p.total, qty: p.qty }));

    return (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
            <div className="min-w-0 space-y-6">
                <Hero name={userName} low={data.low_stock_count} expiring={data.expiring_count} />

                <section>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-slate-900">Performance</h2>
                        <LiveBadge online={online} updatedAt={data.updated_at} />
                    </div>
                    <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                        <ChartPanel title="Transactions per day" subtitle="Completed sales, last 14 days"
                            columns={['Day', 'Transactions']}
                            rows={data.daily.labels.map((label, i) => [label, count(data.daily.transactions[i])])}>
                            <ColumnChart labels={data.daily.labels} values={data.daily.transactions} color="var(--chart-1)" unit="transactions" height={230} name="Transactions per day, last 14 days" />
                        </ChartPanel>

                        <ChartPanel title="Top sellers" subtitle="By sales, last 30 days"
                            columns={['Product', 'Qty sold', 'Sales']}
                            rows={topSellers.map((p) => [p.name, qtyText(p.qty), peso(p.value)])}>
                            {topSellers.length === 0 ? <p className="py-10 text-center text-sm text-slate-500">No sales yet.</p> : (
                                <BarList rows={topSellers} color="var(--chart-1)" format={shortPeso} detail={(r) => <>{qtyText(r.qty)} sold</>} />
                            )}
                        </ChartPanel>
                    </div>
                </section>

                <section>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-slate-900">Recent sales <span className="text-sm font-normal text-slate-500">· {periodName}</span></h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <select aria-label="Period" value={period} onChange={(e) => changePeriod(e.target.value)} className="input w-auto py-1.5 pr-8">
                                {Object.entries(PERIODS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                            <select aria-label="Filter sales" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }} className="input w-auto py-1.5 pr-8">
                                {FILTERS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                            </select>
                            <a href={exportUrl} className="btn btn-secondary py-2"><SVG d="M12 4v11M7.5 10.5 12 15l4.5-4.5M4 20h16" className="h-4 w-4" width={2.2} />Export</a>
                            <a href={`${baseUrl}/sales`} className="btn btn-primary py-2">View all</a>
                        </div>
                    </div>
                    <div className="card overflow-hidden">
                        <div className="relative overflow-x-auto">
                            <table className="table-stack w-full text-left text-sm sm:min-w-[820px]">
                                <thead>
                                    <tr className="border-b border-slate-100">
                                        <th className={th}>Receipt</th>
                                        <th className={th}>Items</th>
                                        <th className={th}>Payment</th>
                                        <th className={th}>Cashier</th>
                                        <th className={th}>Date</th>
                                        <th className={`${th} text-right`}>Amount</th>
                                        <th className={th}>Status</th>
                                        <th className={`${th} text-center`}><span className="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sales.rows.length === 0 && (
                                        <tr><td colSpan="8" className="px-5 py-12 text-center text-slate-500">No sales to show for {periodName}.</td></tr>
                                    )}
                                    {sales.rows.map((s) => (
                                        <tr key={s.id} className={`border-b border-slate-100 transition-colors duration-1000 last:border-0 ${freshSales.includes(s.id) ? 'bg-brand-100' : 'hover:bg-slate-50'}`}>
                                            <td className="px-5 py-3 whitespace-nowrap">
                                                <a href={`${baseUrl}/sales/${s.id}`} className="font-medium text-brand-700 hover:underline">{s.receipt_no}</a>
                                                <div className="text-xs text-slate-500">{plural(s.item_count, 'item')}</div>
                                            </td>
                                            <td className="max-w-60 truncate px-5 py-3 text-slate-600" title={s.item_names}>{s.item_names}</td>
                                            <td className="px-5 py-3 text-slate-600">{PAYMENTS[s.payment] || s.payment}</td>
                                            <td className="px-5 py-3 whitespace-nowrap text-slate-600">{s.cashier}</td>
                                            <td className="px-5 py-3 whitespace-nowrap text-slate-600">{s.date}<div className="text-xs text-slate-500">{s.time}</div></td>
                                            <td className={`px-5 py-3 text-right font-semibold whitespace-nowrap tabular-nums ${s.voided ? 'text-slate-400 line-through' : 'text-slate-900'}`}>{peso(s.total)}</td>
                                            <td className="px-5 py-3"><StatusPill voided={s.voided} /></td>
                                            <td className="px-3 py-3 text-center"><RowActions sale={s} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
                            <span>Showing {sales.from} to {sales.to} of {sales.total} {sales.total === 1 ? 'entry' : 'entries'}</span>
                            <PageNumbers page={sales.page} last={sales.last_page} onPage={setPage} />
                            <label className="flex items-center gap-2">
                                Rows per page
                                <select value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }} className="input w-auto py-1 pr-7">
                                    {[5, 10, 25].map((n) => <option key={n} value={n}>{n}</option>)}
                                </select>
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            <Rail week={data.week} expiring={data.expiring} expiringCount={data.expiring_count} lowStock={data.low_stock} />
        </div>
    );
}
