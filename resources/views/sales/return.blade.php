@extends('layouts.app', ['title' => 'Return items: '.$sale->receipt_no])

@section('actions')
    <a href="{{ route('sales.show', $sale) }}" class="btn btn-secondary">Back to the sale</a>
@endsection

@section('content')
    <form id="return-form" method="POST" action="{{ route('sales.return.store', $sale) }}"
          data-subtotal="{{ (float) $sale->subtotal }}" data-total="{{ (float) $sale->total }}">
        @csrf
        <p class="mb-3 text-sm text-slate-500">
            Sold {{ $sale->created_at->format('M j, Y g:i A') }} by {{ $sale->user->name }}, paid by {{ strtoupper($sale->payment_method) }}.
            Enter how many of each item came back. The sale, the reports and the stock are updated to match.
        </p>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Still with the shopper</th>
                        <th class="w-36">Coming back</th>
                        <th>Back on the shelf?</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($sale->receiptLines() as $line)
                    @php
                        $key = $line['key'];
                        if ($line['basket']) {
                            $left = $line['qty']; $unit = $left == 1 ? 'basket' : 'baskets'; $step = 1; $paid = $line['total'];
                        } else {
                            $item = $line['item']; $left = (float) $item->quantity; $unit = $item->product?->unit?->abbreviation;
                            $step = $item->product?->unit?->allows_decimal ? '0.001' : 1; $paid = (float) $item->line_total;
                        }
                    @endphp
                    <tr data-line
                        data-left="{{ $left }}"
                        data-paid="{{ $paid }}"
                        @if (! $line['basket'])
                            data-price="{{ (float) $item->unit_price }}" data-free="{{ (float) $item->free_qty }}"
                        @endif>
                        <td>
                            @if ($line['basket'])
                                <span class="font-medium">{{ $line['basket'] }}</span>
                                <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">Basket</span>
                                <div class="text-xs text-slate-500">Comes back by the whole basket:
                                    {{ collect($line['items'])->map(fn ($i) => qty((float) $i->quantity / max($line['qty'], 1)).' '.$i->product_name)->join(', ') }}
                                    each</div>
                            @else
                                <span class="font-medium">{{ $item->product_name }}</span>
                                @if ($item->free_qty > 0)<div class="text-xs font-medium text-amber-700">{{ $item->promo }}: {{ qty($item->free_qty) }} free. Free ones come back first, for no money.</div>@endif
                                @if ($item->returned_qty > 0)<div class="text-xs text-slate-500">{{ qty($item->returned_qty) }} already returned</div>@endif
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">{{ peso($paid) }}</td>
                        <td class="text-right whitespace-nowrap">{{ qty($left) }} {{ $unit }}</td>
                        <td>
                            @if ($left > 0)
                                <label class="sr-only" for="qty-{{ $key }}">How many came back</label>
                                <input id="qty-{{ $key }}" type="number" name="lines[{{ $key }}][qty]" min="0" max="{{ $left }}" step="{{ $step }}"
                                       value="{{ old("lines.$key.qty") }}" placeholder="0" class="input w-28" inputmode="decimal">
                            @else
                                <span class="text-sm text-slate-400">All returned</span>
                            @endif
                        </td>
                        <td>
                            @if ($left > 0)
                                <input type="hidden" name="lines[{{ $key }}][restock]" value="0">
                                <label class="inline-flex min-h-10 items-center gap-2 text-sm">
                                    <input type="checkbox" name="lines[{{ $key }}][restock]" value="1" data-restock @checked(old("lines.$key.restock", '1') === '1')>
                                    Yes, it can be sold again
                                </label>
                            @else
                                <span class="text-sm text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card mt-4 p-5">
            <div class="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-[220px_minmax(0,1fr)_180px]">
                <div>
                    <label class="label" for="return-reason">Reason</label>
                    <select id="return-reason" name="reason" class="input" required>
                        <option value="">Choose a reason</option>
                        @foreach (\App\Models\SaleReturn::REASONS as $reason)
                            <option value="{{ $reason }}" @selected(old('reason') === $reason)
                                    @if (in_array($reason, \App\Models\SaleReturn::NOT_SELLABLE)) data-not-sellable @endif>{{ $reason }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="return-note">Note (needed for Other)</label>
                    <input id="return-note" name="note" value="{{ old('note') }}" maxlength="200" class="input" autocomplete="off" placeholder="e.g. can was dented">
                </div>
                <div>
                    <label class="label" for="return-method">Money given back by</label>
                    <select id="return-method" name="refund_method" class="input" required>
                        @foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('refund_method', $sale->payment_method) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p id="return-preview" role="status" class="min-h-5 text-sm font-medium text-slate-700"></p>
                <button class="btn btn-primary">Save return</button>
            </div>
        </div>
    </form>

    <script>
        // Shows the refund before saving, worked out the same way as the server (app/Services/SaleReturns.php)
        (() => {
            const form = document.getElementById('return-form');
            const subtotal = Number(form.dataset.subtotal);
            const total = Number(form.dataset.total);
            const peso = (n) => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const round2 = (n) => Math.round(n * 100) / 100;
            const rows = [...form.querySelectorAll('[data-line]')];

            const show = () => {
                let gross = 0, any = false, allBack = true, tooMany = false;
                rows.forEach((row) => {
                    const input = row.querySelector('input[type=number]');
                    const left = Number(row.dataset.left), paid = Number(row.dataset.paid);
                    const want = input ? Number(input.value || 0) : 0;
                    if (want > left + 0.0005) tooMany = true;
                    if (want > 0) any = true;
                    if (left - want > 0.0005) allBack = false;
                    if (want <= 0) return;
                    if (want >= left - 0.0005) { gross += paid; return; }
                    if (row.dataset.price === undefined) { gross += round2(paid * want / left); return; } // basket share
                    const freeBack = Math.min(want, Number(row.dataset.free));
                    gross += Math.min(round2((want - freeBack) * Number(row.dataset.price)), paid);
                });
                const preview = document.getElementById('return-preview');
                if (tooMany) { preview.textContent = 'One of the amounts is more than the shopper still has.'; return; }
                if (!any) { preview.textContent = 'Nothing entered yet.'; return; }
                const refund = allBack ? total : (subtotal > 0 ? round2(round2(gross) * total / subtotal) : 0);
                const method = form.refund_method.selectedOptions[0].textContent;
                preview.textContent = `Give back ${peso(refund)} by ${method}. The sale's total goes from ${peso(total)} to ${peso(round2(total - refund))}.`;
            };

            form.addEventListener('input', show);
            form.addEventListener('change', show);
            // Damaged or expired items usually can't be sold again, so untick "back on the shelf" (it can be ticked again)
            form.reason.addEventListener('change', () => {
                if (form.reason.selectedOptions[0]?.hasAttribute('data-not-sellable')) {
                    form.querySelectorAll('[data-restock]').forEach((box) => { box.checked = false; });
                }
            });
            show();
        })();
    </script>
@endsection
