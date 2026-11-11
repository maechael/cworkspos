import React from 'react';

export default function Pager({ page, lastPage, total, onPage }) {
    return (
        <div className="flex items-center gap-2 text-sm text-slate-600">
            <span>{total} total</span>
            <button type="button" className="btn btn-secondary py-1" disabled={page <= 1} onClick={() => onPage(page - 1)}>‹ Prev</button>
            <span>Page {page} of {lastPage}</span>
            <button type="button" className="btn btn-secondary py-1" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>Next ›</button>
        </div>
    );
}
