<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockCount;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockCountController extends Controller
{
    public function index()
    {
        $counts = StockCount::with('user')->withCount('items')
            ->withSum('items as net_variance', 'variance')
            ->latest()->paginate(25);

        return view('stock-counts.index', compact('counts'));
    }

    /** Counting screen (React). */
    public function create()
    {
        return view('stock-counts.create');
    }

    public function store(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.counted_qty' => 'required|numeric|min:0',
        ]);

        $count = DB::transaction(function () use ($data, $inventory, $request) {
            $count = StockCount::create(['user_id' => $request->user()->id, 'notes' => $data['notes'] ?? null]);
            $reference = 'SC-'.str_pad((string) $count->id, 5, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                $system = (float) $product->stock;
                $counted = round((float) $item['counted_qty'], 3);
                $inventory->assertQuantity($product, $counted);
                $variance = round($counted - $system, 3);

                $count->items()->create([
                    'product_id' => $product->id,
                    'system_qty' => $system,
                    'counted_qty' => $counted,
                    'variance' => $variance,
                ]);

                if ($variance < 0) {
                    $inventory->consumeLots($product, -$variance);
                } elseif ($variance > 0) {
                    $inventory->restoreLots($product, $variance);
                }
                if ($variance != 0) {
                    $inventory->move($product, $variance, 'count', $reference, $request->user()->id);
                }
            }

            return $count;
        });

        session()->flash('success', 'Stock count saved. Stock levels now match what was counted.');

        return response()->json(['id' => $count->id, 'url' => route('stock-counts.show', $count)], 201);
    }

    public function show(StockCount $stockCount)
    {
        $stockCount->load('items.product.unit', 'user');

        return view('stock-counts.show', ['count' => $stockCount]);
    }
}
