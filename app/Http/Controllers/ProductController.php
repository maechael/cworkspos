<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Services\Inventory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** Blade shell; the React list polls this same URL for JSON so stock levels stay live. */
    public function index(Request $request)
    {
        if (! $request->wantsJson()) {
            return view('products.index', [
                'categories' => Category::orderBy('name')->get(['id', 'name']),
                'filters' => [
                    'q' => (string) $request->query('q', ''),
                    'category' => (string) $request->query('category', ''),
                    'filter' => (string) $request->query('filter', ''),
                ],
            ]);
        }

        $products = Product::with('category', 'unit')
            ->withCount('extraBarcodes')
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('barcode', $term)
                ->orWhereHas('extraBarcodes', fn ($b) => $b->where('barcode', $term))))
            ->when($request->category, fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->filter === 'low', fn ($q) => $q->lowStock())
            ->when($request->filter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->filter !== 'inactive', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(25);

        return response()->json([
            'data' => $products->getCollection()->map(fn ($p) => [
                'id' => $p->id,
                'barcode' => $p->barcode,
                'more_barcodes' => $p->extra_barcodes_count,
                'name' => $p->name,
                'category' => $p->category?->name,
                'unit' => $p->unit->abbreviation,
                'cost' => (float) $p->cost_price,
                'price' => (float) $p->selling_price,
                'stock' => (float) $p->stock,
                'low' => $p->isLowStock(),
                'promo' => $p->promoActive() ? $p->promoLabel() : null,
            ]),
            'page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
            'updated_at' => now()->format('g:i:s A'),
        ]);
    }

    public function create(Request $request)
    {
        $product = new Product(['is_active' => true, 'barcode' => $request->query('barcode')]);

        return view('products.form', ['product' => $product, ...$this->options()]);
    }

    public function store(Request $request, Inventory $inventory)
    {
        $data = $this->validated($request);
        $extras = Arr::pull($data, 'extra_barcodes', []);
        $data['barcode'] = $data['barcode'] ?: $this->internalBarcode();
        $opening = (float) ($data['stock'] ?? 0);
        $data['stock'] = 0;

        DB::transaction(function () use ($data, $extras, $opening, $inventory, $request) {
            $product = Product::create($data);
            $this->saveExtraBarcodes($product, $extras);
            if ($opening > 0) {
                $inventory->move($product, $opening, 'opening', 'Opening stock', $request->user()->id);
            }
        });

        return redirect()->route('products.index')->with('success', "Added {$data['name']}.");
    }

    /** Batch add screen (React): scan items, repeat scans add quantity, unknown barcodes become new products. */
    public function batch()
    {
        return view('products.batch', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'abbreviation', 'allows_decimal']),
        ]);
    }

    /** Creates the new products and receives all stock as one delivery, so lots and expiry dates are tracked. */
    public function storeBatch(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer|exists:products,id',
            'items.*.barcode' => ['nullable', 'string', 'max:64', 'distinct', Rule::unique('products', 'barcode'), Rule::unique('product_barcodes', 'barcode')],
            'items.*.box_pieces' => ['nullable', 'numeric', 'gt:0', 'max:100000', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $unit = Unit::find($request->input(str_replace('box_pieces', 'unit_id', $attribute)));
                $this->wholeUnlessDecimal($unit)($attribute, $value, $fail);
            }],
            'items.*.piece_barcode' => ['nullable', 'string', 'max:64', 'distinct', 'different:items.*.barcode', Rule::unique('products', 'barcode'), Rule::unique('product_barcodes', 'barcode')],
            'items.*.name' => 'required_without:items.*.product_id|nullable|string|max:255',
            'items.*.category_id' => 'nullable|exists:categories,id',
            'items.*.unit_id' => 'required_without:items.*.product_id|nullable|exists:units,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.selling_price' => 'required|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
        ], [
            'items.*.barcode.unique' => 'Barcode :input already belongs to another product, which may be inactive.',
            'items.*.barcode.distinct' => 'Barcode :input is on more than one new product.',
        ], [
            'items.*.name' => 'product name',
            'items.*.box_pieces' => 'pieces inside',
            'items.*.piece_barcode' => 'barcode on one piece',
            'items.*.unit_id' => 'unit',
            'items.*.quantity' => 'quantity',
            'items.*.unit_cost' => 'cost',
            'items.*.selling_price' => 'price',
            'items.*.expiry_date' => 'expiry date',
        ]);

        $delivery = DB::transaction(function () use ($data, $inventory, $request) {
            $delivery = Delivery::create([
                'received_at' => now()->toDateString(),
                'notes' => 'Batch add',
                'user_id' => $request->user()->id,
            ]);
            $total = 0;

            foreach ($data['items'] as $item) {
                // A new product delivered in a box: the scanned code is the box, and the main barcode is the piece's
                $isBox = empty($item['product_id']) && ! empty($item['box_pieces']) && ! empty($item['barcode']);
                $mainBarcode = $isBox ? ($item['piece_barcode'] ?? null) : ($item['barcode'] ?? null);

                $product = empty($item['product_id'])
                    ? Product::create([
                        'barcode' => $mainBarcode ?: $this->internalBarcode(),
                        'name' => $item['name'],
                        'category_id' => $item['category_id'] ?? null,
                        'unit_id' => $item['unit_id'],
                        'is_active' => true,
                    ])
                    : Product::lockForUpdate()->findOrFail($item['product_id']);

                if ($isBox) {
                    $product->extraBarcodes()->create(['barcode' => $item['barcode'], 'pieces' => $item['box_pieces'], 'label' => 'Box of '.(float) $item['box_pieces']]);
                }

                $total += $inventory->receive($delivery, $product, $item, $request->user()->id);
            }

            $delivery->update(['total_cost' => round($total, 2)]);

            return $delivery;
        });

        session()->flash('success', 'Batch saved and stock updated.');

        return response()->json(['id' => $delivery->id, 'url' => route('deliveries.show', $delivery)], 201);
    }

    public function edit(Product $product)
    {
        return view('products.form', ['product' => $product, ...$this->options()]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);
        $extras = Arr::pull($data, 'extra_barcodes', []);
        // A blank keeps the current code, unless that code was just moved down as the box's barcode
        $keepCurrent = $product->barcode && ! in_array($product->barcode, array_column($extras, 'barcode'), true);
        $data['barcode'] = $data['barcode'] ?: ($keepCurrent ? $product->barcode : $this->internalBarcode());

        DB::transaction(function () use ($product, $data, $extras) {
            $product->update($data);
            $this->saveExtraBarcodes($product, $extras);
        });

        return redirect()->route('products.index')->with('success', "Saved {$product->name}.");
    }

    /** Adds a barcode to a product straight from a scanning screen, when a scan turns out not to be a new product. */
    public function attachBarcode(Request $request, Product $product)
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:64', $this->barcodeIsFree()],
            'pieces' => ['required', 'numeric', 'gt:0', 'max:100000', $this->wholeUnlessDecimal($product->unit)],
            'label' => 'nullable|string|max:60',
        ], [], ['pieces' => 'pieces inside']);

        $extra = $product->extraBarcodes()->create($data);

        return response()->json($extra->load('product.unit', 'product.category')->toScanArray(), 201);
    }

    /** Sets stock to what is really on the shelf. The stock history keeps the change, the reason and who made it. */
    public function adjustStock(Request $request, Product $product, Inventory $inventory)
    {
        $data = $request->validate([
            'counted' => 'required|numeric|min:0|max:9999999',
            'reason' => ['required', Rule::in(Product::ADJUST_REASONS)],
            'note' => 'nullable|required_if:reason,Other|string|max:200',
        ], ['note.required_if' => 'Add a note saying what happened.'], ['counted' => 'actual stock']);

        $change = DB::transaction(function () use ($data, $product, $inventory, $request) {
            $locked = Product::with('unit')->lockForUpdate()->findOrFail($product->id);
            $counted = round((float) $data['counted'], 3);
            $inventory->assertQuantity($locked, $counted);
            $change = round($counted - (float) $locked->stock, 3);

            if ($change < 0) {
                $inventory->consumeLots($locked, -$change);
            } elseif ($change > 0) {
                $inventory->restoreLots($locked, $change);
            }
            if ($change != 0) {
                $reference = $data['reason'].(empty($data['note']) ? '' : ': '.$data['note']);
                $inventory->move($locked, $change, 'adjust', $reference, $request->user()->id);
            }

            return $change;
        });

        return redirect()->route('products.show', $product)->with('success', $change == 0
            ? "No change. {$product->name} already had that stock on record."
            : "Stock adjusted by ".($change > 0 ? '+' : '').qty($change)." {$product->unit->abbreviation}.");
    }

    public function show(Product $product)
    {
        $product->load('category', 'unit', 'extraBarcodes');
        $lots = $product->lots()->with('delivery.supplier')->where('remaining_qty', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')->get();
        $movements = $product->movements()->with('user')->latest('id')->limit(30)->get();

        return view('products.show', compact('product', 'lots', 'movements'));
    }

    public function destroy(Product $product)
    {
        // Products with history are deactivated, never deleted, so reports stay intact.
        $product->update(['is_active' => false]);

        return redirect()->route('products.index')->with('success', "{$product->name} was deactivated.");
    }

    public function labels(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $products = Product::whereIn('id', $ids)->whereNotNull('barcode')->orderBy('name')->get();
        // Baskets print the same way, from the Baskets page
        $baskets = Basket::whereIn('id', (array) $request->input('basket_ids', []))->orderBy('name')->get();
        $copies = max(1, min(100, (int) $request->input('copies', 1)));
        $labelProps = [
            'copies' => $copies,
            'products' => $products->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'barcode' => $p->barcode, 'price' => (float) $p->selling_price,
            ])->concat($baskets->map(fn ($b) => [
                'id' => 'basket-'.$b->id, 'name' => $b->name, 'barcode' => $b->barcode, 'price' => (float) $b->price,
            ]))->values(),
        ];
        $products = $products->concat($baskets);

        return view('products.labels', compact('products', 'labelProps'));
    }

    /** Barcode lookup used by the POS, receiving and stock count screens. */
    public function lookup(Request $request)
    {
        $code = trim((string) $request->query('barcode'));
        $variants = Product::barcodeVariants($code);

        $product = Product::with('unit', 'category')
            ->where('is_active', true)
            ->whereIn('barcode', $variants)
            ->orderByRaw('barcode = ? DESC', [$code]) // the exact code wins
            ->first();

        if ($product) {
            return response()->json($product->toScanArray());
        }

        $extra = ProductBarcode::with('product.unit', 'product.category')
            ->whereIn('barcode', $variants)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->first();

        if ($extra) {
            return response()->json($extra->toScanArray());
        }

        // Only the cashier sells baskets. Receiving and counting work with the items inside.
        $basket = $request->boolean('baskets') ? Basket::where('is_active', true)->whereIn('barcode', $variants)->first() : null;
        if ($basket) {
            return response()->json($basket->toScanArray());
        }

        return response()->json(['message' => "No product with barcode {$code}."], 404);
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if ($term === '') {
            return response()->json([]);
        }

        $products = Product::with('unit', 'category')
            ->where('is_active', true)
            ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "{$term}%")
                ->orWhereHas('extraBarcodes', fn ($b) => $b->where('barcode', 'like', "{$term}%")))
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json($products->map->toScanArray());
    }

    /** All active products for the tile, keypad and list cashier styles. Best sellers (last 30 days) come first. */
    public function catalog()
    {
        // The best-seller order only sets which cards come first, and this list reloads after every sale on every till,
        // so the 30-day totals are worked out at most every 10 minutes. Stock and prices below are always current.
        [$sold, $basketsSold] = Cache::remember('pos:catalog-sold', 600, fn () => [
            $this->soldLast30Days()->all(),
            $this->basketsSoldLast30Days()->all(),
        ]);

        // Every active product: a cap here would quietly hide the products past it from the tile screens.
        // A store's shelf is a few thousand items at most, a few hundred KB of JSON.
        $products = Product::with('unit', 'category')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $p->toScanArray() + ['reorder' => (float) $p->reorder_level, 'sold' => (float) ($sold[$p->id] ?? 0)]);

        $baskets = Basket::with('items.product.unit')->where('is_active', true)->has('items')->orderBy('name')->get()
            ->map(fn ($b) => $b->toScanArray() + ['reorder' => 0, 'sold' => (float) ($basketsSold[$b->id] ?? 0)]);

        return response()->json($products->concat($baskets)->sortByDesc('sold')->values());
    }

    private function soldLast30Days()
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', now()->subDays(30))
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as qty')
            ->pluck('qty', 'product_id');
    }

    /** Each basket sold is on every one of its item lines, so it counts once per sale. */
    private function basketsSoldLast30Days()
    {
        return DB::table(DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', now()->subDays(30))
            ->whereNotNull('sale_items.basket_id')
            ->select('sale_items.sale_id', 'sale_items.basket_id', 'sale_items.basket_qty')->distinct(), 'sold_baskets')
            ->groupBy('basket_id')->selectRaw('basket_id, SUM(basket_qty) as qty')->pluck('qty', 'basket_id');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        // A row with no barcode was just never filled in
        $request->merge(['extra_barcodes' => collect($request->input('extra_barcodes', []))
            ->filter(fn ($row) => filled($row['barcode'] ?? null))->values()->all()]);

        $data = $request->validate([
            'barcode' => ['nullable', 'string', 'max:64', $this->barcodeIsFree($product)],
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'reorder_level' => 'required|numeric|min:0',
            'stock' => $product ? 'prohibited' : 'nullable|numeric|min:0',
            'extra_barcodes' => 'array|max:50',
            'extra_barcodes.*.barcode' => ['required', 'string', 'max:64', 'distinct', $this->barcodeIsFree($product), function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if (in_array($value, Product::barcodeVariants((string) $request->input('barcode')), true)) {
                    $fail("Barcode {$value} is already this product's main barcode.");
                }
            }],
            'extra_barcodes.*.pieces' => ['required', 'numeric', 'gt:0', 'max:100000', $this->wholeUnlessDecimal(Unit::find($request->input('unit_id')))],
            'extra_barcodes.*.label' => 'nullable|string|max:60',
            'promo_buy' => 'nullable|required_with:promo_take|integer|min:1|max:999',
            'promo_take' => ['nullable', 'required_with:promo_buy', 'integer', 'min:1', 'max:999', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if (Unit::find($request->input('unit_id'))?->allows_decimal) {
                    $fail('Items sold by weight can’t have a Buy and Take promo.');
                }
            }],
            // A past date is allowed, so a product whose promo already ended can still be saved. It just stays off.
            'promo_until' => 'nullable|date',
        ], [], [
            'extra_barcodes.*.barcode' => 'extra barcode',
            'extra_barcodes.*.pieces' => 'pieces inside',
            'extra_barcodes.*.label' => 'barcode name',
            'promo_buy' => 'promo Buy',
            'promo_take' => 'promo Take',
            'promo_until' => 'promo end date',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        if (empty($data['promo_buy'])) {
            $data['promo_buy'] = $data['promo_take'] = $data['promo_until'] = null;
        }

        return $data;
    }

    /** The list in the product form is the whole list, so anything left out of it is removed. */
    private function saveExtraBarcodes(Product $product, array $rows): void
    {
        $product->extraBarcodes()->whereNotIn('barcode', array_column($rows, 'barcode'))->delete();

        foreach ($rows as $row) {
            $product->extraBarcodes()->updateOrCreate(['barcode' => $row['barcode']], ['pieces' => $row['pieces'], 'label' => $row['label'] ?? null]);
        }
    }

    /** One code must always lead to one product, so it can't belong to any other, even a deactivated one. */
    private function barcodeIsFree(?Product $product = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($product) {
            $owner = Product::barcodeOwner((string) $value, $product?->id);
            if ($owner) {
                $fail("Barcode {$value} already belongs to {$owner->name}".($owner->is_active ? '.' : ', which is deactivated.'));
            } elseif ($basket = Basket::barcodeOwner((string) $value)) {
                $fail("Barcode {$value} already belongs to the basket {$basket->name}.");
            }
        };
    }

    /** A scan can't sell half a piece, but a sack of rice can count as 2.5 kilos. */
    private function wholeUnlessDecimal(?Unit $unit): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($unit) {
            if ($unit && ! $unit->allows_decimal && floor((float) $value) != (float) $value) {
                $fail("Pieces inside must be a whole number for items sold by {$unit->name}.");
            }
        };
    }

    private function internalBarcode(): string
    {
        return Product::internalBarcode();
    }

    private function options(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
        ];
    }
}
