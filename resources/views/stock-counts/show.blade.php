@extends('layouts.app', ['title' => 'Stock count SC-'.str_pad($count->id, 5, '0', STR_PAD_LEFT)])

@section('actions')
    <button onclick="window.print()" class="btn btn-secondary">Print</button>
@endsection

@section('content')
    <p class="mb-4 text-sm text-slate-500">Counted by {{ $count->user->name }} on {{ $count->created_at->format('M j, Y g:i A') }}. {{ $count->notes }}</p>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Product</th><th class="text-right">On record</th><th class="text-right">Counted</th><th class="text-right">Difference</th><th class="text-right">Value at cost</th></tr></thead>
            <tbody>
            @foreach ($count->items as $item)
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td class="text-right">{{ qty($item->system_qty) }} {{ $item->product->unit->abbreviation }}</td>
                    <td class="text-right">{{ qty($item->counted_qty) }}</td>
                    <td class="text-right font-semibold {{ $item->variance < 0 ? 'text-red-600' : ($item->variance > 0 ? 'text-amber-600' : 'text-brand-700') }}">{{ $item->variance > 0 ? '+' : '' }}{{ qty($item->variance) }}</td>
                    <td class="text-right">{{ peso($item->variance * $item->product->cost_price) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
