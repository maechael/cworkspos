<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $fillable = ['reference_no', 'supplier_id', 'user_id', 'received_at', 'total_cost', 'notes'];

    protected $casts = ['received_at' => 'date', 'total_cost' => 'decimal:2'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
