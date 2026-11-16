@extends('layouts.app', ['title' => 'Import from old POS: check'])

@section('actions')
    <a href="{{ route('products.import') }}" class="btn btn-secondary">Choose another file</a>
@endsection

@section('content')
    @php
        $c = $plan['counts'];
        $badges = [
            'create' => ['New', 'bg-brand-100 text-brand-700'],
            'update' => ['Already here', 'bg-sky-100 text-sky-700'],
            'merge' => ['Extra barcode', 'bg-slate-100 text-slate-700'],
            'skip' => ['Duplicate, skipped', 'bg-slate-100 text-slate-500'],
        ];
        // Rows with something to read come first
        $rows = collect($plan['rows'])->sortBy(fn ($r) => [$r['notes'] ? 0 : 1, $r['line']])->values();
    @endphp

    <div class="card p-5">
        <p class="text-sm text-slate-500">{{ $fileName }}: {{ count($plan['rows']) }} products read.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-4">
            <div><div class="text-2xl font-bold">{{ $c['create'] }}</div><div class="text-sm text-slate-500">new products</div></div>
            <div><div class="text-2xl font-bold">{{ $c['update'] }}</div><div class="text-sm text-slate-500">already in this POS</div></div>
            <div><div class="text-2xl font-bold">{{ $c['merge'] + $c['skip'] }}</div><div class="text-sm text-slate-500">repeated in the file</div></div>
            <div><div class="text-2xl font-bold {{ $c['notes'] ? 'text-amber-700' : '' }}">{{ $c['notes'] }}</div><div class="text-sm text-slate-500">with a note to read</div></div>
        </div>
        @if ($plan['ignored'])
            <p class="mt-3 text-xs text-slate-500">Not brought over: {{ implode(', ', $plan['ignored']) }}.</p>
        @endif

        <form method="POST" action="{{ route('products.import.store', $token) }}" class="mt-4 border-t border-slate-100 pt-4"
              onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Importing…';">
            @csrf
            @if ($c['update'])
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="update_existing" value="1" checked class="mt-0.5">
                    <span>Update the {{ $c['update'] }} already in this POS with the old POS name, price, cost and reorder level</span>
                </label>
                <label class="mt-2 flex items-start gap-2 text-sm">
                    <input type="checkbox" name="replace_stock" value="1" class="mt-0.5">
                    <span>Also set their stock to the old POS Qty <span class="text-slate-500">(leave this off if you have counted stock here since)</span></span>
                </label>
            @endif
            <button class="btn btn-primary mt-4 px-6">Import {{ $c['create'] }} new{{ $c['update'] ? ' and '.$c['update'].' already here' : '' }}</button>
        </form>
    </div>

    <div class="card mt-4 overflow-x-auto">
        <table class="table table-stack min-w-[760px]">
            <thead><tr><th>Row</th><th>Product</th><th>Barcodes</th><th class="text-right">Price</th><th class="text-right">Cost</th><th class="text-right">Qty</th><th>What happens</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                @php [$label, $class] = $badges[$r['action']]; @endphp
                <tr>
                    <td class="text-slate-500">{{ $r['line'] }}</td>
                    <td class="font-medium">{{ $r['name'] }}@if (! $r['active'])<span class="badge ml-1 bg-slate-100 text-slate-500">inactive</span>@endif</td>
                    <td class="font-mono text-xs">{{ implode(', ', $r['barcodes']) ?: '—' }}</td>
                    <td class="text-right">{{ peso($r['price']) }}</td>
                    <td class="text-right">{{ peso($r['cost']) }}</td>
                    <td class="text-right">{{ $r['qty'] }}</td>
                    <td>
                        <span class="badge {{ $class }}">{{ $label }}</span>
                        @if ($r['action'] === 'update')<div class="text-xs text-slate-500">now {{ $r['current']['name'] }}, {{ peso($r['current']['price']) }}, {{ qty($r['current']['stock']) }} in stock</div>@endif
                        @foreach ($r['notes'] as $note)<div class="text-xs text-amber-800">{{ $note }}</div>@endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
