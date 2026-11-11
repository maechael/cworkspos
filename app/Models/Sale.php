<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'receipt_no', 'user_id', 'subtotal', 'discount', 'total',
        'amount_paid', 'change', 'payment_method', 'status', 'voided_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

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
     * Each entry is ['basket' => null, 'item' => SaleItem] or ['basket' => name, 'qty', 'total', 'items' => [...]].
     */
    public function receiptLines(): array
    {
        $lines = [];
        $baskets = [];
        foreach ($this->items as $item) {
            if (! $item->basket_name) {
                $lines[] = ['basket' => null, 'item' => $item];

                continue;
            }
            $key = ($item->basket_id ?? 'x').'|'.$item->basket_name;
            if (! isset($baskets[$key])) {
                $baskets[$key] = count($lines);
                $lines[] = ['basket' => $item->basket_name, 'qty' => (float) $item->basket_qty, 'total' => 0.0, 'items' => []];
            }
            $lines[$baskets[$key]]['total'] += (float) $item->line_total;
            $lines[$baskets[$key]]['items'][] = $item;
        }

        return $lines;
    }

    /** Items scanned, with each basket counted once. */
    public function lineCount(): int
    {
        return count($this->receiptLines());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
