<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Several items sold together at one price, such as a gift or Noche Buena basket.
 * It has no stock of its own: selling one takes each item out of stock.
 */
class Basket extends Model
{
    protected $fillable = ['barcode', 'name', 'price', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(BasketItem::class)->orderBy('id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public static function barcodeOwner(string $code, ?int $exceptId = null): ?self
    {
        return static::whereIn('barcode', Product::barcodeVariants($code))
            ->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->first();
    }

    /** What the items would cost bought one by one. */
    public function regularPrice(): float
    {
        return round($this->items->sum(fn ($i) => (float) $i->quantity * (float) $i->product->selling_price), 2);
    }

    public function cost(): float
    {
        return round($this->items->sum(fn ($i) => (float) $i->quantity * (float) $i->product->cost_price), 2);
    }

    /** How many baskets the stock on record can make up. */
    public function available(): float
    {
        if ($this->items->isEmpty()) {
            return 0;
        }

        return max(0, $this->items->min(fn ($i) => floor((float) $i->product->stock / max((float) $i->quantity, 0.001))));
    }

    /** Shaped like a product for the cashier screen. Its id can't clash with a product's. */
    public function toScanArray(): array
    {
        $this->loadMissing('items.product.unit');

        return [
            'id' => 'basket-'.$this->id,
            'basket_id' => $this->id,
            'basket' => true,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'price' => (float) $this->price,
            'regular' => $this->regularPrice(),
            'cost' => $this->cost(),
            'stock' => $this->available(),
            'unit' => 'basket',
            'allows_decimal' => false,
            'category' => 'Baskets',
            'promo' => null,
            'contents' => $this->items->map(fn ($i) => ['name' => $i->product->name, 'qty' => (float) $i->quantity, 'unit' => $i->product->unit?->abbreviation])->all(),
        ];
    }
}
