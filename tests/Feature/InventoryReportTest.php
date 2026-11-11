<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/InventoryReportTest.php
 */
class InventoryReportTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    private int $receipt = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        // November 7, so 24 days are left in the month, today included
        Carbon::setTestNow('2026-11-07 10:00:00');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $stock, float $cost = 10, float $price = 15, ?int $category = null): Product
    {
        return Product::create(['barcode' => '48'.str_pad((string) crc32($name), 11, '0'), 'name' => $name, 'category_id' => $category, 'unit_id' => $this->piece->id, 'cost_price' => $cost, 'selling_price' => $price, 'stock' => $stock, 'is_active' => true]);
    }

    private function sold(Product $product, float $qty, string $at, string $status = 'completed', float $discount = 0): void
    {
        $total = $qty * (float) $product->selling_price;
        $sale = DB::table('sales')->insertGetId([
            'receipt_no' => 'R-'.++$this->receipt, 'user_id' => $this->admin->id, 'subtotal' => $total, 'discount' => $discount, 'total' => $total - $discount,
            'amount_paid' => $total, 'change' => 0, 'payment_method' => 'cash', 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty,
            'unit_price' => $product->selling_price, 'unit_cost' => $product->cost_price, 'line_total' => $total,
        ]);
    }

    private function report(array $query = [])
    {
        return $this->get(route('reports.inventory', $query))->assertOk();
    }

    private function row($response, Product $product): array
    {
        return $response->viewData('rows')->first(fn ($r) => $r['product']->is($product));
    }

    public function test_it_defaults_to_the_last_30_days_and_shows_todays_stock(): void
    {
        $coffee = $this->product('Coffee', 100);

        $response = $this->report();

        $this->assertSame('2026-10-09', $response->viewData('from')->toDateString());
        $this->assertSame('2026-11-07', $response->viewData('to')->toDateString());
        $this->assertEquals(1000, $response->viewData('totalCost'));
        $this->assertEquals(1500, $response->viewData('totalRetail'));
        $this->assertSame(24, $response->viewData('monthDaysLeft'));
        $response->assertSee('Coffee')->assertSee('₱500.00');
    }

    public function test_the_estimate_follows_the_selling_speed(): void
    {
        $coffee = $this->product('Coffee', 1000);
        // The store's first sale was 30 days ago, so the speed is 60 over 30 days: 2 a day
        $this->sold($coffee, 60, '2026-10-09 09:00:00');

        $response = $this->report();
        $row = $this->row($response, $coffee);

        $this->assertEquals(60, $row['sold']);
        $this->assertEquals(2, $row['per_day']);
        $this->assertSame(500, $row['days_of_stock']);
        $this->assertEquals(240, $row['expected_month']); // 2 a day x 24 days x ₱5 profit
        $this->assertEquals(300, $row['expected_30']);
        $this->assertEquals(240, $response->viewData('expectedMonth'));
        $this->assertEquals(300, $response->viewData('profitMade'));
    }

    public function test_an_item_cant_earn_past_what_is_in_stock(): void
    {
        $coffee = $this->product('Coffee', 10);
        $this->sold($coffee, 60, '2026-10-09 09:00:00');

        $row = $this->row($this->report(), $coffee);

        $this->assertSame(5, $row['days_of_stock']);
        $this->assertEquals(50, $row['expected_month']);
        $this->assertEquals(50, $row['expected_30']);
    }

    public function test_days_before_the_first_sale_dont_slow_the_speed(): void
    {
        $coffee = $this->product('Coffee', 1000);
        // Open for 10 days, Oct 29 to Nov 7, in a 30-day window
        $this->sold($coffee, 20, '2026-10-29 09:00:00');

        $response = $this->report();

        $this->assertSame(10, $response->viewData('speedDays'));
        $this->assertEquals(2, $this->row($response, $coffee)['per_day']);
    }

    public function test_the_dates_pick_which_sales_count(): void
    {
        $coffee = $this->product('Coffee', 1000);
        $this->sold($coffee, 10, '2026-10-10 09:00:00');
        $this->sold($coffee, 14, '2026-11-02 09:00:00');

        $month = $this->report(['from' => '2026-11-01', 'to' => '2026-11-07']);
        $this->assertEquals(14, $this->row($month, $coffee)['sold']);
        $this->assertSame(7, $month->viewData('speedDays'));
        $this->assertEquals(2, $this->row($month, $coffee)['per_day']);
        $this->assertEquals(70, $month->viewData('profitMade'));

        $october = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertEquals(10, $this->row($october, $coffee)['sold']);
    }

    public function test_voided_sales_and_inactive_products_are_left_out(): void
    {
        $coffee = $this->product('Coffee', 1000);
        $old = $this->product('Old item', 5);
        $old->update(['is_active' => false]);
        $this->sold($coffee, 99, '2026-11-02 09:00:00', 'voided');

        $response = $this->report();

        $this->assertEquals(0, $this->row($response, $coffee)['sold']);
        $this->assertNull($this->row($response, $coffee)['days_of_stock']);
        $this->assertEquals(0, $response->viewData('expectedMonth'));
        $this->assertCount(1, $response->viewData('rows'));
        $response->assertSee('No sales yet');
    }

    public function test_profit_made_matches_the_sales_report_after_discounts(): void
    {
        $coffee = $this->product('Coffee', 1000);
        $this->sold($coffee, 10, '2026-11-02 09:00:00', 'completed', 20);

        $inventory = $this->report(['from' => '2026-11-01', 'to' => '2026-11-07']);
        $this->assertEquals(30, $inventory->viewData('profitMade'));
        $inventory->assertSee('after discounts');

        $sales = $this->get(route('reports.sales', ['from' => '2026-11-01', 'to' => '2026-11-07']))->assertOk();
        $sales->assertSee(peso(30));
    }

    public function test_a_category_narrows_everything_and_shows_profit_before_discounts(): void
    {
        $drinks = Category::create(['name' => 'Drinks']);
        $coffee = $this->product('Coffee', 100, 10, 15, $drinks->id);
        $soap = $this->product('Soap', 100);
        $this->sold($coffee, 10, '2026-11-02 09:00:00', 'completed', 5);
        $this->sold($soap, 10, '2026-11-02 09:00:00');

        $response = $this->report(['category' => $drinks->id]);

        $this->assertCount(1, $response->viewData('rows'));
        $this->assertEquals(1000, $response->viewData('totalCost'));
        $this->assertEquals(50, $response->viewData('profitMade'));
        $response->assertSee('before discounts');
    }

    public function test_odd_dates_stay_sensible(): void
    {
        $this->product('Coffee', 10);

        $future = $this->report(['from' => '2026-11-01', 'to' => '2027-05-01']);
        $this->assertSame('2026-11-07', $future->viewData('to')->toDateString());

        $backwards = $this->report(['from' => '2026-11-05', 'to' => '2026-11-01']);
        $this->assertSame('2026-11-01', $backwards->viewData('from')->toDateString());
        $this->assertSame(1, $backwards->viewData('speedDays'));

        $this->get(route('reports.inventory', ['from' => 'soon']))->assertSessionHasErrors('from');
    }

    public function test_empty_and_negative_stock_earn_nothing(): void
    {
        $out = $this->product('Out', 0);
        $negative = $this->product('Negative', -40);
        $this->sold($out, 30, '2026-11-02 09:00:00');
        $this->sold($negative, 30, '2026-11-02 09:00:00');

        $response = $this->report();

        $this->assertEquals(0, $this->row($response, $out)['expected_month']);
        $this->assertEquals(0, $this->row($response, $negative)['expected_30']);
        $response->assertSee('Out of stock');
    }

    public function test_a_cashier_cant_see_it(): void
    {
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->get(route('reports.inventory'))->assertForbidden();
    }
}
