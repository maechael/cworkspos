@extends('layouts.app', ['title' => 'Owner approval'])

@section('content')
    @php
        $states = [
            'pending' => ['Waiting for the owner…', 'border-amber-200 bg-amber-50 text-amber-900'],
            'approved' => ['Approved', 'border-brand-200 bg-brand-50 text-brand-800'],
            'declined' => ['Declined', 'border-red-200 bg-red-50 text-red-800'],
            'cancelled' => ['Cancelled', 'border-slate-200 bg-slate-50 text-slate-700'],
            'expired' => ['No answer', 'border-slate-200 bg-slate-50 text-slate-700'],
            'failed' => ["Couldn't be done", 'border-red-200 bg-red-50 text-red-800'],
        ];
    @endphp
    <div id="approval-wait" class="card max-w-2xl p-6" data-url="{{ route('approvals.show', $approval) }}" data-status="{{ $approval->status }}"
         data-states='@json($states)'>
        <p class="text-sm text-slate-500">{{ $approval->typeLabel() }}@if ($approval->sale) · {{ $approval->sale->receipt_no }}@endif</p>
        <h2 class="mt-1 text-lg font-semibold">{{ $approval->summary }}</h2>

        <div data-box role="status" aria-live="polite" class="mt-4 rounded-xl border px-4 py-4 {{ $states[$approval->status][1] }}">
            <div class="flex items-center gap-3">
                <span data-spinner @class(['h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent', 'hidden' => $approval->status !== 'pending'])></span>
                <span data-title class="text-lg font-bold">{{ $states[$approval->status][0] }}</span>
            </div>
            <p data-message class="mt-1 text-sm">
                @if ($approval->status === 'pending')
                    The owner sees this on their dashboard and can approve or decline it from their phone. Keep this page open; it updates by itself.
                    It stops waiting after {{ \App\Models\ApprovalRequest::EXPIRES_MINUTES }} minutes.
                @else
                    {{ $approval->message }}@if ($approval->decider) ({{ $approval->decider->name }})@endif
                @endif
            </p>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($saleUrl)<a href="{{ $saleUrl }}" class="btn btn-secondary">Back to the sale</a>@endif
            <a data-receipt href="{{ $receiptUrl ? $receiptUrl : '#' }}" target="_blank" @class(['btn btn-secondary', 'hidden' => ! ($receiptUrl && $approval->status === 'approved')])>Print receipt</a>
            <form data-cancel method="POST" action="{{ route('approvals.cancel', $approval) }}" @class(['hidden' => $approval->status !== 'pending'])>
                @csrf
                <button class="btn btn-secondary">Cancel the request</button>
            </form>
        </div>
    </div>

    <script>
        // Asks the server every 2 seconds until the owner answers
        (() => {
            const box = document.getElementById('approval-wait');
            const states = JSON.parse(box.dataset.states);
            if (box.dataset.status !== 'pending') return;

            const show = (a) => {
                const [title, classes] = states[a.status] || [a.status, ''];
                const panel = box.querySelector('[data-box]');
                panel.className = 'mt-4 rounded-xl border px-4 py-4 ' + classes;
                box.querySelector('[data-title]').textContent = title;
                box.querySelector('[data-spinner]').classList.add('hidden');
                box.querySelector('[data-cancel]').classList.add('hidden');
                box.querySelector('[data-message]').textContent = (a.message || '') + (a.decided_by ? ` (${a.decided_by})` : '');
                if (a.status === 'approved' && box.querySelector('[data-receipt]').getAttribute('href') !== '#') {
                    box.querySelector('[data-receipt]').classList.remove('hidden');
                }
            };

            const check = async () => {
                try {
                    const res = await fetch(box.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (res.ok) {
                        const a = await res.json();
                        if (a.status !== 'pending') { show(a); return; }
                    }
                } catch (e) { /* offline for a moment; try again */ }
                setTimeout(check, 2000);
            };
            setTimeout(check, 2000);
        })();
    </script>
@endsection
