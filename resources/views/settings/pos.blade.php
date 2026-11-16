@extends('layouts.app', ['title' => 'POS style'])


@section('actions')
    <a href="{{ route('pos.index') }}" class="btn btn-secondary">Open cashier</a>
@endsection

@section('content')
    <form method="POST" action="{{ route('settings.features') }}" class="card mb-4 flex flex-wrap items-center justify-between gap-3 p-5">
        @csrf @method('PUT')
        <div class="max-w-xl">
            <h2 class="font-semibold">Expiry dates</h2>
            <p class="mt-1 text-sm text-slate-500">
                @if (track_expiry())
                    <b class="text-brand-700">On.</b> Receiving asks for expiry dates, and expiring items show on the dashboard, the bell and the reports.
                @else
                    <b>Off.</b> Expiry dates are hidden everywhere: receiving, products, the dashboard and the reports. Dates already saved are kept and come back if you turn this on.
                @endif
            </p>
        </div>
        <input type="hidden" name="track_expiry" value="{{ track_expiry() ? '0' : '1' }}">
        <button class="btn {{ track_expiry() ? 'btn-secondary' : 'btn-primary' }}" role="switch" aria-checked="{{ track_expiry() ? 'true' : 'false' }}">
            {{ track_expiry() ? 'Turn expiry dates off' : 'Turn expiry dates on' }}
        </button>
    </form>

    <div data-react="PosSettings" data-props='@json($props)'></div>
@endsection
