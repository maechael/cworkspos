<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Baskets: several items sold together at one price. Owners set them up here; the cashier sells them like an item. */
class BasketController extends Controller
{
    public function index(Request $request)
    {
        $baskets = Basket::with('items.product.unit')
            ->when($request->query('show') !== 'all', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return view('baskets.index', compact('baskets'));
    }

    public function create()
    {
        return view('baskets.form', ['basket' => new Basket(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $basket = Basket::create($data['basket']);
            $basket->items()->createMany($data['items']);
        });

        return redirect()->route('baskets.index')->with('success', "Added the basket {$data['basket']['name']}.");
    }

    public function edit(Basket $basket)
    {
        $basket->load('items.product.unit', 'items.product.category');

        return view('baskets.form', compact('basket'));
    }

    public function update(Request $request, Basket $basket)
    {
        $data = $this->validated($request, $basket);

        // Past sales keep their own lines, so changing what's inside only affects baskets sold from now on
        DB::transaction(function () use ($basket, $data) {
            $basket->update($data['basket']);
            $basket->items()->delete();
            $basket->items()->createMany($data['items']);
        });

        return redirect()->route('baskets.index')->with('success', "Saved the basket {$basket->name}.");
    }

    /** A basket that was sold is only turned off, so its sales still show what it was. */
    public function destroy(Basket $basket)
    {
        if ($basket->saleItems()->exists()) {
            $basket->update(['is_active' => false]);

            return redirect()->route('baskets.index')->with('success', "{$basket->name} was turned off. It has sales, so it is kept.");
        }

        $basket->delete();

        return redirect()->route('baskets.index')->with('success', "Deleted the basket {$basket->name}.");
    }

    private function validated(Request $request, ?Basket $basket = null): array
    {
        // A row with no product was never filled in
        $request->merge(['items' => collect($request->input('items', []))->filter(fn ($row) => filled($row['product_id'] ?? null))->values()->all()]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'barcode' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) use ($basket) {
                if ($owner = Product::barcodeOwner((string) $value)) {
                    $fail("Barcode {$value} already belongs to the product {$owner->name}.");
                } elseif ($other = Basket::barcodeOwner((string) $value, $basket?->id)) {
                    $fail("Barcode {$value} already belongs to the basket {$other->name}.");
                }
            }],
            'price' => 'required|numeric|min:0|max:9999999',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $index = explode('.', $attribute)[1];
                $product = Product::with('unit')->find($request->input("items.{$index}.product_id"));
                if ($product && ! $product->unit->allows_decimal && floor((float) $value) != (float) $value) {
                    $fail("{$product->name} is counted per {$product->unit->name}, so its quantity must be a whole number.");
                }
            }],
        ], [
            'items.required' => 'Add at least one item to the basket.',
            'items.*.product_id.distinct' => 'An item is in the basket twice. Put it in once with a bigger quantity.',
        ], [
            'items.*.product_id' => 'item',
            'items.*.quantity' => 'quantity',
        ]);

        return [
            'basket' => [
                'name' => $data['name'],
                'barcode' => $data['barcode'] ?: ($basket?->barcode ?? Product::internalBarcode()),
                'price' => $data['price'],
                'is_active' => $request->boolean('is_active'),
            ],
            'items' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['quantity']], $data['items']),
        ];
    }
}
