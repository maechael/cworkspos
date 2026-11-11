<div class="no-print mb-4 flex flex-wrap gap-1 border-b border-slate-200">
    @foreach (['reports.sales' => 'Sales', 'reports.inventory' => 'Inventory value', 'reports.expiry' => 'Expiring items', 'reports.movements' => 'Stock movements'] as $route => $label)
        <a href="{{ route($route) }}"
           class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ request()->routeIs($route) ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
    @endforeach
    <button onclick="window.print()" class="btn btn-secondary mb-1 ml-auto py-1">Print</button>
</div>
