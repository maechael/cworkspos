<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Approvals;
use App\Services\SaleReturns;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A shopper brings items back and the sale is edited to match. The owner does it straight away;
 * a cashier needs the owner's approval, typed at the counter or given from the owner's own login.
 */
class SaleReturnController extends Controller
{
    public function create(Request $request, Sale $sale)
    {
        abort_unless(SaleController::canOpen($request, $sale), 403);
        $sale->load('items.product.unit', 'user');
        $user = $request->user();

        if (! $sale->canReturn()) {
            return redirect($sale->linkFor('sales.show', $user))->with('error', $sale->status === 'voided'
                ? 'This sale was voided, so nothing can be returned.'
                : 'Everything on this sale was already returned.');
        }

        return view('sales.return', [
            'sale' => $sale,
            'action' => $sale->linkFor('sales.return.store', $user),
            'backUrl' => $sale->linkFor('sales.show', $user),
            'needsApproval' => ! $user->isAdmin(),
            'voidsAllowed' => Approvals::allowed(),
        ]);
    }

    public function store(Request $request, Sale $sale, SaleReturns $returns, Approvals $approvals)
    {
        abort_unless(SaleController::canOpen($request, $sale), 403);
        $user = $request->user();

        $data = $request->validate([
            'lines' => 'required|array',
            'lines.*.qty' => 'nullable|numeric|min:0',
            'lines.*.restock' => 'nullable|boolean',
            'reason' => ['required', Rule::in(SaleReturn::REASONS)],
            'note' => 'nullable|string|max:200|required_if:reason,Other',
            'refund_method' => 'required|in:cash,gcash,card',
            'approval_via' => $user->isAdmin() ? 'nullable' : 'required|in:here,remote',
            'credential' => 'nullable|string|max:255|required_if:approval_via,here',
        ], [
            'note.required_if' => 'Write a short note when the reason is Other.',
            'credential.required_if' => "Ask the owner to type their password or passkey, or send it to the owner's phone.",
        ]);
        $args = [$data['lines'], $data['reason'], $data['note'] ?? null, $data['refund_method']];

        if ($user->isAdmin()) {
            return $this->done($sale, $returns->process($sale, ...$args, userId: $user->id), $user);
        }

        // Checked first, so a mistake on the form never uses up an approval or reaches the owner's phone
        $preview = $returns->preview($sale, $data['lines']);

        if ($data['approval_via'] === 'remote') {
            $approval = $approvals->request('return', $user, $sale, $preview['summary'], $preview['refund'], [
                'lines' => $data['lines'], 'reason' => $data['reason'], 'note' => $data['note'] ?? null, 'refund_method' => $data['refund_method'],
            ]);

            return redirect()->route('approvals.show', $approval);
        }

        [$owner, $via] = $approvals->checkCredential($data['credential'], $user, 'Return', ['receipt' => $sale->receipt_no, 'summary' => $preview['summary']]);
        $return = $returns->process($sale, ...$args, userId: $user->id, approvedBy: $owner->id);
        $approvals->recordCounter('return', $user, $owner, $via, $sale, $preview['summary'], (float) $return->refund,
            ['reason' => $data['reason'], 'note' => $data['note'] ?? null, 'refund_method' => $data['refund_method']], $return->return_no.' saved.');

        return $this->done($sale, $return, $user);
    }

    private function done(Sale $sale, SaleReturn $return, $user)
    {
        $back = $return->items->where('restocked', true)->isNotEmpty() ? ' Items marked "back on the shelf" are in stock again.' : '';

        return redirect($sale->linkFor('sales.show', $user))->with('success',
            "Return {$return->return_no} saved. Give back ".peso($return->refund).' by '.strtoupper($return->refund_method).'.'.$back);
    }
}
