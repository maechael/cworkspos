@extends('layouts.app', ['title' => 'Batch add products'])

@section('content')
    <div data-react="BatchAdd" data-props='@json(['categories' => $categories, 'units' => $units, 'trackExpiry' => track_expiry()])'></div>
@endsection
