@extends('layouts.app', ['title' => 'Reports'])

@section('content')
    @include('reports.tabs')
    @php
        $query = array_filter(['category' => request('category')]);
        $presets = [
            'Last 30 days' => [$today->copy()->subDays(29), $today],
            'This month' => [$today->copy()->startOfMonth(), $today],
        ];
        $card = 'card p-4';
        $label = 'text-sm text-slate-500';
        $money = 'text-2xl font-bold';
    @endphp
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="label" for="inventory-category">Category</label>
            <select id="inventory-category" name="category" class="input" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div><label class="label" for="inventory-from">From</label><input id="inventory-from" type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" class="input"></div>
        <div><label class="label" for="inventory-to">To</label><input id="inventory-to" type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" class="input"></div>
        <button class="btn btn-secondary">Show</button>
        @foreach ($presets as $name => [$start, $end])
            <a href="{{ route('reports.inventory', $query + ['from' => $start->toDateString(), 'to' => $end->toDateString()]) }}" @if ($start->isSameDay($from) && $end->isSameDay($to)) aria-current="true" @endif class="btn {{ $start->isSameDay($from) && $end->isSameDay($to) ? 'btn-primary' : 'btn-secondary' }}">{{ $name }}</a>
        @endforeach
    </form>

    <h2 class="mb-2 font-semibold">Your stock today</h2>
    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <div class="{{ $card }}"><div class="{{ $label }}">Stock value at cost</div><div class="{{ $money }}">{{ peso($totalCost) }}</div></div>
        <div class="{{ $card }}"><div class="{{ $label }}">Stock value at selling price</div><div class="{{ $money }}">{{ peso($totalRetail) }}</div></div>
        <div class="{{ $card }}"><div class="{{ $label }}">Potential profit</div><div class="{{ $money }} text-brand-700">{{ peso($totalRetail - $totalCost) }}</div><div class="text-xs text-slate-500">If everything in stock sells</div></div>
    </div>

    <h2 class="mb-2 font-semibold">Profit</h2>
    <div class="mb-2 grid gap-4 sm:grid-cols-3">
        <div class="{{ $card }}">
            <div class="{{ $label }}">Made from {{ $from->format('M j') }} to {{ $to->format('M j') }}</div>
            <div class="{{ $money }}">{{ peso($profitMade) }}</div>
            <div class="text-xs text-slate-500">Actual sales, {{ $discountsTaken ? 'after discounts' : 'before discounts' }}</div>
        </div>
        <div class="{{ $card }}">
            <div class="{{ $label }}">Expected for the rest of {{ $today->format('F') }}</div>
            <div class="{{ $money }} text-brand-700">{{ peso($expectedMonth) }}</div>
            <div class="text-xs text-slate-500">Estimate, {{ $monthDaysLeft }} day{{ $monthDaysLeft === 1 ? '' : 's' }} left</div>
        </div>
        <div class="{{ $card }}">
            <div class="{{ $label }}">Expected in the next 30 days</div>
            <div class="{{ $money }} text-brand-700">{{ peso($expected30) }}</div>
            <div class="text-xs text-slate-500">Estimate</div>
        </div>
    </div>
    <p class="mb-4 text-sm text-slate-500">
        Estimates use how fast each item sold from {{ $speedFrom->format('M j') }} to {{ $to->format('M j') }} ({{ $speedDays }} day{{ $speedDays === 1 ? '' : 's' }} of sales) at today's prices, before discounts, and stop when an item's stock runs out. Items with no sales in those dates count as zero, and one unusually big sale makes an item look faster than it is.
    </p>

    <div class="card overflow-x-auto">
        <table class="table min-w-[1120px]">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-right">Stock</th>
                    <th class="text-right">Cost</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Value at cost</th>
                    <th class="text-right">Value at price</th>
                    <th class="text-right">Potential profit</th>
                    <th class="text-right">Sold</th>
                    <th class="text-right">Stock lasts</th>
                    <th class="text-right">Expected, rest of {{ $today->format('M') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php
                    $p = $row['product'];
                    $lasts = $row['days_of_stock'];
                    $runsOut = $lasts !== null && $lasts < $monthDaysLeft;
                @endphp
                <tr>
                    <td>{{ $p->name }}<div class="text-xs text-slate-500">{{ $p->category?->name }}</div></td>
                    <td class="text-right whitespace-nowrap {{ $p->isLowStock() ? 'font-semibold text-amber-600' : '' }}">{{ qty($p->stock) }} {{ $p->unit->abbreviation }}</td>
                    <td class="text-right">{{ peso($p->cost_price) }}</td>
                    <td class="text-right">{{ peso($p->selling_price) }}</td>
                    <td class="text-right">{{ peso($p->stock * $p->cost_price) }}</td>
                    <td class="text-right">{{ peso($p->stock * $p->selling_price) }}</td>
                    <td class="text-right">{{ peso($p->stock * ($p->selling_price - $p->cost_price)) }}</td>
                    <td class="text-right whitespace-nowrap">{{ qty($row['sold']) }} {{ $p->unit->abbreviation }}</td>
                    <td class="text-right whitespace-nowrap {{ $runsOut ? 'font-medium text-amber-700' : 'text-slate-500' }}">
                        @if ($p->stock <= 0) Out of stock
                        @elseif ($lasts === null) No sales yet
                        @else {{ $lasts }} day{{ $lasts === 1 ? '' : 's' }}
                        @endif
                    </td>
                    <td class="text-right font-medium">{{ peso($row['expected_month']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
