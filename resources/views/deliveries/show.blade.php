@extends('layouts.app', ['title' => 'Delivery DR-'.str_pad($delivery->id, 5, '0', STR_PAD_LEFT)])

@section('actions')
    <a href="{{ route('deliveries.create') }}" class="btn btn-primary">Receive another</a>
@endsection

@section('content')
    <div class="card mb-4 grid gap-4 p-5 text-sm sm:grid-cols-4">
        <div><div class="text-slate-500">Supplier</div><div class="font-semibold">{{ $delivery->supplier?->name ?? '—' }}</div></div>
        <div><div class="text-slate-500">Invoice / DR</div><div class="font-semibold">{{ $delivery->reference_no ?? '—' }}</div></div>
        <div><div class="text-slate-500">Received</div><div class="font-semibold">{{ $delivery->received_at->format('M j, Y') }} by {{ $delivery->user->name }}</div></div>
        <div><div class="text-slate-500">Total cost</div><div class="text-xl font-bold">{{ peso($delivery->total_cost) }}</div></div>
        @if ($delivery->notes)<div class="sm:col-span-4"><div class="text-slate-500">Notes</div>{{ $delivery->notes }}</div>@endif
    </div>
    @php
        // Lines split from the same item stay next to each other
        $lines = $delivery->items->sortBy([['product_id', 'asc'], ['id', 'asc']]);
        $onShelf = $lines->filter(fn ($item) => $item->remaining_qty > 0);
        $noDate = $onShelf->whereNull('expiry_date')->count();
    @endphp
    <form id="expiry-form" method="POST" action="{{ route('deliveries.expiry', $delivery) }}">@csrf @method('PUT')</form>
    @foreach ($onShelf as $item)
        <form id="split-{{ $item->id }}" method="POST" action="{{ route('deliveries.items.split', [$delivery, $item]) }}">@csrf</form>
    @endforeach

    <div class="card overflow-x-auto">
        <table class="table min-w-[860px]">
            <thead><tr><th>Product</th><th class="text-right">Received</th><th class="text-right">Still on shelf</th><th class="text-right">Unit cost</th><th class="w-72">Expiry date</th><th class="text-right">Line cost</th></tr></thead>
            <tbody>
            @foreach ($lines as $item)
                @php($unit = $item->product->unit)
                <tr>
                    <td class="align-top"><a href="{{ route('products.show', $item->product) }}" class="hover:underline">{{ $item->product->name }}</a></td>
                    <td class="text-right align-top">{{ qty($item->quantity) }} {{ $unit->abbreviation }}</td>
                    <td class="text-right align-top">{{ qty($item->remaining_qty) }}</td>
                    <td class="text-right align-top">{{ peso($item->unit_cost) }}</td>
                    <td class="align-top">
                        @if ($item->remaining_qty > 0)
                            <input type="date" name="expiry[{{ $item->id }}]" form="expiry-form" value="{{ old('expiry.'.$item->id, $item->expiry_date?->format('Y-m-d')) }}" aria-label="Expiry date of {{ $item->product->name }}" class="input py-1 {{ $item->expiry_date ? '' : 'border-amber-500' }}">
                            @unless ($item->expiry_date)<div class="mt-0.5 text-xs font-medium text-amber-700">No date yet</div>@endunless
                            @if ($item->quantity > 1)
                                <details class="mt-1">
                                    <summary class="tap cursor-pointer text-xs font-medium text-brand-700">Some have another date</summary>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                        Move
                                        <input type="number" name="quantity" form="split-{{ $item->id }}" min="0" max="{{ (float) $item->remaining_qty }}" step="{{ $unit->allows_decimal ? '0.001' : '1' }}" required aria-label="How many of {{ $item->product->name }} to move" class="input w-20 py-1">
                                        {{ $unit->abbreviation }} to
                                        <input type="date" name="expiry_date" form="split-{{ $item->id }}" required aria-label="Their expiry date" class="input w-40 py-1">
                                        <button form="split-{{ $item->id }}" class="btn btn-secondary px-3 py-1">Move</button>
                                    </div>
                                </details>
                            @endif
                        @else
                            {{ $item->expiry_date?->format('M j, Y') ?? '—' }} <span class="text-xs text-slate-500">· all sold</span>
                        @endif
                    </td>
                    <td class="text-right align-top font-medium">{{ peso($item->quantity * $item->unit_cost) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($onShelf->isNotEmpty())
        <div class="mt-3 flex flex-wrap items-center justify-end gap-3">
            <p class="text-sm text-slate-500">
                @if ($noDate){{ $noDate }} line{{ $noDate === 1 ? '' : 's' }} still {{ $noDate === 1 ? 'needs a date' : 'need dates' }}. @endif
                Items with no date won't warn you before they expire.
            </p>
            <button form="expiry-form" class="btn btn-primary px-6">Save dates</button>
        </div>
    @endif
@endsection
