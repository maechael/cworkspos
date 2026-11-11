<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $fillable = ['stock_count_id', 'product_id', 'system_qty', 'counted_qty', 'variance'];

    protected $casts = [
        'system_qty' => 'decimal:3',
        'counted_qty' => 'decimal:3',
        'variance' => 'decimal:3',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
