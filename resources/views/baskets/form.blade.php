@extends('layouts.app', ['title' => $basket->exists ? 'Edit '.$basket->name : 'Add basket'])

@section('content')
    @php
        // After a failed save, the rows come back from the form: one query for all of them
        $oldProducts = old('items') ? \App\Models\Product::with('unit', 'category')->findMany(array_column(old('items'), 'product_id'))->keyBy('id') : collect();
        $rows = old('items') ? collect(old('items'))->map(fn ($row) => [
            'product' => $oldProducts->get($row['product_id'] ?? null)?->toScanArray(),
            'quantity' => $row['quantity'] ?? 1,
        ])->filter(fn ($row) => $row['product'])->values() : $basket->items->map(fn ($item) => [
            'product' => $item->product->toScanArray(),
            'quantity' => (float) $item->quantity,
        ]);
    @endphp
    <form method="POST" action="{{ $basket->exists ? route('baskets.update', $basket) : route('baskets.store') }}" class="card max-w-3xl space-y-5 p-6">
        @csrf
        @if ($basket->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="label" for="basket-name">Basket name</label>
                <input id="basket-name" name="name" value="{{ old('name', $basket->name) }}" class="input" required autofocus placeholder="e.g. Noche Buena basket">
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="basket-barcode">Barcode</label>
                <input id="basket-barcode" name="barcode" value="{{ old('barcode', $basket->barcode) }}" class="input font-mono" placeholder="Leave blank and the system makes one you can print" autocomplete="off" data-scan-keep
                       onkeydown="if (event.key === 'Enter') event.preventDefault();">
            </div>
            <p class="-mt-2 text-xs text-slate-500 sm:col-span-2">To scan the basket's own barcode, click in the Barcode box first. A scan anywhere else adds an item.</p>
        </div>

        <div data-react="BasketItems" data-props='@json(['rows' => $rows, 'price' => old('price', $basket->exists ? (float) $basket->price : '')])'></div>

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $basket->is_active))> On sale (the cashier can sell it)
        </label>

        <div class="flex flex-wrap justify-between gap-2 border-t pt-5">
            <a href="{{ route('baskets.index') }}" class="btn btn-secondary">Cancel</a>
            <button class="btn btn-primary px-6">Save basket</button>
        </div>
    </form>

    @if ($basket->exists)
        <form method="POST" action="{{ route('baskets.destroy', $basket) }}" class="mt-4 max-w-3xl text-right" onsubmit="return confirm('Delete this basket? If it was ever sold, it is only turned off.')">
            @csrf @method('DELETE')
            <button class="tap text-sm font-medium text-red-600 underline">Delete basket</button>
        </form>
    @endif
@endsection
