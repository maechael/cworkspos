<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\Approvals;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The owner answers a cashier's void or return from their own login, wherever they are,
 * and can switch voids and returns off. The cashier's screen waits on the answer.
 */
class ApprovalController extends Controller
{
    /** Owner: the on/off switch, what is waiting, and the history. The panel polls this as JSON. */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json(self::panelData());
        }

        $history = ApprovalRequest::with('requester', 'decider', 'sale')->where('status', '!=', 'pending')->latest('id')->paginate(30);

        return view('approvals.index', ['panel' => self::panelData(), 'history' => $history]);
    }

    /** Everything the owner's live panel shows. */
    public static function panelData(): array
    {
        ApprovalRequest::where('status', 'pending')->where('created_at', '<=', now()->subMinutes(ApprovalRequest::EXPIRES_MINUTES))
            ->update(['status' => 'expired', 'message' => 'Nobody answered within '.ApprovalRequest::EXPIRES_MINUTES.' minutes.']);

        return [
            'allowed' => Approvals::allowed(),
            'waiting' => ApprovalRequest::with('requester', 'sale')->waiting()->oldest('id')->get()->map(fn (ApprovalRequest $a) => [
                'id' => $a->id,
                'type' => $a->typeLabel(),
                'summary' => $a->summary,
                'amount' => (float) $a->amount,
                'cashier' => $a->requester->name,
                'receipt_no' => $a->sale?->receipt_no,
                'sale_url' => $a->sale ? route('sales.show', $a->sale) : null,
                'reason' => $a->payload['reason'] ?? null,
                'note' => $a->payload['note'] ?? null,
                'asked' => $a->created_at->diffForHumans(),
            ])->values(),
            'updated_at' => now()->format('g:i:s A'),
        ];
    }

    public function toggle(Request $request, Approvals $approvals)
    {
        $on = $request->validate(['allowed' => 'required|boolean'])['allowed'];
        $declined = $approvals->setAllowed((bool) $on, $request->user());

        $text = $on ? 'Voids and returns are on. Cashiers can ask for your approval again.'
            : 'Voids and returns are off. Cashiers can\'t void or return anything until you turn them back on.'
                .($declined ? " {$declined} waiting request".($declined === 1 ? ' was' : 's were').' declined.' : '');

        return $request->wantsJson() ? response()->json(self::panelData() + ['notice' => $text]) : back()->with('success', $text);
    }

    public function approve(Request $request, ApprovalRequest $approval, Approvals $approvals)
    {
        return $this->answer($request, fn () => $approvals->approve($approval, $request->user()));
    }

    public function decline(Request $request, ApprovalRequest $approval, Approvals $approvals)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:200'])['reason'] ?? null;

        return $this->answer($request, fn () => $approvals->decline($approval, $request->user(), $reason));
    }

    private function answer(Request $request, callable $do)
    {
        try {
            $done = $do();
        } catch (ValidationException $e) {
            $text = collect($e->errors())->flatten()->first();

            return $request->wantsJson() ? response()->json(self::panelData() + ['notice' => $text, 'error' => true], 422) : back()->with('error', $text);
        }

        $text = match ($done->status) {
            'approved' => 'Approved: '.$done->summary.'.'.($done->message ? ' '.$done->message : ''),
            'declined' => 'Declined: '.$done->summary.'.',
            default => 'It couldn\'t be done: '.$done->message,
        };
        $error = $done->status === 'failed';

        return $request->wantsJson()
            ? response()->json(self::panelData() + ['notice' => $text, 'error' => $error])
            : back()->with($error ? 'error' : 'success', $text);
    }

    /** Whether voids and returns are switched on right now (the cashier screen asks before offering them). */
    public function allowed()
    {
        return response()->json(['allowed' => Approvals::allowed()]);
    }

    /** Cashier screen: ask the owner, from anywhere, to approve taking lines off the order. */
    public function store(Request $request, Approvals $approvals)
    {
        $data = $request->validate([
            'type' => 'required|in:line_void,clear_order',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required_without:lines.*.basket_id|nullable|integer',
            'lines.*.basket_id' => 'required_without:lines.*.product_id|nullable|integer',
            'lines.*.quantity' => 'required|numeric',
        ]);

        [$names, $amount] = $approvals->describeLines($data['lines']);
        $summary = ($data['type'] === 'clear_order' ? 'Clear the order: ' : 'Void ').implode(', ', $names);
        $approval = $approvals->request($data['type'], $request->user(), null, $summary, $amount, ['lines' => $data['lines']]);

        return response()->json($approval->forScreen(), 201);
    }

    /** The asker's screen checks the answer here (JSON), or waits on this page for a return or a sale void. */
    public function show(Request $request, ApprovalRequest $approval)
    {
        abort_unless($request->user()->isAdmin() || $approval->requested_by === $request->user()->id, 403);
        $approval->load('decider', 'sale');

        if ($request->wantsJson()) {
            return response()->json($approval->forScreen());
        }

        return view('approvals.show', [
            'approval' => $approval->settle(),
            'saleUrl' => $approval->sale?->linkFor('sales.show', $request->user()),
            'receiptUrl' => $approval->sale?->linkFor('sales.receipt', $request->user()),
        ]);
    }

    public function cancel(Request $request, ApprovalRequest $approval, Approvals $approvals)
    {
        abort_unless($approval->requested_by === $request->user()->id, 403);
        $approval = $approvals->cancel($approval);

        return $request->wantsJson() ? response()->json($approval->forScreen()) : back();
    }
}
