<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryController extends Controller
{
    public function index(Request $request)
    {
        $deliveries = Delivery::with('supplier', 'user')->withCount('items')
            ->when($request->supplier, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest('received_at')->latest('id')
            ->paginate(25)->withQueryString();

        return view('deliveries.index', [
            'deliveries' => $deliveries,
            'suppliers' => Supplier::orderBy('name')->get(),
        ]);
    }

    /** Receiving screen (React). */
    public function create()
    {
        return view('deliveries.create', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'reference_no' => 'nullable|string|max:100',
            'received_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.selling_price' => 'nullable|numeric|min:0',
        ]);

        $delivery = DB::transaction(function () use ($data, $inventory, $request) {
            $delivery = Delivery::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'received_at' => $data['received_at'],
                'notes' => $data['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);
            $total = 0;

            foreach ($data['items'] as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                $total += $inventory->receive($delivery, $product, $item, $request->user()->id);
            }

            $delivery->update(['total_cost' => round($total, 2)]);

            return $delivery;
        });

        session()->flash('success', 'Delivery received and stock updated.');

        return response()->json(['id' => $delivery->id, 'url' => route('deliveries.show', $delivery)], 201);
    }

    public function show(Delivery $delivery)
    {
        $delivery->load('items.product.unit', 'supplier', 'user');

        return view('deliveries.show', compact('delivery'));
    }

    /** Expiry dates can be filled in or corrected after saving. Stock and costs don't change. */
    public function updateExpiry(Request $request, Delivery $delivery)
    {
        $data = $request->validate([
            'expiry' => 'required|array',
            'expiry.*' => 'nullable|date',
        ], [], ['expiry.*' => 'expiry date']);

        $changed = 0;
        foreach ($delivery->items as $item) {
            if (array_key_exists($item->id, $data['expiry'])) {
                $item->expiry_date = $data['expiry'][$item->id];
                if ($item->isDirty('expiry_date')) {
                    $item->save();
                    $changed++;
                }
            }
        }

        return back()->with('success', $changed ? "Saved {$changed} expiry date".($changed === 1 ? '' : 's').'.' : 'No dates changed.');
    }

    /** Boxes in one line can expire on different dates: moves some pieces still on the shelf to a line of their own. */
    public function splitItem(Request $request, Delivery $delivery, DeliveryItem $item, Inventory $inventory)
    {
        abort_unless($item->delivery_id === $delivery->id, 404);

        $data = $request->validate([
            'quantity' => 'required|numeric|gt:0',
            'expiry_date' => 'required|date',
        ], [], ['quantity' => 'quantity to move', 'expiry_date' => 'expiry date']);

        DB::transaction(function () use ($data, $delivery, $item, $inventory) {
            $line = DeliveryItem::with('product.unit')->lockForUpdate()->findOrFail($item->id);
            $qty = round((float) $data['quantity'], 3);
            $inventory->assertQuantity($line->product, $qty);

            if ($qty > (float) $line->remaining_qty) {
                throw ValidationException::withMessages(['quantity' => 'Only '.qty($line->remaining_qty)." {$line->product->unit->abbreviation} of {$line->product->name} are still on the shelf."]);
            }
            if ($qty >= (float) $line->quantity) {
                throw ValidationException::withMessages(['quantity' => 'That is the whole line, so just change its date.']);
            }

            $line->update([
                'quantity' => round((float) $line->quantity - $qty, 3),
                'remaining_qty' => round((float) $line->remaining_qty - $qty, 3),
            ]);
            $delivery->items()->create([
                'product_id' => $line->product_id,
                'quantity' => $qty,
                'remaining_qty' => $qty,
                'unit_cost' => $line->unit_cost,
                'expiry_date' => $data['expiry_date'],
            ]);
        });

        return back()->with('success', 'Moved '.qty($data['quantity'])." {$item->product->unit->abbreviation} of {$item->product->name} to its own expiry date.");
    }
}
