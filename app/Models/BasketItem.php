<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BasketItem extends Model
{
    protected $fillable = ['basket_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function basket()
    {
        return $this->belongsTo(Basket::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
