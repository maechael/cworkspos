@extends('layouts.app', ['title' => 'Approvals'])

@section('content')
    <div data-react="ApprovalsPanel" data-props='@json(['initial' => $panel])'></div>

    <div class="card mt-4 overflow-x-auto">
        <h2 class="px-5 pt-4 pb-1 font-semibold">History</h2>
        <table class="table table-stack">
            <thead><tr><th>When</th><th>Request</th><th>Cashier</th><th>Answer</th></tr></thead>
            <tbody>
            @forelse ($history as $a)
                <tr>
                    <td class="whitespace-nowrap">{{ $a->created_at->format('M j, Y g:i A') }}</td>
                    <td>
                        <span class="text-xs text-slate-500">{{ $a->typeLabel() }}</span>
                        <div class="font-medium">{{ $a->summary }}</div>
                        @if ($a->payload['reason'] ?? null)<div class="text-xs text-slate-500">Reason: {{ $a->payload['reason'] }}@if ($a->payload['note'] ?? null) ({{ $a->payload['note'] }})@endif</div>@endif
                        @if ($a->sale)<a href="{{ route('sales.show', $a->sale) }}" class="tap text-xs text-brand-700 hover:underline">{{ $a->sale->receipt_no }}</a>@endif
                    </td>
                    <td>{{ $a->requester->name }}</td>
                    <td>
                        @php
                            $badge = ['approved' => 'bg-brand-100 text-brand-700', 'declined' => 'bg-red-100 text-red-700', 'failed' => 'bg-red-100 text-red-700'][$a->status] ?? 'bg-slate-100 text-slate-700';
                            $label = ['approved' => 'Approved', 'declined' => 'Declined', 'failed' => "Couldn't be done", 'cancelled' => 'Cancelled', 'expired' => 'No answer'][$a->status] ?? ucfirst($a->status);
                        @endphp
                        <span class="badge {{ $badge }}">{{ $label }}</span>
                        @if ($a->decided_via === 'auto')
                            <span class="badge ml-1 bg-amber-100 text-amber-800">Auto-approved</span>
                            <div class="text-xs text-slate-500">while auto-approve was on</div>
                        @elseif ($a->decider)
                            <div class="text-xs text-slate-500">by {{ $a->decider->name }} {{ $a->viaLabel() }}</div>
                        @endif
                        @if ($a->message && $a->status !== 'approved')<div class="text-xs text-slate-500">{{ $a->message }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-slate-400">No approvals yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $history->links() }}</div>
@endsection
