<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Voids a whole sale: what the shopper still had goes back into stock, and the sale stops counting. */
class SaleVoids
{
    public function __construct(private Inventory $inventory) {}

    public function void(Sale $sale, int $userId, ?int $approvedBy = null): Sale
    {
        return DB::transaction(function () use ($sale, $userId, $approvedBy) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'This sale is already voided.']);
            }

            $sale->load('items');
            foreach ($sale->items as $item) {
                if ((float) $item->quantity <= 0) {
                    continue; // all of it came back already in a return
                }
                $product = Product::lockForUpdate()->find($item->product_id);
                $this->inventory->restoreLots($product, (float) $item->quantity);
                $this->inventory->move($product, (float) $item->quantity, 'void', $sale->receipt_no, $userId);
            }
            $sale->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $userId, 'void_approved_by' => $approvedBy]);

            return $sale;
        });
    }
}
