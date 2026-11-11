@extends('layouts.app', ['title' => 'Deliveries'])

@section('actions')
    <a href="{{ route('deliveries.create') }}" class="btn btn-primary">Receive delivery</a>
@endsection

@section('content')
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Ref</th><th>Received</th><th>Supplier</th><th>Invoice / DR</th><th class="text-right">Items</th><th class="text-right">Total cost</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse ($deliveries as $d)
                <tr>
                    <td class="font-mono text-sm">DR-{{ str_pad($d->id, 5, '0', STR_PAD_LEFT) }}</td>
                    <td>{{ $d->received_at->format('M j, Y') }}</td>
                    <td>{{ $d->supplier?->name ?? '—' }}</td>
                    <td>{{ $d->reference_no ?? '—' }}</td>
                    <td class="text-right">{{ $d->items_count }}</td>
                    <td class="text-right font-semibold">{{ peso($d->total_cost) }}</td>
                    <td>{{ $d->user->name }}</td>
                    <td class="text-right"><a href="{{ route('deliveries.show', $d) }}" class="text-sm text-brand-700 hover:underline">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-8 text-center text-slate-400">No deliveries yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $deliveries->links() }}</div>
@endsection
