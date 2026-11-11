<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The queries rewritten for years of data must still give the same answers.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/LargeDataQueriesTest.php
 */
class LargeDataQueriesTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    private int $receipt = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        Cache::flush();
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->actingAs($this->admin);
    }

    private function product(string $name, ?int $category = null, float $price = 15): Product
    {
        return Product::create(['barcode' => '48'.str_pad((string) crc32($name), 11, '0'), 'name' => $name, 'category_id' => $category, 'unit_id' => $this->piece->id, 'cost_price' => $price - 5, 'selling_price' => $price, 'stock' => 100, 'is_active' => true]);
    }

    private function sold(Product $product, float $qty, ?string $at = null, string $status = 'completed'): string
    {
        $at ??= now()->toDateTimeString();
        $total = $qty * (float) $product->selling_price;
        $no = 'OR-'.now()->format('ymd').'-'.str_pad((string) ++$this->receipt, 5, '0', STR_PAD_LEFT);
        $sale = DB::table('sales')->insertGetId(['receipt_no' => $no, 'user_id' => $this->admin->id, 'subtotal' => $total, 'discount' => 0, 'total' => $total,
            'amount_paid' => $total, 'change' => 0, 'payment_method' => 'cash', 'status' => $status, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('sale_items')->insert(['sale_id' => $sale, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty,
            'unit_price' => $product->selling_price, 'unit_cost' => $product->cost_price, 'line_total' => $total]);

        return $no;
    }

    public function test_the_indexes_for_large_data_are_there(): void
    {
        $this->assertTrue(Schema::hasIndex('sales', 'sales_created_status_index'));
        $this->assertTrue(Schema::hasIndex('sale_items', 'sale_items_sale_totals_index'));
        $this->assertTrue(Schema::hasIndex('stock_movements', 'stock_movements_product_id_id_index'));
        $this->assertTrue(Schema::hasIndex('delivery_items', 'delivery_items_remaining_expiry_index'));
        $this->assertFalse(Schema::hasIndex('sales', 'sales_created_at_index'));
    }

    public function test_the_sales_report_still_adds_up_by_day_category_and_product(): void
    {
        $drinks = Category::create(['name' => 'Drinks']);
        $coffee = $this->product('Coffee', $drinks->id);
        $tea = $this->product('Tea', $drinks->id, 20);
        $soap = $this->product('Soap');
        $this->sold($coffee, 2);
        $this->sold($coffee, 1);
        $this->sold($tea, 1);
        $this->sold($soap, 4);
        $this->sold($soap, 50, null, 'voided');

        $response = $this->get(route('reports.sales'))->assertOk();

        $this->assertEquals(125, $response->viewData('daily')->sum('gross')); // coffee 45 + tea 20 + soap 60, the voided sale left out
        $this->assertEquals(4, $response->viewData('daily')->sum('transactions'));
        $this->assertEquals([['Soap', 4, 60], ['Coffee', 3, 45]], $response->viewData('byProduct')->take(2)->map(fn ($p) => [$p->product_name, (float) $p->qty, (float) $p->gross])->all());
        $this->assertEquals(['Drinks' => 65.0, 'Uncategorized' => 60.0], $response->viewData('byCategory')->mapWithKeys(fn ($c) => [$c->category => (float) $c->gross])->all());
        $this->assertEquals(['Drinks' => 20.0, 'Uncategorized' => 20.0], $response->viewData('byCategory')->mapWithKeys(fn ($c) => [$c->category => (float) $c->profit])->all());
    }

    public function test_stock_history_search_finds_a_product_by_name_or_any_barcode(): void
    {
        $coffee = $this->product('Coffee');
        $soap = $this->product('Soap');
        $coffee->extraBarcodes()->create(['barcode' => '9990001112223', 'pieces' => 10]);
        foreach ([$coffee, $soap] as $product) {
            DB::table('stock_movements')->insert(['product_id' => $product->id, 'type' => 'delivery', 'quantity' => 5, 'balance_after' => 5, 'reference' => 'DR-'.$product->name, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get(route('reports.movements', ['q' => 'coff']))->assertOk()->assertSee('DR-Coffee')->assertDontSee('DR-Soap');
        $this->get(route('reports.movements', ['q' => '9990001112223']))->assertOk()->assertSee('DR-Coffee')->assertDontSee('DR-Soap');
        $this->get(route('reports.movements', ['q' => 'nothing like it']))->assertOk()->assertSee('No movements.');
    }

    public function test_stock_history_pages_without_counting_everything(): void
    {
        $coffee = $this->product('Coffee');
        DB::table('stock_movements')->insert(array_map(fn ($i) => ['product_id' => $coffee->id, 'type' => 'sale', 'quantity' => -1, 'balance_after' => 100 - $i, 'reference' => "R-{$i}", 'created_at' => now(), 'updated_at' => now()], range(1, 60)));

        $first = $this->get(route('reports.movements'))->assertOk()->assertSee('R-60')->assertDontSee('R-10<');
        $this->assertTrue($first->viewData('movements')->hasMorePages());
        $this->get(route('reports.movements', ['page' => 2]))->assertOk()->assertSee('R-10');
    }

    public function test_a_whole_receipt_number_is_found_exactly_and_part_of_one_still_works(): void
    {
        $coffee = $this->product('Coffee');
        $first = $this->sold($coffee, 1);
        $this->sold($coffee, 1);

        $json = ['Accept' => 'application/json'];
        $this->get(route('sales.index', ['q' => strtolower($first)]), $json)->assertJsonCount(1, 'data')->assertJsonPath('data.0.receipt_no', $first);
        $this->get(route('sales.index', ['q' => substr($first, -5)]), $json)->assertJsonCount(1, 'data');
        $this->get(route('sales.index', ['q' => '%']), $json)->assertJsonCount(0, 'data'); // a typed % is a character, not "anything"
    }

    public function test_the_cashier_list_has_every_active_product_even_past_a_thousand(): void
    {
        $now = now();
        DB::table('products')->insert(array_map(fn ($i) => ['barcode' => '47'.str_pad((string) $i, 11, '0', STR_PAD_LEFT), 'name' => sprintf('Item %04d', $i), 'unit_id' => $this->piece->id,
            'cost_price' => 1, 'selling_price' => 2, 'stock' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], range(1, 1100)));

        $catalog = $this->getJson(route('products.catalog'))->assertOk()->json();

        $this->assertCount(1100, $catalog);
        $this->assertContains('Item 1100', array_column($catalog, 'name'));
    }

    public function test_best_sellers_come_first_on_the_cashier_list(): void
    {
        $this->product('Apple');
        $soap = $this->product('Soap');
        $this->sold($soap, 3);

        $this->assertSame('Soap', $this->getJson(route('products.catalog'))->json('0.name'));
    }

    public function test_the_dashboard_top_sellers_group_by_product(): void
    {
        $coffee = $this->product('Coffee');
        $this->sold($coffee, 2);
        $this->sold($coffee, 3);

        $this->getJson(route('dashboard'))->assertOk()->assertJsonPath('top_products.0', ['name' => 'Coffee', 'qty' => 5, 'total' => 75]);
    }
}
