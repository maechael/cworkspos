@extends('layouts.app', ['title' => 'Cashier', 'fullWidth' => true, 'posScheme' => $props['scheme']])

@section('content')
    <div class="h-[calc(100vh-3.25rem)] lg:h-screen"
         data-react="Pos"
         data-props='@json($props)'></div>
@endsection
