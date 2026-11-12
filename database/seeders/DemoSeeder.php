<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sample products, deliveries and a week of sales so the dashboard has something to show.
 * For trying the app out or a staging copy only: php artisan db:seed --class=DemoSeeder
 * Needs the arnold and cashier accounts (php artisan db:seed) and does nothing if products exist.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Product::exists()) {
            $this->command?->warn('Products already exist, so no sample data was added. Empty the store first: php artisan store:clear');

            return;
        }

        $owner = User::where('username', 'arnold')->first();
        $cashier = User::where('username', 'cashier')->first();
        if (! $owner || ! $cashier) {
            $this->command?->error('The sample sales need the arnold and cashier accounts. Run php artisan db:seed first.');

            return;
        }

        $this->call(StarterListsSeeder::class);
        $inventory = app(Inventory::class);
        $cat = Category::pluck('id', 'name');
        $unit = Unit::pluck('id', 'abbreviation');

        $supplier = Supplier::create(['name' => 'Metro Wholesale Distributors', 'contact_person' => 'Liza Ramos', 'phone' => '0917 555 0142', 'address' => 'Poblacion']);

        // [barcode, name, category, unit, cost, price, reorder, delivered qty, days to expiry]
        $items = [
            ['4800024555011', 'Sardines in Tomato Sauce 155g', 'Canned Goods', 'pc', 19.50, 24.00, 24, 96, 400],
            ['4800024555028', 'Corned Beef 150g', 'Canned Goods', 'pc', 36.00, 45.00, 12, 48, 520],
            ['4800024555035', 'Meat Loaf 150g', 'Canned Goods', 'pc', 22.00, 28.00, 12, 36, 18],
            [null, 'Dinorado Rice', 'Rice', 'kg', 52.00, 62.00, 25, 150, null],
            [null, 'Sinandomeng Rice', 'Rice', 'kg', 44.00, 52.00, 25, 200, null],
            ['4800016644511', 'Potato Chips 60g', 'Snacks', 'pc', 22.00, 30.00, 10, 40, 75],
            ['4800016644528', 'Cheese Crackers 10s', 'Snacks', 'pack', 48.00, 58.00, 6, 20, 120],
            ['4801981118502', 'Cola 1.5L', 'Beverages', 'pc', 62.00, 75.00, 12, 48, 180],
            ['4801981118519', 'Bottled Water 500ml', 'Beverages', 'pc', 9.00, 15.00, 24, 120, 365],
            ['4801981118526', 'Bottled Water 500ml (Case of 24)', 'Beverages', 'case', 210.00, 280.00, 2, 6, 365],
            ['4800110055013', 'Hotdog Regular 1kg', 'Frozen Goods', 'pack', 155.00, 185.00, 5, 15, 9],
            ['4800110055020', 'Chicken Nuggets 200g', 'Frozen Goods', 'pack', 68.00, 85.00, 5, 12, 45],
            ['4800888100013', 'Bath Soap 135g', 'Toiletries', 'pc', 38.00, 48.00, 10, 30, null],
            ['4800888100020', 'Shampoo Sachet 12ml (6s)', 'Toiletries', 'pack', 30.00, 38.00, 10, 25, null],
            ['4800047820013', 'Soy Sauce 1L', 'Condiments', 'pc', 48.00, 58.00, 6, 24, 300],
            ['4800047820020', 'Cane Vinegar 1L', 'Condiments', 'pc', 32.00, 40.00, 6, 24, 300],
            ['4800016055017', 'Instant Pancit Canton', 'Noodles', 'pc', 14.00, 18.00, 30, 120, 160],
            ['4800016055024', 'Instant Mami Chicken', 'Noodles', 'pc', 10.50, 14.00, 30, 4, 140],
        ];

        DB::transaction(function () use ($items, $cat, $unit, $supplier, $owner, $inventory) {
            $delivery = Delivery::create([
                'reference_no' => 'INV-88214',
                'supplier_id' => $supplier->id,
                'user_id' => $owner->id,
                'received_at' => Carbon::today()->subDays(8),
            ]);
            $total = 0;

            foreach ($items as [$barcode, $name, $category, $u, $cost, $price, $reorder, $qty, $expiryDays]) {
                $product = Product::create([
                    'barcode' => $barcode ?? '20'.str_pad((string) crc32($name), 11, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'category_id' => $cat[$category],
                    'unit_id' => $unit[$u],
                    'cost_price' => $cost,
                    'selling_price' => $price,
                    'reorder_level' => $reorder,
                ]);
                $delivery->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'remaining_qty' => $qty,
                    'unit_cost' => $cost,
                    'expiry_date' => $expiryDays ? Carbon::today()->addDays($expiryDays) : null,
                ]);
                $inventory->move($product, $qty, 'delivery', 'DR-'.str_pad((string) $delivery->id, 5, '0', STR_PAD_LEFT), $owner->id);
                $total += $qty * $cost;
            }
            $delivery->update(['total_cost' => $total]);
        });

        // A week of small sales
        mt_srand(42);
        $products = Product::with('unit')->get();
        for ($ago = 6; $ago >= 0; $ago--) {
            for ($n = 0; $n < mt_rand(6, 14); $n++) {
                $at = Carbon::today()->subDays($ago)->setTime(mt_rand(7, 19), mt_rand(0, 59));
                if ($at->isFuture()) {
                    continue;
                }
                DB::transaction(function () use ($products, $at, $cashier, $inventory) {
                    $lines = $products->random(mt_rand(1, 4))->filter(fn ($p) => (float) $p->stock > 3);
                    if ($lines->isEmpty()) {
                        return;
                    }
                    $sale = new Sale(['receipt_no' => 'TMP-'.uniqid(), 'user_id' => $cashier->id, 'subtotal' => 0, 'total' => 0, 'amount_paid' => 0, 'change' => 0]);
                    $sale->created_at = $sale->updated_at = $at;
                    $sale->save();
                    $sale->receipt_no = 'OR-'.$at->format('ymd').'-'.str_pad((string) $sale->id, 5, '0', STR_PAD_LEFT);
                    $subtotal = 0;
                    foreach ($lines as $product) {
                        $qty = $product->unit->allows_decimal ? [0.5, 1, 2, 5][mt_rand(0, 3)] : mt_rand(1, 3);
                        $line = round($qty * (float) $product->selling_price, 2);
                        $sale->items()->create([
                            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty,
                            'unit_price' => $product->selling_price, 'unit_cost' => $product->cost_price, 'line_total' => $line,
                        ]);
                        $inventory->consumeLots($product, $qty);
                        $inventory->move($product, -$qty, 'sale', $sale->receipt_no, $cashier->id);
                        $subtotal += $line;
                    }
                    $paid = ceil($subtotal / 50) * 50;
                    $sale->fill(['subtotal' => $subtotal, 'total' => $subtotal, 'amount_paid' => $paid, 'change' => $paid - $subtotal])->save();
                });
            }
        }
    }
}
