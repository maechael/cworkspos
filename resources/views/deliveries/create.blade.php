@extends('layouts.app', ['title' => 'Receive delivery'])

@section('content')
    <div data-react="Receiving" data-props='@json(['suppliers' => $suppliers, 'trackExpiry' => track_expiry()])'></div>
@endsection
