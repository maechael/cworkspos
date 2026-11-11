import React, { useLayoutEffect, useRef, useState } from 'react';

const axisCount = (n) => Number(n).toLocaleString('en-PH', { maximumFractionDigits: 0 });

/** Clean axis steps that end just above the data. Whole steps only when counting things. */
function niceScale(value, wholeOnly = false) {
    if (value <= 0) return { top: wholeOnly ? 4 : 1, ticks: wholeOnly ? [0, 1, 2, 3, 4] : [0, 0.25, 0.5, 0.75, 1] };
    const rough = value / 4;
    const power = 10 ** Math.floor(Math.log10(rough));
    const steps = wholeOnly ? [1, 2, 5, 10] : [1, 2, 2.5, 5, 10];
    const step = Math.max(wholeOnly ? 1 : 0, steps.find((s) => s * power >= rough) * power);
    const top = Math.ceil(value / step) * step;
    return { top, ticks: Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step) };
}

/** The drawing's real width, so its text never gets stretched. */
function useWidth() {
    const ref = useRef(null);
    const [width, setWidth] = useState(320);
    useLayoutEffect(() => {
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(40, entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    return [ref, width];
}

/** Hovered position in a chart. Arrow keys move it, so the values are reachable by keyboard too. */
function useCursor(count) {
    const [index, setIndex] = useState(null);
    const props = {
        tabIndex: 0,
        onFocus: () => setIndex(count - 1),
        onBlur: () => setIndex(null),
        onMouseLeave: () => setIndex(null),
        onKeyDown: (e) => {
            if (e.key === 'ArrowLeft') { e.preventDefault(); setIndex((i) => Math.max(0, (i ?? count) - 1)); }
            if (e.key === 'ArrowRight') { e.preventDefault(); setIndex((i) => Math.min(count - 1, (i ?? -1) + 1)); }
        },
    };
    return [index, setIndex, props];
}

function Tip({ x, width, children }) {
    const flip = x > width - 170;
    return (
        <div className="chart-tip" style={{ top: 8, left: flip ? undefined : x + 14, right: flip ? width - x + 14 : undefined }}>{children}</div>
    );
}

const everyNth = (count, width, labelWidth = 62) => Math.max(1, Math.ceil(count / Math.max(2, Math.floor(width / labelWidth))));

/** Columns grow from one baseline, with the latest value on its cap. */
export function ColumnChart({ labels, values, color, unit, height = 250, name }) {
    const [box, width] = useWidth();
    const [cursor, setCursor, cursorProps] = useCursor(labels.length);
    const M = { top: 22, right: 8, bottom: 26, left: 36 };
    const plotW = width - M.left - M.right;
    const plotH = height - M.top - M.bottom;
    const { top: max, ticks } = niceScale(Math.max(...values, 0), true);
    const band = plotW / labels.length;
    const barW = Math.min(24, Math.max(4, band * 0.62));
    const base = M.top + plotH;
    const y = (v) => base - (v / max) * plotH;
    const center = (i) => M.left + band * i + band / 2;
    const nth = everyNth(labels.length, plotW);

    const bar = (i, v) => {
        const left = center(i) - barW / 2;
        const top = y(v);
        const r = Math.min(4, barW / 2, base - top);
        return `M${left},${base}V${top + r}Q${left},${top} ${left + r},${top}H${left + barW - r}Q${left + barW},${top} ${left + barW},${top + r}V${base}Z`;
    };

    return (
        <div ref={box} className="relative">
            <svg width={width} height={height} role="img" aria-label={name} {...cursorProps} className="chart-svg block">
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={M.left} x2={width - M.right} y1={y(t)} y2={y(t)} className="chart-grid" />
                        <text x={M.left - 8} y={y(t) + 4} textAnchor="end" className="chart-text">{axisCount(t)}</text>
                    </g>
                ))}
                {values.map((v, i) => (
                    <g key={labels[i]} onMouseEnter={() => setCursor(i)}>
                        <rect x={M.left + band * i} y={M.top} width={band} height={plotH} fill="transparent" />
                        {v > 0 && <path d={bar(i, v)} fill={color} opacity={cursor === null || cursor === i ? 1 : 0.55} />}
                    </g>
                ))}
                {labels.map((label, i) => i % nth === (labels.length - 1) % nth && (
                    <text key={label} x={center(i)} y={height - 8} textAnchor="middle" className="chart-text">{label}</text>
                ))}
                <text x={center(values.length - 1)} y={y(values[values.length - 1]) - 6} textAnchor="middle" className="chart-text chart-text-strong">{axisCount(values[values.length - 1])}</text>
            </svg>
            {cursor !== null && (
                <Tip x={center(cursor)} width={width}>
                    <div className="chart-tip-title">{labels[cursor]}</div>
                    <b>{axisCount(values[cursor])}</b> <span className="opacity-75">{unit}</span>
                </Tip>
            )}
        </div>
    );
}

