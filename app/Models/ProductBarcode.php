<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A product's extra barcode. One scan of it counts as `pieces` of the product. */
class ProductBarcode extends Model
{
    protected $fillable = ['barcode', 'pieces', 'label'];

    protected $casts = ['pieces' => 'decimal:3'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** The product as the scanning screens expect it, plus what this particular code stands for. */
    public function toScanArray(): array
    {
        return $this->product->toScanArray() + ['scan_pieces' => (float) $this->pieces, 'scan_label' => $this->label];
    }
}
