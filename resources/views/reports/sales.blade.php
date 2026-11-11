@extends('layouts.app', ['title' => 'Reports'])

@php
    $gross = $daily->sum('gross');
    $profit = $daily->sum('profit');
@endphp

@section('content')
    @include('reports.tabs')
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <div><label class="label">From</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="input"></div>
        <div><label class="label">To</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="input"></div>
        <button class="btn btn-secondary">Show</button>
    </form>
    <p class="mb-4 text-sm text-slate-500">Sales from {{ $from->format('M j, Y') }} to {{ $to->format('M j, Y') }} (voided sales excluded)</p>

    <div class="grid gap-4 sm:grid-cols-4">
        <div class="card p-4"><div class="text-sm text-slate-500">Item sales</div><div class="text-2xl font-bold">{{ peso($gross) }}</div></div>
        <div class="card p-4"><div class="text-sm text-slate-500">Discounts</div><div class="text-2xl font-bold">{{ peso($discounts) }}</div></div>
        <div class="card p-4"><div class="text-sm text-slate-500">Net sales</div><div class="text-2xl font-bold">{{ peso($gross - $discounts) }}</div></div>
        <div class="card p-4"><div class="text-sm text-slate-500">Gross profit</div><div class="text-2xl font-bold text-brand-700">{{ peso($profit - $discounts) }}</div>
            <div class="text-xs text-slate-500">{{ $gross > 0 ? number_format(($profit - $discounts) / $gross * 100, 1) : 0 }}% margin</div></div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="card overflow-x-auto">
            <h2 class="px-5 py-4 font-semibold">By day</h2>
            <table class="table">
                <thead><tr><th>Date</th><th class="text-right">Transactions</th><th class="text-right">Sales</th><th class="text-right">Profit</th></tr></thead>
                <tbody>
                @forelse ($daily as $d)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($d->day)->format('D, M j') }}</td><td class="text-right">{{ $d->transactions }}</td><td class="text-right">{{ peso($d->gross) }}</td><td class="text-right">{{ peso($d->profit) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-slate-400">No sales in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto">
            <h2 class="px-5 py-4 font-semibold">By category</h2>
            <table class="table">
                <thead><tr><th>Category</th><th class="text-right">Sales</th><th class="text-right">Profit</th><th class="text-right">Share</th></tr></thead>
                <tbody>
                @foreach ($byCategory as $c)
                    <tr><td>{{ $c->category }}</td><td class="text-right">{{ peso($c->gross) }}</td><td class="text-right">{{ peso($c->profit) }}</td><td class="text-right">{{ $gross > 0 ? number_format($c->gross / $gross * 100, 1) : 0 }}%</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4 overflow-x-auto">
        <h2 class="px-5 py-4 font-semibold">By product (top 50)</h2>
        <table class="table">
            <thead><tr><th>Product</th><th class="text-right">Qty sold</th><th class="text-right">Sales</th><th class="text-right">Profit</th></tr></thead>
            <tbody>
            @foreach ($byProduct as $p)
                <tr><td>{{ $p->product_name }}</td><td class="text-right">{{ qty($p->qty) }}</td><td class="text-right">{{ peso($p->gross) }}</td><td class="text-right">{{ peso($p->profit) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
