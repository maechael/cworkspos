<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A shopper brings items back. The sale is edited down to what they kept (lines, subtotal, discount, total),
 * so reports, profit and best sellers count only what was really sold, and the return is kept as its own record.
 *
 * - Free promo items come back first: returning 1 of a Buy 1 Take 1 pair refunds nothing; both refunds one price.
 * - Baskets come back whole, each item at its share of the basket price.
 * - The sale's discount is shared out, so the refund is what was really paid for those items.
 * - Items marked back on the shelf return to stock (into their expiry lots); the rest stay out of stock.
 */
class SaleReturns
{
    private const EPS = 0.0005;

    public function __construct(private Inventory $inventory) {}

    /**
     * @param  array<string, array{qty?: mixed, restock?: mixed}>  $lines  keyed like Sale::receiptLines()
     */
    public function process(Sale $sale, array $lines, string $reason, ?string $note, string $method, int $userId): SaleReturn
    {
        return DB::transaction(function () use ($sale, $lines, $reason, $note, $method, $userId) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['lines' => 'This sale was voided, so nothing can be returned.']);
            }
            $sale->load('items.product.unit');

            $parts = []; // [SaleItem, qty back, amount back, restock, basket name]
            foreach ($sale->receiptLines() as $line) {
                $want = round((float) ($lines[$line['key']]['qty'] ?? 0), 3);
                if ($want <= 0) {
                    continue;
                }
                $restock = filter_var($lines[$line['key']]['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($line['basket']) {
                    array_push($parts, ...$this->basketParts($line, $want, $restock));
                } else {
                    $parts[] = $this->itemPart($line['item'], $want, $restock);
                }
            }

            if (! $parts) {
                throw ValidationException::withMessages(['lines' => 'Enter how many came back for at least one item.']);
            }

            // The sale's discount is shared out: the refund is the same share of the total as the items are of the subtotal
            $gross = round(array_sum(array_column($parts, 2)), 2);
            $subtotal = round((float) $sale->subtotal - $gross, 2);
            $allBack = $sale->items->every(fn ($i) => (float) $i->quantity <= self::EPS);
            $refund = $allBack ? (float) $sale->total
                : ((float) $sale->subtotal > 0 ? round($gross * (float) $sale->total / (float) $sale->subtotal, 2) : 0.0);
            $total = round((float) $sale->total - $refund, 2);

            $sale->update([
                'subtotal' => max($subtotal, 0),
                'total' => max($total, 0),
                'discount' => max(round(max($subtotal, 0) - max($total, 0), 2), 0),
                'refunded' => round((float) $sale->refunded + $refund, 2),
            ]);

            $return = SaleReturn::create([
                'return_no' => 'TMP-'.uniqid(),
                'sale_id' => $sale->id,
                'user_id' => $userId,
                'reason' => $reason,
                'note' => $note,
                'refund' => $refund,
                'refund_method' => $method,
            ]);
            $return->update(['return_no' => 'RT-'.now()->format('ymd').'-'.str_pad((string) $return->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($parts as [$item, $qty, $amount, $restock, $basket]) {
                $item->save();
                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'basket_name' => $basket,
                    'quantity' => $qty,
                    'amount' => $amount,
                    'restocked' => $restock,
                ]);
                if ($restock) {
                    $product = Product::lockForUpdate()->find($item->product_id);
                    $this->inventory->restoreLots($product, $qty);
                    $this->inventory->move($product, $qty, 'return', $return->return_no, $userId);
                }
            }

            return $return;
        });
    }

    /** One item on its own. Changes the line in memory; it is saved with the return. */
    private function itemPart($item, float $want, bool $restock): array
    {
        $left = (float) $item->quantity;
        if ($want > $left + self::EPS) {
            throw ValidationException::withMessages(['lines' => "Only {$this->qty($left)} of {$item->product_name} are left on this sale."]);
        }
        if ($item->product) {
            $this->inventory->assertQuantity($item->product, $want);
        }

        $freeBack = min($want, (float) $item->free_qty);
        $amount = $want >= $left - self::EPS
            ? (float) $item->line_total
            : min(round(($want - $freeBack) * (float) $item->unit_price, 2), (float) $item->line_total);

        $item->quantity = round($left - $want, 3);
        $item->returned_qty = round((float) $item->returned_qty + $want, 3);
        $item->free_qty = round((float) $item->free_qty - $freeBack, 3);
        $item->line_total = round((float) $item->line_total - $amount, 2);

        return [$item, $want, $amount, $restock, null];
    }

    /** Whole baskets: every item inside comes back in step, each at its share of the basket price. */
    private function basketParts(array $line, float $baskets, bool $restock): array
    {
        $had = $line['qty'];
        if (floor($baskets) != $baskets) {
            throw ValidationException::withMessages(['lines' => "{$line['basket']} comes back by the whole basket."]);
        }
        if ($baskets > $had + self::EPS) {
            throw ValidationException::withMessages(['lines' => "Only {$this->qty($had)} of {$line['basket']} are left on this sale."]);
        }

        $all = $baskets >= $had - self::EPS;
        $parts = [];
        foreach ($line['items'] as $item) {
            $qty = $all ? (float) $item->quantity : round((float) $item->quantity * $baskets / $had, 3);
            $amount = $all ? (float) $item->line_total : round((float) $item->line_total * $baskets / $had, 2);

            $item->quantity = round((float) $item->quantity - $qty, 3);
            $item->returned_qty = round((float) $item->returned_qty + $qty, 3);
            $item->line_total = round((float) $item->line_total - $amount, 2);
            $item->basket_qty = round($had - $baskets, 3);

            $parts[] = [$item, $qty, $amount, $restock, $line['basket']];
        }

        return $parts;
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
