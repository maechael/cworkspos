@extends('layouts.app', ['title' => 'Products'])

@section('actions')
    @if (auth()->user()->isAdmin())<a href="{{ route('products.import') }}" class="btn btn-secondary">Import from old POS</a>@endif
    <a href="{{ route('products.batch') }}" class="btn btn-secondary">Batch add</a>
    <a href="{{ route('products.create') }}" class="btn btn-primary">Add product</a>
@endsection

@section('content')
    <div data-react="Products" data-props='@json(['categories' => $categories, 'filters' => $filters])'></div>
@endsection
