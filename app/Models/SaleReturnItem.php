<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $fillable = ['sale_return_id', 'sale_item_id', 'product_id', 'product_name', 'basket_name', 'quantity', 'amount', 'restocked'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'amount' => 'decimal:2',
        'restocked' => 'boolean',
    ];

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
