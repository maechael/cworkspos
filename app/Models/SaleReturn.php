<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Items a shopper brought back from one sale, and the money given back. */
class SaleReturn extends Model
{
    public const REASONS = ['Changed their mind', 'Damaged or defective', 'Expired', 'Wrong item', 'Other'];

    /** Reasons where the item usually can't be sold again, so "back on the shelf" starts unticked. */
    public const NOT_SELLABLE = ['Damaged or defective', 'Expired'];

    protected $fillable = ['return_no', 'sale_id', 'user_id', 'approved_by', 'reason', 'note', 'refund', 'refund_method'];

    protected $casts = ['refund' => 'decimal:2'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
