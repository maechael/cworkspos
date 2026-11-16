@extends('layouts.app', ['title' => 'Fix a batch: '.$lot->product->name])

@section('actions')
    <a href="{{ $back }}" class="btn btn-secondary">Back</a>
@endsection

@section('content')
    @php
        $unit = $lot->product->unit->abbreviation;
        $step = $lot->product->unit->allows_decimal ? '0.001' : '1';
        $dates = track_expiry();
        $expired = $dates && $lot->expiry_date && $lot->expiry_date->lt(today());
    @endphp
    <div class="card p-5">
        <p class="text-sm text-slate-500">
            Batch received <a class="tap text-brand-700 hover:underline" href="{{ route('deliveries.show', $lot->delivery_id) }}">{{ $lot->delivery->received_at->format('M j, Y') }}</a>
            from {{ $lot->delivery->supplier?->name ?? 'no supplier' }}: {{ qty($lot->quantity) }} {{ $unit }} came in.
        </p>
        <p class="mt-1 text-lg font-semibold">
            {{ qty($lot->remaining_qty) }} {{ $unit }} left
            @if ($dates)
                · <span @class(['text-red-600' => $expired])>{{ $lot->expiry_date ? ($expired ? 'expired ' : 'expires ').$lot->expiry_date->format('M j, Y') : 'no expiry date' }}</span>
            @endif
        </p>
    </div>

    <div class="mt-4 grid gap-4 {{ $dates ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }}">
        @if ($dates)
        {{-- 1. The date on the label was typed wrong, or there is none --}}
        <form method="POST" action="{{ route('lots.expiry', $lot) }}" class="card p-5">
            @csrf @method('PUT')
            <input type="hidden" name="back" value="{{ $back }}">
            <h2 class="font-semibold">Change the expiry date</h2>
            <p class="mt-0.5 text-sm text-slate-500">If the date was entered wrong. Leave it empty for items that don't expire.</p>
            <label class="label mt-3" for="lot-expiry">Expiry date</label>
            <input id="lot-expiry" type="date" name="expiry_date" value="{{ old('expiry_date', $lot->expiry_date?->toDateString()) }}" class="input">
            <button class="btn btn-primary mt-4">Save date</button>
        </form>
        @endif

        {{-- 2. The shelf has a different number from this batch --}}
        <form method="POST" action="{{ route('lots.adjust', $lot) }}" class="card p-5">
            @csrf
            <input type="hidden" name="back" value="{{ $back }}">
            <h2 class="font-semibold">Correct how many are left</h2>
            <p class="mt-0.5 text-sm text-slate-500">Count what is really left of this batch. Stock goes up or down to match.</p>
            <label class="label mt-3" for="lot-left">Left in this batch ({{ $unit }})</label>
            <input id="lot-left" type="number" name="left" min="0" max="{{ (float) $lot->quantity }}" step="{{ $step }}" value="{{ old('left', (float) $lot->remaining_qty) }}" class="input" required>
            <label class="label mt-3" for="lot-reason">Reason</label>
            <select id="lot-reason" name="reason" class="input" required>
                @foreach (\App\Models\Product::ADJUST_REASONS as $reason)
                    <option value="{{ $reason }}" @selected(old('reason', 'Wrong count') === $reason)>{{ $reason }}</option>
                @endforeach
            </select>
            <label class="label mt-3" for="lot-note">Note (needed for Other)</label>
            <input id="lot-note" name="note" value="{{ old('note') }}" maxlength="200" class="input" autocomplete="off">
            <button class="btn btn-primary mt-4">Save count</button>
        </form>

        {{-- 3. Pull the whole batch off the shelf --}}
        <form method="POST" action="{{ route('lots.adjust', $lot) }}" class="card border-red-100 p-5"
              onsubmit="return confirm('Remove all {{ qty($lot->remaining_qty) }} {{ $unit }} of this batch from stock?')">
            @csrf
            <input type="hidden" name="back" value="{{ $back }}">
            <input type="hidden" name="left" value="0">
            <h2 class="font-semibold text-red-700">Remove this batch</h2>
            <p class="mt-0.5 text-sm text-slate-500">Takes all {{ qty($lot->remaining_qty) }} {{ $unit }} out of stock, such as {{ $dates ? 'expired' : 'damaged' }} items thrown away. The stock history keeps it.</p>
            <label class="label mt-3" for="lot-remove-reason">Reason</label>
            <select id="lot-remove-reason" name="reason" class="input" required>
                @foreach (array_values(array_filter(['Expired', 'Damaged', 'Lost or missing', 'Other'], fn ($r) => $dates || $r !== 'Expired')) as $reason)
                    <option value="{{ $reason }}" @selected($reason === ($expired ? 'Expired' : 'Damaged'))>{{ $reason }}</option>
                @endforeach
            </select>
            <label class="label mt-3" for="lot-remove-note">Note (needed for Other)</label>
            <input id="lot-remove-note" name="note" maxlength="200" class="input" autocomplete="off">
            <button class="btn btn-danger mt-4" @disabled((float) $lot->remaining_qty <= 0)>Remove from stock</button>
        </form>
    </div>
@endsection
