<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'receipt_no', 'user_id', 'subtotal', 'discount', 'total',
        'amount_paid', 'change', 'refunded', 'payment_method', 'status', 'voided_at', 'voided_by', 'void_approved_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change' => 'decimal:2',
        'refunded' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function returns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function voidApprover()
    {
        return $this->belongsTo(User::class, 'void_approved_by');
    }

    /**
     * A link to one of this sale's pages for this person. A cashier opening another cashier's sale
     * (found by its receipt number, for a return) gets a signed link that works for 2 hours.
     */
    public function linkFor(string $route, User $viewer, array $query = []): string
    {
        $params = ['sale' => $this] + $query;

        return $viewer->isAdmin() || $this->user_id === $viewer->id
            ? route($route, $params)
            : \Illuminate\Support\Facades\URL::temporarySignedRoute($route, now()->addHours(2), $params);
    }

    /** A completed sale with something still left to bring back. */
    public function canReturn(): bool
    {
        return $this->status === 'completed' && $this->items->contains(fn ($i) => (float) $i->quantity > 0);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * The lines as the customer bought them: an item on its own, or a basket with the items inside it.
     * Each entry is ['key', 'basket' => null, 'item' => SaleItem] or ['key', 'basket' => name, 'qty', 'total', 'items' => [...]].
     * The key ("item-5", or "basket-" and its first item's id) names the line on the return form.
     */
    public function receiptLines(): array
    {
        $lines = [];
        $baskets = [];
        foreach ($this->items as $item) {
            if (! $item->basket_name) {
                $lines[] = ['key' => 'item-'.$item->id, 'basket' => null, 'item' => $item];

                continue;
            }
            $key = ($item->basket_id ?? 'x').'|'.$item->basket_name;
            if (! isset($baskets[$key])) {
                $baskets[$key] = count($lines);
                $lines[] = ['key' => 'basket-'.$item->id, 'basket' => $item->basket_name, 'qty' => (float) $item->basket_qty, 'total' => 0.0, 'items' => []];
            }
            $lines[$baskets[$key]]['total'] += (float) $item->line_total;
            $lines[$baskets[$key]]['items'][] = $item;
        }

        return $lines;
    }

    /** The lines the shopper still has: anything brought back in full is left out. */
    public function keptLines(): array
    {
        return array_values(array_filter($this->receiptLines(),
            fn ($line) => $line['basket'] ? $line['qty'] > 0 : (float) $line['item']->quantity > 0));
    }

    /** Items scanned, with each basket counted once, not counting any brought back in full. */
    public function lineCount(): int
    {
        return count($this->keptLines());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
