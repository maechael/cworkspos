<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * All stock changes go through here so products.stock, the expiry lots
 * (delivery_items.remaining_qty) and the stock_movements ledger stay in step.
 * Callers must already be inside a DB transaction.
 */
class Inventory
{
    /** Pieces, packs and cases must be whole numbers; only kilo-type units allow fractions. */
    public function assertQuantity(Product $product, float $qty): void
    {
        $product->loadMissing('unit');
        if (! $product->unit->allows_decimal && floor($qty) != $qty) {
            throw ValidationException::withMessages([
                'items' => "{$product->name} is counted per {$product->unit->name}, so the quantity must be a whole number.",
            ]);
        }
    }

    public function move(Product $product, float $qty, string $type, ?string $reference, ?int $userId): void
    {
        $product->stock = round((float) $product->stock + $qty, 3);
        $product->save();

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $qty,
            'balance_after' => $product->stock,
            'reference' => $reference,
        ]);
    }

    /**
     * Adds one delivery line: a stock lot plus the stock movement. Returns the line's cost.
     * The latest delivery cost becomes the product cost; the price only changes if one was given.
     */
    public function receive(Delivery $delivery, Product $product, array $item, ?int $userId): float
    {
        $qty = round((float) $item['quantity'], 3);
        $this->assertQuantity($product, $qty);

        $delivery->items()->create([
            'product_id' => $product->id,
            'quantity' => $qty,
            'remaining_qty' => $qty,
            'unit_cost' => $item['unit_cost'],
            'expiry_date' => $item['expiry_date'] ?? null,
        ]);

        $product->cost_price = $item['unit_cost'];
        if (isset($item['selling_price']) && $item['selling_price'] !== '') {
            $product->selling_price = $item['selling_price'];
        }
        $reference = 'DR-'.str_pad((string) $delivery->id, 5, '0', STR_PAD_LEFT);
        $this->move($product, $qty, 'delivery', $reference, $userId);

        return $qty * (float) $item['unit_cost'];
    }

    /** Take stock out of lots, earliest expiry first. */
    public function consumeLots(Product $product, float $qty): void
    {
        $lots = DeliveryItem::where('product_id', $product->id)
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC, id ASC')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($qty <= 0) {
                break;
            }
            $take = min($qty, (float) $lot->remaining_qty);
            $lot->remaining_qty = round((float) $lot->remaining_qty - $take, 3);
            $lot->save();
            $qty = round($qty - $take, 3);
        }
    }

    /** Put stock back into lots (voided sale or a count that found extra). */
    public function restoreLots(Product $product, float $qty): void
    {
        $lots = DeliveryItem::where('product_id', $product->id)
            ->whereColumn('remaining_qty', '<', 'quantity')
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC, id ASC')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($qty <= 0) {
                return;
            }
            $room = (float) $lot->quantity - (float) $lot->remaining_qty;
            $add = min($qty, $room);
            $lot->remaining_qty = round((float) $lot->remaining_qty + $add, 3);
            $lot->save();
            $qty = round($qty - $add, 3);
        }

        // Anything left over has no lot to go back to; it stays as untracked stock.
    }
}
