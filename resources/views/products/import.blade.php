@extends('layouts.app', ['title' => 'Import from old POS'])

@section('content')
    <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
        <form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data" class="card p-5">
            @csrf
            <h2 class="font-semibold">Choose the product list from the old POS</h2>
            <p class="mt-1 text-sm text-slate-500">The file the old POS exports, such as <span class="font-mono">Product_2026_11_16.csv</span>. Excel (.xls, .xlsx) and CSV files work, whatever the name ends in.</p>
            <label class="label mt-4" for="import-file">File</label>
            <input id="import-file" type="file" name="file" required accept=".csv,.xls,.xlsx,.txt" class="input">
            @error('file')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            <button class="btn btn-primary mt-4">Check the file</button>
            <p class="mt-2 text-xs text-slate-500">Nothing is saved yet. You see what will happen first.</p>
        </form>

        <div class="card p-5 text-sm">
            <h2 class="font-semibold">What comes over</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600">
                <li><b>Name, Price, Cost</b></li>
                <li><b>Barcode 1</b> as the main barcode, <b>Barcode 2 and 3</b> as extra barcodes</li>
                <li><b>Qty</b> as opening stock for new products</li>
                <li><b>Warn Qty</b> as the reorder level</li>
            </ul>
            <p class="mt-3 text-slate-500">Member prices, tax, colors and fonts are left behind. Products without a barcode get an in-store code you can print on a label. Products already in this POS are found by barcode or exact name, and you choose whether to update them.</p>
        </div>
    </div>
@endsection
