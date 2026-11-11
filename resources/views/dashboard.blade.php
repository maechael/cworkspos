@extends('layouts.app', ['title' => 'Dashboard', 'hideHeading' => true])

@section('content')
    <div data-react="Dashboard" data-props='@json(['initial' => $data, 'userName' => auth()->user()->name])'></div>
@endsection
