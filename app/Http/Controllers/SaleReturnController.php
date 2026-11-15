<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturns;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A shopper brings items back: the owner picks what came back and the sale is edited to match. */
class SaleReturnController extends Controller
{
    public function create(Sale $sale)
    {
        $sale->load('items.product.unit', 'user');

        if (! $sale->canReturn()) {
            return redirect()->route('sales.show', $sale)->with('error', $sale->status === 'voided'
                ? 'This sale was voided, so nothing can be returned.'
                : 'Everything on this sale was already returned.');
        }

        return view('sales.return', compact('sale'));
    }

    public function store(Request $request, Sale $sale, SaleReturns $returns)
    {
        $data = $request->validate([
            'lines' => 'required|array',
            'lines.*.qty' => 'nullable|numeric|min:0',
            'lines.*.restock' => 'nullable|boolean',
            'reason' => ['required', Rule::in(SaleReturn::REASONS)],
            'note' => 'nullable|string|max:200|required_if:reason,Other',
            'refund_method' => 'required|in:cash,gcash,card',
        ], [
            'note.required_if' => 'Write a short note when the reason is Other.',
        ]);

        $return = $returns->process($sale, $data['lines'], $data['reason'], $data['note'] ?? null, $data['refund_method'], $request->user()->id);

        $back = $return->items->where('restocked', true)->isNotEmpty() ? ' Items marked "back on the shelf" are in stock again.' : '';

        return redirect()->route('sales.show', $sale)->with('success',
            "Return {$return->return_no} saved. Give back ".peso($return->refund).' by '.strtoupper($return->refund_method).'.'.$back);
    }
}
