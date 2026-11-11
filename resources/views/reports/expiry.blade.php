@extends('layouts.app', ['title' => 'Reports'])

@section('content')
    @include('reports.tabs')
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="label">Show items expiring within</label>
            <select name="days" class="input" onchange="this.form.submit()">
                @foreach ([7, 14, 30, 60, 90] as $d)<option value="{{ $d }}" @selected($days == $d)>{{ $d }} days</option>@endforeach
            </select>
        </div>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Product</th><th>Expiry</th><th>Status</th><th class="text-right">Qty left</th><th class="text-right">Value at cost</th><th>Delivered</th><th>Supplier</th></tr></thead>
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
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-400">Nothing expiring within {{ $days }} days.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
