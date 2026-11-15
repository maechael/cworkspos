import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { baseUrl, beep, errorMessage, peso } from '../lib';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };
const canNotify = typeof window !== 'undefined' && 'Notification' in window && window.isSecureContext;

/**
 * The owner's say over cashiers' voids and returns, from any device they are logged in on:
 * a switch to turn them off (overnight, say), and Approve / Decline on each request as it comes in.
 * New requests beep, show in the tab title, and can show a phone or desktop notification.
 */
export default function ApprovalsPanel({ initial, compact = false }) {
    const [data, setData] = useState(initial);
    const [busy, setBusy] = useState(null);
    const [notice, setNotice] = useState(null);
    const [online, setOnline] = useState(true);
    const [notifyState, setNotifyState] = useState(canNotify ? Notification.permission : 'unsupported');
    const seen = useRef(new Set(initial.waiting.map((w) => w.id)));
    const title = useRef(document.title);

    // Checks every 3 seconds, also while the tab is in the background so a notification can come up
    useEffect(() => {
        let stopped = false;
        let timer;
        const load = async () => {
            try {
                const { data: fresh } = await axios.get('/approvals', JSON_HEADERS);
                if (!stopped) { setData(fresh); setOnline(true); }
            } catch (err) {
                if (err.response?.status === 401 || err.response?.status === 419) return window.location.reload();
                if (!stopped) setOnline(false);
            }
            if (!stopped) timer = setTimeout(load, 3000);
        };
        timer = setTimeout(load, 3000);
        return () => { stopped = true; clearTimeout(timer); };
    }, []);

    // Something new to answer: beep, notify, and put the count in the tab title
    useEffect(() => {
        const fresh = data.waiting.filter((w) => !seen.current.has(w.id));
        fresh.forEach((w) => seen.current.add(w.id));
        if (fresh.length) {
            beep();
            if (canNotify && Notification.permission === 'granted') {
                fresh.forEach((w) => {
                    try { new Notification(`${w.cashier} needs your approval`, { body: w.summary, tag: `approval-${w.id}` }); } catch { /* optional */ }
                });
            }
        }
        const n = data.waiting.length;
        document.title = n ? `(${n}) Approval needed · ${title.current}` : title.current;
    }, [data]);

    const post = async (url, body, key) => {
        setBusy(key);
        setNotice(null);
        try {
            const { data: d } = await axios.post(url, body, JSON_HEADERS);
            setData(d);
            setNotice({ text: d.notice, error: !!d.error });
        } catch (err) {
            if (err.response?.data?.waiting) setData(err.response.data);
            setNotice({ text: err.response?.data?.notice || errorMessage(err), error: true });
        } finally {
            setBusy(null);
        }
    };

    const toggle = () => {
        const turnOff = data.allowed;
        if (turnOff && data.waiting.length && !window.confirm(`Turn off voids and returns? The ${data.waiting.length} waiting request${data.waiting.length === 1 ? '' : 's'} will be declined.`)) return;
        post('/approvals/switch', { allowed: !turnOff }, 'switch');
    };

    const askToNotify = async () => {
        try { setNotifyState(await Notification.requestPermission()); } catch { /* ignored */ }
    };

    const waiting = data.waiting;

    return (
        <section className="card p-4 sm:p-5" aria-labelledby="approvals-heading">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="approvals-heading" className="font-semibold">
                        Approvals{waiting.length > 0 && <span className="badge ml-2 bg-red-100 text-red-700">{waiting.length} waiting</span>}
                    </h2>
                    <p className="text-sm text-slate-500">
                        {data.allowed
                            ? "Cashiers' voids and returns need your OK: your password at the counter, or a tap here."
                            : "Voids and returns are off. Cashiers can't void or return anything until you turn them on."}
                    </p>
                </div>
                <button type="button" role="switch" aria-checked={data.allowed} disabled={busy === 'switch'} onClick={toggle}
                    className="flex min-h-10 items-center gap-3 rounded-full border border-slate-200 py-1 pr-4 pl-1 text-sm font-medium">
                    <span className={`relative h-7 w-12 rounded-full transition-colors ${data.allowed ? 'bg-brand-600' : 'bg-slate-300'}`}>
                        <span className={`absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all ${data.allowed ? 'left-6' : 'left-1'}`} />
                    </span>
                    Voids and returns: {data.allowed ? 'On' : 'Off'}
                </button>
            </div>

            {notice?.text && (
                <p role="status" className={`mt-3 rounded-lg px-3 py-2 text-sm font-medium ${notice.error ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-700'}`}>{notice.text}</p>
            )}
            {!online && <p className="mt-3 text-sm text-amber-700">Can't reach the store's system right now. Still trying…</p>}

            {waiting.length > 0 ? (
                <ul className="mt-4 space-y-3">
                    {waiting.map((w) => (
                        <li key={w.id} className="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
                                <span><span className="font-semibold">{w.cashier}</span> asks: {w.type}</span>
                                <span className="text-slate-500">{w.asked}</span>
                            </div>
                            <p className="mt-1 font-medium text-slate-900">{w.summary}</p>
                            <p className="mt-1 text-sm text-slate-600">
                                {w.amount > 0 && <>Amount {peso(w.amount)}. </>}
                                {w.reason && <>Reason: {w.reason}{w.note ? ` (${w.note})` : ''}. </>}
                                {w.receipt_no && <a href={w.sale_url} className="tap text-brand-700 hover:underline">See {w.receipt_no}</a>}
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button type="button" className="btn btn-primary" disabled={!!busy} onClick={() => post(`/approvals/${w.id}/approve`, {}, w.id)}>
                                    {busy === w.id ? 'Working…' : 'Approve'}
                                </button>
                                <button type="button" className="btn btn-secondary" disabled={!!busy} onClick={() => post(`/approvals/${w.id}/decline`, {}, w.id)}>Decline</button>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                !compact && <p className="mt-4 text-sm text-slate-500">Nothing is waiting. New requests show up here by themselves.</p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                {compact && <a href={`${baseUrl}/approvals`} className="tap text-brand-700 hover:underline">{waiting.length ? 'All approvals' : 'Approval history'}</a>}
                {notifyState === 'default' && <button type="button" onClick={askToNotify} className="tap text-brand-700 hover:underline">Notify me on this device</button>}
                {notifyState === 'denied' && !compact && <span className="text-slate-500">Notifications are blocked for this site in the browser's settings.</span>}
            </div>
        </section>
    );
}
