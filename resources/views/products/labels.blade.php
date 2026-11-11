@extends('layouts.app', ['title' => 'Barcode labels'])

@section('actions')
    <button onclick="window.print()" class="btn btn-primary">Print</button>
@endsection

@section('content')
    @if ($products->isEmpty())
        <div class="card p-8 text-center text-slate-500">Tick products on the Products page first, then press “Print labels”.</div>
    @else
        <div class="bg-white p-4"
             data-react="BarcodeLabels"
             data-props='@json($labelProps)'></div>
    @endif
@endsection
