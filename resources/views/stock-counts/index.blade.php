@extends('layouts.app', ['title' => 'Stock counts'])

@section('actions')
    <a href="{{ route('stock-counts.create') }}" class="btn btn-primary">Start a count</a>
@endsection

@section('content')
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Ref</th><th>Date</th><th>Counted by</th><th>Notes</th><th class="text-right">Items</th><th class="text-right">Net difference</th><th></th></tr></thead>
            <tbody>
            @forelse ($counts as $c)
                <tr>
                    <td class="font-mono text-sm">SC-{{ str_pad($c->id, 5, '0', STR_PAD_LEFT) }}</td>
                    <td>{{ $c->created_at->format('M j, Y g:i A') }}</td>
                    <td>{{ $c->user->name }}</td>
                    <td class="text-slate-500">{{ $c->notes }}</td>
                    <td class="text-right">{{ $c->items_count }}</td>
                    <td class="text-right font-semibold {{ $c->net_variance < 0 ? 'text-red-600' : '' }}">{{ $c->net_variance > 0 ? '+' : '' }}{{ qty($c->net_variance) }}</td>
                    <td class="text-right"><a href="{{ route('stock-counts.show', $c) }}" class="text-sm text-brand-700 hover:underline">View</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-400">No counts yet. Start one and scan the shelves.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $counts->links() }}</div>
@endsection
