<?php

namespace App\Http\Controllers;

use App\Services\Approvals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checks a supervisor's password or passkey, typed at the counter, before the cashier screen takes a line off a sale.
 * Wrong tries are limited, because a passkey is short and the cashier could otherwise keep guessing.
 */
class VoidApprovalController extends Controller
{
    public function __invoke(Request $request, Approvals $approvals): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required_without:lines.*.basket_id', 'nullable', 'integer'],
            'lines.*.basket_id' => ['required_without:lines.*.product_id', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
            'clear_all' => ['nullable', 'boolean'],
        ]);

        [$names, $amount] = $approvals->describeLines($data['lines']);
        [$owner, $via] = $approvals->checkCredential($data['credential'], $request->user(), 'Line void', ['lines' => $names]);

        $clearAll = $request->boolean('clear_all');
        $approvals->recordCounter($clearAll ? 'clear_order' : 'line_void', $request->user(), $owner, $via, null,
            ($clearAll ? 'Clear the order: ' : 'Void ').implode(', ', $names), $amount, ['lines' => $data['lines']]);

        return response()->json(['approved_by' => $owner->name]);
    }
}
