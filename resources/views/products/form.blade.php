@extends('layouts.app', ['title' => $product->exists ? 'Edit '.$product->name : 'Add product'])

@section('content')
    <form method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}" class="card max-w-3xl space-y-5 p-6">
        @csrf
        @if ($product->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="label">Barcode</label>
                <input name="barcode" value="{{ old('barcode', $product->barcode) }}" class="input font-mono" placeholder="Scan the item's barcode here" autofocus
                       onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.form.name.focus(); }">
                <p class="mt-1 text-xs text-slate-500">Leave blank for items without a barcode (loose rice, eggs). The system makes one you can print as a label.</p>
            </div>
            <div class="sm:col-span-2">
                <label class="label">Product name</label>
                <input name="name" value="{{ old('name', $product->name) }}" class="input" required>
            </div>
            <div>
                <label class="label">Category</label>
                <select name="category_id" class="input">
                    <option value="">— None —</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Sold by</label>
                <select name="unit_id" class="input" required>
                    @foreach ($units as $u)
                        <option value="{{ $u->id }}" @selected(old('unit_id', $product->unit_id) == $u->id)>{{ $u->name }} ({{ $u->abbreviation }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Cost price (₱)</label>
                <input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price', $product->cost_price ?? 0) }}" class="input" required>
            </div>
            <div>
                <label class="label">Selling price (₱)</label>
                <input type="number" step="0.01" min="0" name="selling_price" value="{{ old('selling_price', $product->selling_price ?? 0) }}" class="input" required>
            </div>
            <div>
                <label class="label">Reorder level</label>
                <input type="number" step="0.001" min="0" name="reorder_level" value="{{ old('reorder_level', $product->exists ? qty($product->reorder_level) : 5) }}" class="input" required>
                <p class="mt-1 text-xs text-slate-500">Shows as low stock at or below this amount.</p>
            </div>
            @unless ($product->exists)
                <div>
                    <label class="label">Opening stock</label>
                    <input type="number" step="0.001" min="0" name="stock" value="{{ old('stock', 0) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Use “Receive delivery” for stock with expiry dates.</p>
                </div>
            @else
                <div>
                    <label class="label">Current stock</label>
                    <div class="input bg-slate-50">{{ qty($product->stock) }} {{ $product->unit->abbreviation }}</div>
                    <p class="mt-1 text-xs text-slate-500">Changes through deliveries and sales. <a href="{{ route('products.show', ['product' => $product, 'adjust' => 1]) }}" class="hit font-medium text-brand-700 underline">Adjust stock</a></p>
                </div>
            @endunless
        </div>

        <fieldset class="rounded-xl border border-slate-200 p-4" id="promo-fields">
            <legend class="px-1 text-sm font-semibold">Promo</legend>
            <p class="mb-3 text-xs text-slate-500">Buy 1 Take 1 and the like. The cashier scans every item, including the free ones, and the free ones come off by themselves. Leave blank for no promo. Not for items sold by weight.</p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="promo_buy">Buy</label>
                    <input id="promo_buy" type="number" min="1" step="1" name="promo_buy" value="{{ old('promo_buy', $product->promo_buy) }}" class="input w-24">
                </div>
                <div>
                    <label class="label" for="promo_take">Take free</label>
                    <input id="promo_take" type="number" min="1" step="1" name="promo_take" value="{{ old('promo_take', $product->promo_take) }}" class="input w-24">
                </div>
                <div>
                    <label class="label" for="promo_until">Ends on (optional)</label>
                    <input id="promo_until" type="date" name="promo_until" value="{{ old('promo_until', $product->promo_until?->toDateString()) }}" class="input">
                </div>
                <button type="button" class="btn btn-secondary" data-promo="1,1">Buy 1 Take 1</button>
                <button type="button" class="btn btn-secondary" data-promo="2,1">Buy 2 Take 1</button>
                <button type="button" class="btn btn-secondary" data-promo="">No promo</button>
            </div>
            @if ($product->exists && $product->promo_buy && ! $product->promoActive())
                <p class="mt-2 text-xs font-medium text-amber-700">This promo is off{{ $product->promo_until?->isPast() ? ', it ended on '.$product->promo_until->format('M j, Y') : '' }}.</p>
            @endif
        </fieldset>
        <script>
            document.querySelectorAll('#promo-fields [data-promo]').forEach((button) => button.addEventListener('click', () => {
                const [buy, take] = button.dataset.promo ? button.dataset.promo.split(',') : ['', ''];
                const form = button.form;
                form.promo_buy.value = buy;
                form.promo_take.value = take;
                if (!buy) form.promo_until.value = '';
            }));
        </script>

        @php
            $extraRows =$product->extraBarcodes->map(fn ($b) => ['barcode' => $b->barcode, 'pieces' => (float) $b->pieces, 'label' => $b->label])->all();
        @endphp
        <div data-react="ProductBarcodes" data-props='@json(['rows' => old('extra_barcodes', $extraRows)])'></div>

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> Active (can be sold)
        </label>

        <div class="flex justify-between gap-2 border-t pt-5">
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
            <button class="btn btn-primary px-6">Save product</button>
        </div>
    </form>
@endsection