/** Ranked bars with the value at each tip. Hovering or focusing a row adds its detail. */
export function BarList({ rows, color, format, detail }) {
    const [active, setActive] = useState(null);
    const max = Math.max(...rows.map((r) => r.value), 0) || 1;
    return (
        <ul className="space-y-3">
            {rows.map((row) => (
                <li key={row.name} tabIndex={0} className="chart-row grid grid-cols-[minmax(0,38%)_minmax(0,1fr)] items-center gap-3 rounded-md"
                    onMouseEnter={() => setActive(row.name)} onMouseLeave={() => setActive(null)} onFocus={() => setActive(row.name)} onBlur={() => setActive(null)}>
                    <span className="truncate text-right text-xs text-slate-600" title={row.name}>{row.name}</span>
                    <span className="relative flex items-center gap-2">
                        <span className="h-5 shrink-0 rounded-r-[4px] transition-opacity" style={{ width: `calc((100% - 76px) * ${row.value / max})`, minWidth: row.value > 0 ? 2 : 0, background: color, opacity: active && active !== row.name ? 0.55 : 1 }} />
                        <span className="text-xs font-semibold whitespace-nowrap text-slate-800">{format(row.value)}</span>
                        {active === row.name && detail && <span className="chart-tip" style={{ bottom: '100%', right: 0, marginBottom: 4 }}>{detail(row)}</span>}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** A chart's card: title, and the switch to its table twin, so no value depends on hovering. */
export function ChartPanel({ title, subtitle, columns, rows, children }) {
    const [asTable, setAsTable] = useState(false);
    return (
        <section className="card flex flex-col p-5">
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-[15px] font-semibold text-slate-900">{title}</h3>
                    {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}
                </div>
                <div className="flex shrink-0 items-center gap-4">
                    {columns && (
                        <button type="button" onClick={() => setAsTable(!asTable)} aria-pressed={asTable} aria-label={asTable ? `Show ${title} as a chart` : `Show ${title} as a table`} title={asTable ? 'Show chart' : 'Show table'}
                            className="-m-1.5 flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                            <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                {asTable ? <path d="M4 19h16M7 15l3.5-4 3 2.5L18 8" /> : <path d="M4 5h16v14H4zM4 10h16M10 10v9" />}
                            </svg>
                        </button>
                    )}
                </div>
            </div>
            {asTable ? (
                <div className="max-h-72 overflow-auto">
                    <table className="w-full text-left text-xs tabular-nums">
                        <thead className="sticky top-0 bg-white text-slate-500">
                            <tr>{columns.map((c, i) => <th key={c} className={`py-1.5 pr-3 font-medium ${i ? 'text-right' : ''}`}>{c}</th>)}</tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row[0]} className="border-t border-slate-100">
                                    {row.map((cell, i) => <td key={i} className={`py-1.5 pr-3 ${i ? 'text-right font-medium text-slate-800' : 'text-slate-600'}`}>{cell}</td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : children}
        </section>
    );
}
