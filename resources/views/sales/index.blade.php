@extends('layouts.app', ['title' => auth()->user()->isAdmin() ? 'Sales' : 'My sales'])

@section('content')
    <div data-react="Sales" data-props='@json($props)'></div>
@endsection
