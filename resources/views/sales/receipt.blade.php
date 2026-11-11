<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>{{ $sale->receipt_no }}</title>
    <style>
        /* Sized for 58mm thermal printers; also fine on 80mm */
        @page { margin: 0; size: 58mm auto; }
        body { font-family: 'Courier New', monospace; font-size: 11px; width: 54mm; margin: 0 auto; padding: 3mm 0; color: #000; }
        .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
        hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 1px 0; }
        .big { font-size: 14px; }
        @media screen { body { background: #fff; box-shadow: 0 0 6px #ccc; padding: 4mm; } }
        /* Opened on a phone, the same receipt in bigger print; printing still uses the 58mm size above */
        @media screen and (max-width: 600px) { body { width: auto; max-width: 340px; margin: 12px auto; font-size: 14px; } .big { font-size: 17px; } }
    </style>
</head>
<body>
    <div class="c b big">ARNOLD BULAN</div>
    <div class="c b">MINI GROCERY</div>
    <div class="c">Thank you for shopping!</div>
    <hr>
    <div>{{ $sale->receipt_no }}</div>
    <div>{{ $sale->created_at->format('m/d/Y h:i A') }}</div>
    <div>Cashier: {{ $sale->user->name }}</div>
    @if ($sale->status === 'voided')<div class="c b big">*** VOIDED ***</div>@endif
    <hr>
    <table>
        @foreach ($sale->receiptLines() as $line)
            @if ($line['basket'])
                <tr><td colspan="2">{{ $line['basket'] }}</td></tr>
                <tr>
                    <td>&nbsp;{{ qty($line['qty']) }} basket x {{ number_format($line['total'] / max($line['qty'], 1), 2) }}</td>
                    <td class="r">{{ number_format($line['total'], 2) }}</td>
                </tr>
                @foreach ($line['items'] as $item)
                    <tr><td colspan="2">&nbsp;&nbsp;- {{ qty($item->quantity) }} {{ $item->product_name }}</td></tr>
                @endforeach
            @else
                @php $item = $line['item']; @endphp
                <tr><td colspan="2">{{ $item->product_name }}</td></tr>
                <tr>
                    <td>&nbsp;{{ qty($item->quantity) }} {{ $item->product?->unit?->abbreviation }} x {{ number_format($item->unit_price, 2) }}</td>
                    <td class="r">{{ number_format((float) $item->line_total + (float) $item->free_qty * (float) $item->unit_price, 2) }}</td>
                </tr>
                @if ($item->free_qty > 0)
                    <tr>
                        <td>&nbsp;{{ $item->promo }} ({{ qty($item->free_qty) }} free)</td>
                        <td class="r">-{{ number_format((float) $item->free_qty * (float) $item->unit_price, 2) }}</td>
                    </tr>
                @endif
            @endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="r">{{ number_format($sale->subtotal, 2) }}</td></tr>
        @if ($sale->discount > 0)<tr><td>Discount</td><td class="r">-{{ number_format($sale->discount, 2) }}</td></tr>@endif
        <tr class="b big"><td>TOTAL</td><td class="r">{{ number_format($sale->total, 2) }}</td></tr>
        <tr><td>{{ strtoupper($sale->payment_method) }}</td><td class="r">{{ number_format($sale->amount_paid, 2) }}</td></tr>
        <tr><td>Change</td><td class="r">{{ number_format($sale->change, 2) }}</td></tr>
    </table>
    <hr>
    @php $saved = $sale->items->sum(fn ($i) => (float) $i->free_qty * (float) $i->unit_price); @endphp
    @if ($saved > 0)<div class="c b">You saved {{ number_format($saved, 2) }} on promos</div>@endif
    <div class="c">{{ $sale->lineCount() }} item(s)</div>
    <div class="c">This is not an official receipt.</div>
    @if (request('print'))
        <script>window.onload = () => { window.print(); setTimeout(() => window.close(), 500); };</script>
    @endif
</body>
</html>
