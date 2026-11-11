@extends('layouts.app', ['title' => $product->name])

@section('actions')
    <a href="{{ route('products.labels', ['ids' => [$product->id], 'copies' => 12]) }}" target="_blank" class="btn btn-secondary">Print labels</a>
    <a href="{{ route('products.edit', $product) }}" class="btn btn-primary">Edit</a>
@endsection

@section('content')
    <div class="grid gap-4 sm:grid-cols-4">
        <div class="card p-4">
            <div class="text-sm text-slate-500">Barcode{{ $product->extraBarcodes->isNotEmpty() ? 's' : '' }}</div>
            <div class="font-mono font-semibold">{{ $product->barcode }}</div>
            @foreach ($product->extraBarcodes as $extra)
                <div class="mt-1 text-xs text-slate-500"><span class="font-mono">{{ $extra->barcode }}</span>@if ($extra->label) · {{ $extra->label }}@endif @if ((float) $extra->pieces !== 1.0) · {{ qty($extra->pieces) }} {{ $product->unit->abbreviation }} per scan @endif</div>
            @endforeach
        </div>
        <div class="card p-4"><div class="text-sm text-slate-500">Price</div><div class="text-xl font-bold">{{ peso($product->selling_price) }} <span class="text-sm font-normal text-slate-400">/ {{ $product->unit->abbreviation }}</span></div><div class="text-xs text-slate-500">Cost {{ peso($product->cost_price) }}</div>@if ($product->promoActive())<div class="mt-1 text-xs font-semibold text-amber-700">{{ $product->promoLabel() }}{{ $product->promo_until ? ' until '.$product->promo_until->format('M j') : '' }}</div>@endif</div>
        <div class="card p-4"><div class="text-sm text-slate-500">In stock</div><div class="text-xl font-bold {{ $product->isLowStock() ? 'text-amber-600' : '' }}">{{ qty($product->stock) }} {{ $product->unit->abbreviation }}</div><div class="text-xs text-slate-500">Reorder at {{ qty($product->reorder_level) }}</div></div>
        <div class="card p-4"><div class="text-sm text-slate-500">Category</div><div class="text-xl font-bold">{{ $product->category?->name ?? '—' }}</div></div>
    </div>

    <form id="adjust-stock" method="POST" action="{{ route('products.adjust-stock', $product) }}" class="card mt-4 p-5" data-current="{{ (float) $product->stock }}" data-unit="{{ $product->unit->abbreviation }}">
        @csrf
        <h2 class="font-semibold">Adjust stock</h2>
        <p class="mt-0.5 text-sm text-slate-500">If the shelf doesn't match the record, enter what is really there. The change is saved in the stock history with the reason and your name.</p>
        <div class="mt-3 grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-[160px_200px_minmax(0,1fr)_auto]">
            <div>
                <label class="label" for="adjust-counted">Actual stock ({{ $product->unit->abbreviation }})</label>
                <input id="adjust-counted" type="number" name="counted" min="0" step="{{ $product->unit->allows_decimal ? '0.001' : '1' }}" value="{{ old('counted') }}" class="input" required>
            </div>
            <div>
                <label class="label" for="adjust-reason">Reason</label>
                <select id="adjust-reason" name="reason" class="input" required>
                    <option value="">Choose a reason</option>
                    @foreach (\App\Models\Product::ADJUST_REASONS as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="adjust-note">Note (needed for Other)</label>
                <input id="adjust-note" name="note" value="{{ old('note') }}" maxlength="200" class="input" autocomplete="off" placeholder="e.g. 3 dented cans">
            </div>
            <div class="lg:pt-6"><button class="btn btn-primary w-full whitespace-nowrap">Save adjustment</button></div>
        </div>
        <p id="adjust-preview" role="status" class="mt-2 min-h-5 text-sm font-medium text-slate-700"></p>
    </form>
    <script>
        // Shows what saving would do before it is saved
        (() => {
            const form = document.getElementById('adjust-stock');
            const current = Number(form.dataset.current);
            const unit = form.dataset.unit;
            const text = (n) => n.toLocaleString('en-PH', { maximumFractionDigits: 3 });
            const show = () => {
                const raw = form.counted.value;
                const preview = document.getElementById('adjust-preview');
                if (raw === '') { preview.textContent = ''; return; }
                const change = Math.round((Number(raw) - current) * 1000) / 1000;
                preview.textContent = change === 0
                    ? 'Same as the record, so nothing will change.'
                    : `Stock goes from ${text(current)} to ${text(Number(raw))} ${unit} (${change > 0 ? '+' : '−'}${text(Math.abs(change))}).`;
            };
            form.counted.addEventListener('input', show);
            show();
            // Arriving from an "Adjust stock" link: the cursor is ready for the count
            if (new URLSearchParams(location.search).has('adjust')) {
                form.scrollIntoView({ block: 'center' });
                form.counted.focus({ preventScroll: true });
            }
        })();
    </script>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="card overflow-x-auto">
            <h2 class="px-5 py-4 font-semibold">Stock by delivery (expiry)</h2>
            <table class="table">
                <thead><tr><th>Received</th><th>Supplier</th><th>Expiry</th><th class="text-right">Left</th></tr></thead>
                <tbody>
                @forelse ($lots as $lot)
                    <tr>
                        <td><a class="hover:underline" href="{{ route('deliveries.show', $lot->delivery_id) }}">{{ $lot->delivery->received_at->format('M j, Y') }}</a></td>
                        <td>{{ $lot->delivery->supplier?->name ?? '—' }}</td>
                        <td class="{{ $lot->expiry_date && $lot->expiry_date->lte(now()->addDays(30)) ? 'font-semibold text-red-600' : '' }}">{{ $lot->expiry_date?->format('M j, Y') ?? 'No expiry' }}</td>
                        <td class="text-right">{{ qty($lot->remaining_qty) }} / {{ qty($lot->quantity) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-slate-400">No delivery lots on hand.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto">
            <h2 class="px-5 py-4 font-semibold">Recent stock movements</h2>
            <table class="table">
                <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th class="text-right">Change</th><th class="text-right">Balance</th></tr></thead>
                <tbody>
                @forelse ($movements as $m)
                    <tr>
                        <td class="whitespace-nowrap">{{ $m->created_at->format('M j, g:i A') }}</td>
                        <td>@include('partials.movement-type', ['type' => $m->type])</td>
                        <td class="text-xs text-slate-500">{{ $m->reference }}@if ($m->type === 'adjust' && $m->user)<div>by {{ $m->user->name }}</div>@endif</td>
                        <td class="text-right font-medium {{ $m->quantity < 0 ? 'text-red-600' : 'text-brand-700' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ qty($m->quantity) }}</td>
                        <td class="text-right">{{ qty($m->balance_after) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-slate-400">No movements yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
