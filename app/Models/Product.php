<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** Why stock on record was changed by hand. "Other" needs a note saying what happened. */
    public const ADJUST_REASONS = ['Wrong count', 'Damaged', 'Expired', 'Lost or missing', 'Customer return', 'Other'];

    protected $fillable = [
        'barcode', 'name', 'category_id', 'unit_id', 'cost_price',
        'selling_price', 'stock', 'reorder_level', 'is_active',
        'promo_buy', 'promo_take', 'promo_until',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'is_active' => 'boolean',
        'promo_buy' => 'integer',
        'promo_take' => 'integer',
        'promo_until' => 'date',
    ];

    /** A Buy X Take Y promo that is on today. Kilo items can't have one, because half a free kilo isn't a thing. */
    public function promoActive(): bool
    {
        return $this->promo_buy > 0 && $this->promo_take > 0
            && ! $this->unit?->allows_decimal
            && ($this->promo_until === null || $this->promo_until->gte(today()));
    }

    public function promoLabel(): ?string
    {
        return $this->promo_buy > 0 && $this->promo_take > 0 ? "Buy {$this->promo_buy} Take {$this->promo_take}" : null;
    }

    /** How many of this many are free. Buy 1 Take 1: 2 gives 1 free, 3 gives 1, 4 gives 2. */
    public function freeQty(float $qty): float
    {
        if (! $this->promoActive()) {
            return 0;
        }

        return floor($qty / ($this->promo_buy + $this->promo_take)) * $this->promo_take;
    }

    /**
     * The forms a scanned code may be stored under. Scanners can read the same item
     * as a 12-digit UPC or as a 13-digit EAN with a leading zero, depending on their settings.
     */
    public static function barcodeVariants(string $code): array
    {
        $variants = [$code];

        if (ctype_digit($code) && strlen($code) === 12) {
            $variants[] = '0'.$code;
        }
        if (ctype_digit($code) && strlen($code) === 13 && $code[0] === '0') {
            $variants[] = substr($code, 1);
        }

        return $variants;
    }

    /** The product a code already belongs to, as its main barcode or an extra one. Deactivated products keep theirs. */
    public static function barcodeOwner(string $code, ?int $exceptProductId = null): ?self
    {
        $variants = static::barcodeVariants($code);

        return static::whereIn('barcode', $variants)
            ->when($exceptProductId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->first()
            ?? ProductBarcode::with('product')->whereIn('barcode', $variants)
                ->when($exceptProductId, fn ($q, $id) => $q->where('product_id', '!=', $id))
                ->first()?->product;
    }

    /** In-store code for items and baskets with no printed barcode (EAN-13 with the "20" in-store prefix). */
    public static function internalBarcode(): string
    {
        do {
            $base = '20'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($base) as $i => $digit) {
                $sum += (int) $digit * ($i % 2 ? 3 : 1);
            }
            $code = $base.((10 - $sum % 10) % 10);
        } while (static::barcodeOwner($code) || Basket::barcodeOwner($code));

        return $code;
    }

    public function extraBarcodes()
    {
        return $this->hasMany(ProductBarcode::class)->orderBy('id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function lots()
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeLowStock($query)
    {
        return $query->where('is_active', true)->whereColumn('stock', '<=', 'reorder_level');
    }

    public function isLowStock(): bool
    {
        return (float) $this->stock <= (float) $this->reorder_level;
    }

    /** Shape used by the React screens. */
    public function toScanArray(): array
    {
        return [
            'id' => $this->id,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'price' => (float) $this->selling_price,
            'cost' => (float) $this->cost_price,
            'stock' => (float) $this->stock,
            'unit' => $this->unit?->abbreviation,
            'allows_decimal' => (bool) $this->unit?->allows_decimal,
            'category' => $this->category?->name,
            'promo' => $this->promoActive() ? ['buy' => $this->promo_buy, 'take' => $this->promo_take, 'label' => $this->promoLabel()] : null,
        ];
    }
}
