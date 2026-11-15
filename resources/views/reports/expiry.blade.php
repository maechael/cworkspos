@extends('layouts.app', ['title' => 'Reports'])

@section('content')
    @include('reports.tabs')
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="label">Show items expiring within (expired ones always show)</label>
            <select name="days" class="input" onchange="this.form.submit()">
                @foreach ([7, 14, 30, 60, 90] as $d)<option value="{{ $d }}" @selected($days == $d)>{{ $d }} days</option>@endforeach
            </select>
        </div>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Product</th><th>Expiry</th><th>Status</th><th class="text-right">Qty left</th><th class="text-right">Value at cost</th><th>Delivered</th><th>Supplier</th><th class="no-print"><span class="sr-only">Fix or remove</span></th></tr></thead>
            <tbody>
            @forelse ($lots as $lot)
                @php $daysLeft = (int) now()->startOfDay()->diffInDays($lot->expiry_date, false); @endphp
                <tr>
                    <td><a href="{{ route('products.show', $lot->product) }}" class="hover:underline">{{ $lot->product->name }}</a></td>
                    <td>{{ $lot->expiry_date->format('M j, Y') }}</td>
                    <td>
                        <span class="badge {{ $daysLeft < 0 ? 'bg-red-600 text-white' : ($daysLeft <= 7 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800') }}">
                            {{ $daysLeft < 0 ? 'Expired '.abs($daysLeft).' days ago' : ($daysLeft === 0 ? 'Expires today' : $daysLeft.' days left') }}
                        </span>
                    </td>
                    <td class="text-right">{{ qty($lot->remaining_qty) }} {{ $lot->product->unit->abbreviation }}</td>
                    <td class="text-right">{{ peso($lot->remaining_qty * $lot->unit_cost) }}</td>
                    <td>{{ $lot->delivery->received_at->format('M j, Y') }}</td>
                    <td>{{ $lot->delivery->supplier?->name ?? '—' }}</td>
                    <td class="no-print text-right whitespace-nowrap">
                        <a href="{{ route('lots.edit', $lot) }}" class="tap text-sm text-brand-700 hover:underline" title="Change the expiry date or correct the count">Fix</a>
                        <form method="POST" action="{{ route('lots.adjust', $lot) }}" class="ml-3 inline"
                              onsubmit="return confirm('Remove all {{ qty($lot->remaining_qty) }} {{ $lot->product->unit->abbreviation }} of {{ addslashes($lot->product->name) }} (expiring {{ $lot->expiry_date->format('M j') }}) from stock?')">
                            @csrf
                            <input type="hidden" name="left" value="0">
                            <input type="hidden" name="reason" value="Expired">
                            <input type="hidden" name="note" value="{{ $daysLeft < 0 ? 'Expired' : 'Near expiry' }}, taken off the shelf">
                            <input type="hidden" name="back" value="{{ request()->fullUrl() }}">
                            <button class="tap text-sm font-medium text-red-700 hover:underline">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-8 text-center text-slate-400">Nothing expiring within {{ $days }} days.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
