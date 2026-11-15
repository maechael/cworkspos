<?php

namespace App\Http\Controllers;

use App\Models\DeliveryItem;
use App\Models\Product;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fixing one delivery batch (lot) of a product: its expiry date, how many are really left,
 * or taking all of it out (expired, damaged). Each change goes into the stock history with the reason and who did it.
 */
class LotController extends Controller
{
    public function edit(Request $request, DeliveryItem $lot)
    {
        $lot->load('product.unit', 'delivery.supplier');

        return view('lots.edit', ['lot' => $lot, 'back' => $this->back($request, $lot, url()->previous())]);
    }

    public function updateExpiry(Request $request, DeliveryItem $lot, Inventory $inventory)
    {
        $data = $request->validate(['expiry_date' => 'nullable|date', 'back' => 'nullable|string']);
        $before = $lot->expiry_date?->format('M j, Y') ?? 'no expiry';
        $after = ! empty($data['expiry_date']) ? \Illuminate\Support\Carbon::parse($data['expiry_date'])->format('M j, Y') : 'no expiry';

        if ($before !== $after) {
            DB::transaction(function () use ($lot, $data, $inventory, $before, $after, $request) {
                $lot->update(['expiry_date' => $data['expiry_date'] ?: null]);
                // A zero change keeps the date fix in the stock history next to the stock changes
                $product = Product::lockForUpdate()->findOrFail($lot->product_id);
                $inventory->move($product, 0, 'expiry', "{$this->batch($lot)}: {$before} → {$after}", $request->user()->id);
            });
        }

        return redirect($this->back($request, $lot))->with('success', $before === $after
            ? 'The expiry date was already '.$after.'.'
            : "Expiry date changed from {$before} to {$after}.");
    }

    /** Sets how many of this batch are really left. 0 takes the whole batch out of stock. */
    public function adjust(Request $request, DeliveryItem $lot, Inventory $inventory)
    {
        $lot->load('product.unit');
        $data = $request->validate([
            'left' => 'required|numeric|min:0|max:'.(float) $lot->quantity,
            'reason' => ['required', Rule::in(Product::ADJUST_REASONS)],
            'note' => 'nullable|required_if:reason,Other|string|max:200',
            'back' => 'nullable|string',
        ], [
            'left.max' => 'This batch only came with '.qty($lot->quantity).' '.$lot->product->unit->abbreviation.'. For more, receive a delivery or adjust the product\'s stock.',
            'note.required_if' => 'Add a note saying what happened.',
        ], ['left' => 'pieces left']);

        $change = DB::transaction(function () use ($lot, $data, $inventory, $request) {
            $locked = DeliveryItem::lockForUpdate()->findOrFail($lot->id);
            $product = Product::with('unit')->lockForUpdate()->findOrFail($lot->product_id);
            $left = round((float) $data['left'], 3);
            $inventory->assertQuantity($product, $left);
            $change = round($left - (float) $locked->remaining_qty, 3);
            if ($change == 0) {
                return 0;
            }

            $locked->update(['remaining_qty' => $left]);
            $reference = $data['reason'].(empty($data['note']) ? '' : ': '.$data['note']).' · '.$this->batch($locked);
            $inventory->move($product, $change, 'adjust', $reference, $request->user()->id);

            return $change;
        });

        $unit = $lot->product->unit->abbreviation;
        $text = match (true) {
            $change == 0 => 'No change. That is already how many are left in this batch.',
            (float) $data['left'] == 0 => 'Removed all '.qty(-$change)." {$unit} of this batch from stock.",
            default => 'This batch now has '.qty($data['left'])." {$unit} (".($change > 0 ? '+' : '').qty($change).').',
        };

        return redirect($this->back($request, $lot))->with('success', $text);
    }

    /** "Batch received Nov 3, 2026, expiring Jan 5, 2027", so the history says which one. */
    private function batch(DeliveryItem $lot): string
    {
        $lot->loadMissing('delivery');

        return 'batch received '.$lot->delivery->received_at->format('M j, Y');
    }

    /** Where to go after saving: the page the change started from, if it is one of this app's, else the product. */
    private function back(Request $request, DeliveryItem $lot, ?string $fallback = null): string
    {
        $to = $request->input('back') ?: $fallback;
        $app = url('/');

        return $to && str_starts_with($to, $app) && ! str_contains($to, '/lots/') ? $to : route('products.show', $lot->product_id);
    }
}
