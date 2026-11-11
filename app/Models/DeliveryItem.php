<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryItem extends Model
{
    protected $fillable = ['delivery_id', 'product_id', 'quantity', 'remaining_qty', 'unit_cost', 'expiry_date'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'remaining_qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    /** Lots still on the shelf that have expired or will within the given days. */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->startOfDay()->addDays($days));
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
