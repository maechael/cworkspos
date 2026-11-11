@extends('layouts.app', ['title' => 'POS style'])


@section('actions')
    <a href="{{ route('pos.index') }}" class="btn btn-secondary">Open cashier</a>
@endsection

@section('content')
    <div data-react="PosSettings" data-props='@json($props)'></div>
@endsection
