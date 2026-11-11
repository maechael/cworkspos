@extends('layouts.app', ['title' => 'Baskets'])

@section('actions')
    <a href="{{ route('baskets.create') }}" class="btn btn-primary">+ Add basket</a>
@endsection

@section('content')
    <p class="mb-4 max-w-3xl text-sm text-slate-500">
        A basket is several items sold together at one price, such as a gift or Noche Buena basket. The cashier scans the basket's barcode or picks it under "Baskets", and each item inside comes out of stock.
    </p>
    <div class="mb-3 flex gap-2 text-sm">
        <a href="{{ route('baskets.index') }}" class="btn {{ request('show') !== 'all' ? 'btn-primary' : 'btn-secondary' }}" @if (request('show') !== 'all') aria-current="true" @endif>On sale</a>
        <a href="{{ route('baskets.index', ['show' => 'all']) }}" class="btn {{ request('show') === 'all' ? 'btn-primary' : 'btn-secondary' }}" @if (request('show') === 'all') aria-current="true" @endif>All, including turned off</a>
    </div>

    <div class="card overflow-x-auto">
        <table class="table min-w-[900px]">
            <thead>
                <tr>
                    <th>Basket</th>
                    <th>Inside</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Bought separately</th>
                    <th class="text-right">Profit each</th>
                    <th class="text-right">Can make</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($baskets as $basket)
                @php
                    $regular = $basket->regularPrice();
                    $profit = (float) $basket->price - $basket->cost();
                    $canMake = $basket->available();
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('baskets.edit', $basket) }}" class="font-medium hover:underline">{{ $basket->name }}</a>
                        @unless ($basket->is_active)<span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Off</span>@endunless
                        <div class="font-mono text-xs text-slate-400">{{ $basket->barcode }}</div>
                    </td>
                    <td class="text-sm text-slate-600">
                        @foreach ($basket->items as $item)
                            <div>{{ qty($item->quantity) }} {{ $item->product->unit->abbreviation }} {{ $item->product->name }}</div>
                        @endforeach
                    </td>
                    <td class="text-right font-semibold">{{ peso($basket->price) }}</td>
                    <td class="text-right text-slate-600">
                        {{ peso($regular) }}
                        @if ($regular > (float) $basket->price)<div class="text-xs text-brand-700">Saves {{ peso($regular - (float) $basket->price) }}</div>@endif
                    </td>
                    <td class="text-right {{ $profit < 0 ? 'font-semibold text-red-600' : '' }}">{{ peso($profit) }}</td>
                    <td class="text-right {{ $canMake <= 0 ? 'font-semibold text-amber-600' : '' }}">{{ qty($canMake) }}</td>
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('products.labels', ['basket_ids' => [$basket->id]]) }}" class="btn btn-secondary py-1 text-sm">Print label</a>
                        <a href="{{ route('baskets.edit', $basket) }}" class="btn btn-secondary py-1 text-sm">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">No baskets yet. <a href="{{ route('baskets.create') }}" class="font-medium text-brand-700 underline">Add the first one</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
