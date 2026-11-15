@extends('layouts.app', ['title' => auth()->user()->isAdmin() ? 'Sales' : 'My sales'])

@section('content')
    {{-- A shopper's receipt from any cashier opens here, for a return or a void --}}
    <form action="{{ route('sales.find') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4" role="search">
        <div class="min-w-56 flex-1 sm:max-w-sm">
            <label class="label" for="find-receipt">Return or void a receipt</label>
            <input id="find-receipt" name="receipt" class="input font-mono uppercase" placeholder="OR-261115-00012" autocomplete="off" required>
        </div>
        <button class="btn btn-secondary">Open receipt</button>
        <p class="w-full text-xs text-slate-500">Type the number printed on the shopper's receipt.
            @unless (auth()->user()->isAdmin()) Returns and voids need the owner's approval. @endunless</p>
    </form>
    <div data-react="Sales" data-props='@json($props)'></div>
@endsection
