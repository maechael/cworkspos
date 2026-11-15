@extends('layouts.app', ['title' => 'Sale '.$sale->receipt_no])

@section('actions')
    @php $viewer = auth()->user(); @endphp
    <a href="{{ $sale->linkFor('sales.receipt', $viewer, ['print' => 1]) }}" target="_blank" class="btn btn-secondary">Print receipt</a>
    @if ($sale->canReturn())
        <a href="{{ $sale->linkFor('sales.return', $viewer) }}" class="btn btn-secondary">Return items</a>
    @endif
    @if ($sale->status === 'completed')
        @if ($viewer->isAdmin())
            <form method="POST" action="{{ route('sales.void', $sale) }}" onsubmit="return confirm('Void this sale? Items will be returned to stock.')">
                @csrf
                <button class="btn btn-danger">Void sale</button>
            </form>
        @else
            <a href="{{ $sale->linkFor('sales.void.form', $viewer) }}" class="btn btn-danger">Void sale</a>
        @endif
    @endif
@endsection

@section('content')
    @if ($sale->status === 'voided')
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
            @php
                // A cashier's void with no approver went through on auto-approve
                $who = ($sale->voider ? ' by '.$sale->voider->name : '')
                    .($sale->voidApprover ? ', approved by '.$sale->voidApprover->name : ($sale->voider && ! $sale->voider->isAdmin() ? ', auto-approved' : ''));
            @endphp
            This sale was voided on {{ $sale->voided_at->format('M j, Y g:i A') }}{{ $who }}.
        </div>
    @endif
    <div class="grid gap-4 lg:grid-cols-[1fr_300px]">
        <div class="min-w-0 space-y-4">
            <div class="card overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                    @foreach ($sale->receiptLines() as $line)
                        @if ($line['basket'])
                            <tr @class(['text-slate-400' => $line['qty'] <= 0])>
                                <td>
                                    <span class="font-medium">{{ $line['basket'] }}</span>
                                    <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">Basket</span>
                                    <ul class="mt-1 text-xs text-slate-500">
                                        @foreach ($line['items'] as $item)<li>{{ qty($item->quantity) }} {{ $item->product?->unit?->abbreviation }} {{ $item->product_name }}</li>@endforeach
                                    </ul>
                                    @if ($line['items'][0]->returned_qty > 0)<div class="text-xs font-medium text-red-700">{{ $line['qty'] <= 0 ? 'All returned' : 'Some returned' }}</div>@endif
                                </td>
                                <td class="text-right">{{ qty($line['qty']) }}</td>
                                <td class="text-right">{{ $line['qty'] > 0 ? peso($line['total'] / $line['qty']) : '—' }}</td>
                                <td class="text-right font-medium">{{ peso($line['total']) }}</td>
                            </tr>
                        @else
                            @php $item = $line['item']; @endphp
                            <tr @class(['text-slate-400' => (float) $item->quantity <= 0])>
                                <td>
                                    {{ $item->product_name }}
                                    @if ($item->free_qty > 0)<div class="text-xs font-medium text-amber-700">{{ $item->promo }}: {{ qty($item->free_qty) }} free, −{{ peso((float) $item->free_qty * (float) $item->unit_price) }}</div>@endif
                                    @if ($item->returned_qty > 0)<div class="text-xs font-medium text-red-700">{{ (float) $item->quantity <= 0 ? 'All returned' : qty($item->returned_qty).' returned' }}</div>@endif
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

            @if ($sale->returns->isNotEmpty())
                <div class="card overflow-x-auto">
                    <h2 class="px-5 pt-4 pb-1 font-semibold">Returns</h2>
                    <table class="table table-stack">
                        <thead><tr><th>Return</th><th>What came back</th><th>Reason</th><th class="text-right">Refund</th></tr></thead>
                        <tbody>
                        @foreach ($sale->returns as $return)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="font-medium">{{ $return->return_no }}</span>
                                    <div class="text-xs text-slate-500">{{ $return->created_at->format('M j, Y g:i A') }}, by {{ $return->user->name }}@if ($return->approver), approved by {{ $return->approver->name }}@elseif (! $return->user->isAdmin()), auto-approved @endif</div>
                                </td>
                                <td>
                                    <ul class="text-sm">
                                        @foreach ($return->items as $ri)
                                            <li>{{ qty($ri->quantity) }} {{ $ri->product_name }}@if ($ri->basket_name) <span class="text-xs text-slate-500">({{ $ri->basket_name }})</span>@endif
                                                <span class="text-xs {{ $ri->restocked ? 'text-brand-700' : 'text-slate-500' }}">· {{ $ri->restocked ? 'back on the shelf' : 'not put back' }}</span></li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="text-sm">{{ $return->reason }}@if ($return->note)<div class="text-xs text-slate-500">{{ $return->note }}</div>@endif</td>
                                <td class="text-right whitespace-nowrap font-medium">{{ peso($return->refund) }}<div class="text-xs text-slate-500 uppercase">{{ $return->refund_method }}</div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        <dl class="card h-fit space-y-2 p-5 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>{{ $sale->created_at->format('M j, Y g:i A') }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Cashier</dt><dd>{{ $sale->user->name }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Payment</dt><dd class="uppercase">{{ $sale->payment_method }}</dd></div>
            <div class="flex justify-between border-t pt-2"><dt class="text-slate-500">Subtotal</dt><dd>{{ peso($sale->subtotal) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd>−{{ peso($sale->discount) }}</dd></div>
            <div class="flex justify-between text-lg font-bold"><dt>Total</dt><dd>{{ peso($sale->total) }}</dd></div>
            @if ($sale->refunded > 0)
                <div class="flex justify-between font-medium text-red-700"><dt>Refunded</dt><dd>{{ peso($sale->refunded) }}</dd></div>
            @endif
            <div class="flex justify-between"><dt class="text-slate-500">Paid</dt><dd>{{ peso($sale->amount_paid) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Change</dt><dd>{{ peso($sale->change) }}</dd></div>
        </dl>
    </div>
@endsection
