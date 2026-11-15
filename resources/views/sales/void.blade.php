@extends('layouts.app', ['title' => 'Void sale '.$sale->receipt_no])

@section('actions')
    <a href="{{ $sale->linkFor('sales.show', auth()->user()) }}" class="btn btn-secondary">Back to the sale</a>
@endsection

@section('content')
    <form method="POST" action="{{ $action }}" class="card max-w-3xl p-5">
        @csrf
        <p class="text-sm text-slate-600">
            Voiding cancels the whole sale: {{ $sale->lineCount() }} item(s), <span class="font-semibold">{{ peso($sale->total) }}</span>,
            sold {{ $sale->created_at->format('M j, Y g:i A') }} by {{ $sale->user->name }}.
            Everything on it goes back into stock and the sale stops counting in the reports. To take back only some items, use Return items instead.
        </p>
        <div class="mt-4 max-w-xl">
            <label class="label" for="void-reason">Why is it being voided?</label>
            <input id="void-reason" name="reason" value="{{ old('reason') }}" maxlength="200" class="input" required autocomplete="off" placeholder="e.g. rang up the wrong items">
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">
            @include('partials.approval-choice', ['submit' => 'Approve and void the sale', 'submitRemote' => "Send to the owner's phone"])
        </div>
        <div class="mt-4 flex justify-end">
            <button class="btn btn-danger" data-approval-submit>Void the sale</button>
        </div>
    </form>
@endsection
