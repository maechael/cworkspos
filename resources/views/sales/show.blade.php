@extends('layouts.app', ['title' => 'Sale '.$sale->receipt_no])

@section('actions')
    <a href="{{ route('sales.receipt', $sale) }}?print=1" target="_blank" class="btn btn-secondary">Print receipt</a>
    @if (auth()->user()->isAdmin() && $sale->status === 'completed')
        <form method="POST" action="{{ route('sales.void', $sale) }}" onsubmit="return confirm('Void this sale? Items will be returned to stock.')">
            @csrf
            <button class="btn btn-danger">Void sale</button>
        </form>
    @endif
@endsection

@section('content')
    @if ($sale->status === 'voided')
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">This sale was voided on {{ $sale->voided_at->format('M j, Y g:i A') }}.</div>
    @endif
    <div class="grid gap-4 lg:grid-cols-[1fr_300px]">
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                @foreach ($sale->receiptLines() as $line)
                    @if ($line['basket'])
                        <tr>
                            <td>
                                <span class="font-medium">{{ $line['basket'] }}</span>
                                <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">Basket</span>
                                <ul class="mt-1 text-xs text-slate-500">
                                    @foreach ($line['items'] as $item)<li>{{ qty($item->quantity) }} {{ $item->product?->unit?->abbreviation }} {{ $item->product_name }}</li>@endforeach
                                </ul>
                            </td>
                            <td class="text-right">{{ qty($line['qty']) }}</td>
                            <td class="text-right">{{ peso($line['total'] / max($line['qty'], 1)) }}</td>
                            <td class="text-right font-medium">{{ peso($line['total']) }}</td>
                        </tr>
                    @else
                        @php $item = $line['item']; @endphp
                        <tr>
                            <td>
                                {{ $item->product_name }}
                                @if ($item->free_qty > 0)<div class="text-xs font-medium text-amber-700">{{ $item->promo }}: {{ qty($item->free_qty) }} free, −{{ peso((float) $item->free_qty * (float) $item->unit_price) }}</div>@endif
                            </td>
                            <td class="text-right">{{ qty($item->quantity) }} {{ $item->product?->unit?->abbreviation }}</td>
                            <td class="text-right">{{ peso($item->unit_price) }}</td>
                            <td class="text-right font-medium">{{ peso($item->line_total) }}</td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        <dl class="card h-fit space-y-2 p-5 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>{{ $sale->created_at->format('M j, Y g:i A') }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Cashier</dt><dd>{{ $sale->user->name }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Payment</dt><dd class="uppercase">{{ $sale->payment_method }}</dd></div>
            <div class="flex justify-between border-t pt-2"><dt class="text-slate-500">Subtotal</dt><dd>{{ peso($sale->subtotal) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd>−{{ peso($sale->discount) }}</dd></div>
            <div class="flex justify-between text-lg font-bold"><dt>Total</dt><dd>{{ peso($sale->total) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Paid</dt><dd>{{ peso($sale->amount_paid) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Change</dt><dd>{{ peso($sale->change) }}</dd></div>
        </dl>
    </div>
@endsection
