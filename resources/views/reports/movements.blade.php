@extends('layouts.app', ['title' => 'Reports'])

@section('content')
    @include('reports.tabs')
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <div><label class="label">Product</label><input name="q" value="{{ request('q') }}" class="input" placeholder="Name or barcode"></div>
        <div>
            <label class="label">Type</label>
            <select name="type" class="input">
                <option value="">All</option>
                @foreach (['delivery' => 'Delivery', 'sale' => 'Sale', 'void' => 'Void', 'count' => 'Count adjustment', 'adjust' => 'Stock adjustment', 'opening' => 'Opening stock'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-secondary">Filter</button>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Reference</th><th class="text-right">Change</th><th class="text-right">Balance</th><th>By</th></tr></thead>
            <tbody>
            @forelse ($movements as $m)
                <tr>
                    <td class="whitespace-nowrap">{{ $m->created_at->format('M j, Y g:i A') }}</td>
                    <td>{{ $m->product->name }}</td>
                    <td>@include('partials.movement-type', ['type' => $m->type])</td>
                    <td class="text-xs text-slate-500">{{ $m->reference }}</td>
                    <td class="text-right font-medium {{ $m->quantity < 0 ? 'text-red-600' : 'text-brand-700' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ qty($m->quantity) }}</td>
                    <td class="text-right">{{ qty($m->balance_after) }} {{ $m->product->unit->abbreviation }}</td>
                    <td>{{ $m->user?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-400">No movements.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $movements->links() }}</div>
@endsection
