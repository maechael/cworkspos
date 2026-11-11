import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { peso, qtyText } from '../../../lib';
import { Icon, Loading, NavRestore, Notice, OrderLines, SkeletonCard, Thumb, UndoToast, itemsText, money } from '../Parts';
import { useCatalog, useLookup } from '../usePos';

/** Today's completed sales total (the cashier's own, or the whole store for the owner). */
function useTodaySales(reload) {
    const [total, setTotal] = useState(null);
    useEffect(() => {
        axios.get('/sales', { headers: { Accept: 'application/json' } })
            .then(({ data }) => setTotal(data.totals?.total ?? 0))
            .catch(() => setTotal(null));
    }, [reload]);
    return total;
}

/** Style 07 · Pocket: the whole POS on a phone. Quick items, count badges, "Bayad" bar. */
export default function Pocket({ pos, storeName }) {
    const { products, error } = useCatalog(pos.saleNo);
    const lk = useLookup(pos, products);
    const today = useTodaySales(pos.saleNo);
    const qtyOf = (id) => pos.cart.find((l) => l.id === id)?.qty || 0;
    const quick = useMemo(() => {
        if (!products) return [];
        if (lk.value && lk.matches.length) return lk.matches;
        return products.slice(0, 15); // best sellers first
    }, [products, lk.value, lk.matches]);

    const phone = 'pos-card relative flex h-full w-full max-w-[430px] flex-col overflow-hidden rounded-3xl';

    return (
        <div className="flex h-full items-stretch justify-center gap-6 p-2 sm:p-4">
            <div className={phone}>
                <Notice notice={pos.notice} />
                <UndoToast pos={pos} className="bottom-24 left-1/2 -translate-x-1/2" />
                <header className="px-5 pt-5 pb-4" style={{ background: 'var(--pos-brand)', color: 'var(--pos-on-brand)' }}>
                    <div className="flex items-center justify-between gap-2">
                        <span className="flex min-w-0 items-center gap-2">
                            <NavRestore onBrand />
                            <span className="truncate text-lg font-semibold">{storeName}</span>
                        </span>
                        <span className="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}>
                            {today == null ? 'Ready' : `Today ₱${money(today)}`}
                        </span>
                    </div>
                    <div className="mt-3 flex gap-2">
                        <label className="pos-field flex min-w-0 flex-1 items-center gap-2.5 px-3.5 py-2.5" style={{ color: 'var(--pos-text)' }}>
                            <Icon name="search" size={20} color="var(--pos-muted)" />
                            <input {...lk.inputProps} placeholder="Search item" aria-label="Search item" className="text-base" />
                        </label>
                        <button type="button" onClick={() => pos.focusScan()} aria-label="Scan barcode" className="pos-btn pos-btn-soft w-12 rounded-xl"><Icon name="scan" size={24} /></button>
                    </div>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto px-4 pt-3 pb-2">
                    <div className="pos-heading mb-2 text-sm font-semibold">{lk.value ? 'Matches' : 'Quick items'}</div>
                    {!products ? (
                        <Loading error={error}>
                            <div className="grid grid-cols-3 gap-2 p-0.5">{Array.from({ length: 15 }, (_, i) => <SkeletonCard key={i} compact />)}</div>
                        </Loading>
                    ) : (
                        <div className="grid grid-cols-3 gap-2 p-0.5">
                            {quick.map((p) => {
                                const n = qtyOf(p.id);
                                return (
                                    <button key={p.id} type="button" onClick={() => (lk.value ? lk.pick(p) : pos.handleProduct(p))} className={`pos-product ${n ? 'in-cart' : ''} ${p.stock <= 0 ? 'sold-out' : ''}`}>
                                        <Thumb product={p} size={24}>{n > 0 && <span className="pos-count">{qtyText(n)}</span>}</Thumb>
                                        <span className="line-clamp-2 min-h-8 text-xs leading-tight font-medium">{p.name}</span>
                                        <span className="text-sm font-semibold">{peso(p.price)}</span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
                <div className="p-3">
                    <div className="flex items-center justify-between rounded-2xl px-4 py-3" style={{ background: 'var(--pos-heading)', color: '#FFFFFF' }}>
                        <div>
                            <div className="text-xs opacity-85">{itemsText(pos.itemCount)}</div>
                            <div className="text-2xl font-bold">{peso(pos.subtotal)}</div>
                        </div>
                        <button type="button" disabled={!pos.canPay} onClick={() => pos.setPaying(true)} className="pos-btn px-5 py-2.5 text-base" style={{ background: '#FFFFFF', color: 'var(--pos-heading)' }}>Bayad →</button>
                    </div>
                </div>
            </div>

            {/* Second screen on wide displays: the cart and today's numbers */}
            <div className={`${phone} hidden lg:flex`}>
                <div className="px-6 pt-6 pb-2 text-center">
                    <div className="pos-muted text-sm">Total</div>
                    <div className="pos-heading text-4xl font-bold tracking-tight">{peso(pos.subtotal)}</div>
                </div>
                <OrderLines pos={pos} empty="Tap quick items to start a sale." />
                <div className="space-y-2.5 p-4">
                    <div className="flex justify-between rounded-xl px-4 py-2.5 text-sm" style={{ background: 'var(--pos-soft)', color: 'var(--pos-soft-ink)' }}>
                        <span>Today’s sales</span><b>{today == null ? '—' : `₱${money(today)}`}</b>
                    </div>
                    <button type="button" disabled={!pos.canPay} onClick={() => pos.setPaying(true)} className="pos-btn pos-btn-primary w-full py-3.5 text-lg">Collect payment</button>
                </div>
            </div>
        </div>
    );
}
