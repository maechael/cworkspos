import React from 'react';

export default function LiveBadge({ online, updatedAt }) {
    return (
        <span className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium ${online ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700'}`}>
            <span className={`h-2 w-2 rounded-full ${online ? 'animate-pulse bg-brand-500' : 'bg-red-500'}`} />
            {online ? `Live${updatedAt ? ` · updated ${updatedAt}` : ''}` : 'Offline, retrying…'}
        </span>
    );
}
