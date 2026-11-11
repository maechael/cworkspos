<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/DashboardTest.php
 */
class DashboardTest extends TestCase
{
    private User $admin;

    private int $receipt = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        // A Wednesday at 10:00, so this week started on Monday the 2nd
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.test', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $at, float $total, string $status = 'completed', string $method = 'cash'): void
    {
        DB::table('sales')->insert([
            'receipt_no' => 'R-'.++$this->receipt,
            'user_id' => $this->admin->id,
            'subtotal' => $total, 'discount' => 0, 'total' => $total, 'amount_paid' => $total, 'change' => 0,
            'payment_method' => $method, 'status' => $status,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_the_period_picks_which_sales_the_table_shows(): void
    {
        $this->sale('2026-11-04 09:00:00', 10); // today
        $this->sale('2026-11-02 09:00:00', 10); // this week
        $this->sale('2026-11-01 09:00:00', 10); // this month, last week
        $this->sale('2026-10-30 09:00:00', 10); // last month

        $total = fn ($period) => $this->actingAs($this->admin)->getJson("/dashboard?period={$period}")->assertOk()->json('sales.total');

        $this->assertSame(1, $total('today'));
        $this->assertSame(2, $total('week'));
        $this->assertSame(3, $total('month'));
    }

    public function test_the_table_follows_period_filter_and_page_size(): void
    {
        foreach (range(1, 6) as $i) {
            $this->sale('2026-11-03 09:0'.$i.':00', 10);
        }
        $this->sale('2026-11-03 10:00:00', 10, 'voided', 'gcash');
        $this->sale('2026-10-01 10:00:00', 10);

        $page = $this->actingAs($this->admin)->getJson('/dashboard?period=week&per_page=5&page=2')->json('sales');
        $this->assertSame(7, $page['total']);
        $this->assertSame(2, $page['last_page']);
        $this->assertSame(6, $page['from']);
        $this->assertCount(2, $page['rows']);

        $voided = $this->getJson('/dashboard?period=week&filter=voided')->json('sales');
        $this->assertSame(1, $voided['total']);
        $this->assertTrue($voided['rows'][0]['voided']);
        $this->assertSame(1, $this->getJson('/dashboard?period=week&filter=gcash')->json('sales.total'));

        // Unknown values fall back to the defaults
        $fallback = $this->getJson('/dashboard?period=nonsense&per_page=9999')->json();
        $this->assertSame('week', $fallback['period']);
        $this->assertSame(5, $fallback['sales']['per_page']);
    }

    public function test_export_has_every_matching_sale_and_defuses_formulas(): void
    {
        $this->admin->update(['name' => '=SUM(A1)']);
        $this->sale('2026-11-03 09:00:00', 10);
        $this->sale('2026-11-03 09:30:00', 20, 'voided');
        $this->sale('2026-10-01 09:00:00', 30);

        $csv = $this->actingAs($this->admin)->get('/dashboard/export?period=week&filter=completed')->assertOk()->streamedContent();

        $this->assertStringContainsString('R-1', $csv);
        $this->assertStringNotContainsString('R-2', $csv);
        $this->assertStringNotContainsString('R-3', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);
    }

    /** A product with stock, optionally with a lot that expires on a given date. */
    private function product(string $name, ?int $category, float $stock, float $cost, float $reorder = 0, ?string $expiry = null): int
    {
        $unit = DB::table('units')->where('abbreviation', 'pc')->value('id')
            ?? DB::table('units')->insertGetId(['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false]);
        $id = DB::table('products')->insertGetId([
            'name' => $name, 'category_id' => $category, 'unit_id' => $unit, 'cost_price' => $cost, 'selling_price' => $cost * 2,
            'stock' => $stock, 'reorder_level' => $reorder, 'is_active' => true,
        ]);

        if ($expiry) {
            $delivery = DB::table('deliveries')->insertGetId(['user_id' => $this->admin->id, 'received_at' => '2026-11-04', 'total_cost' => 0]);
            DB::table('delivery_items')->insert(['delivery_id' => $delivery, 'product_id' => $id, 'quantity' => $stock, 'remaining_qty' => $stock, 'unit_cost' => $cost, 'expiry_date' => $expiry]);
        }

        return $id;
    }

    public function test_the_removed_charts_are_gone_and_the_others_still_get_their_data(): void
    {
        $this->sale('2026-11-03 09:00:00', 100);

        $data = $this->actingAs($this->admin)->getJson('/dashboard')->assertOk()->json();

        $this->assertArrayNotHasKey('trend', $data);
        $this->assertArrayNotHasKey('inventory', $data);
        $this->assertArrayNotHasKey('targets', $data);
        $this->assertArrayNotHasKey('cards', $data);
        $this->assertCount(14, $data['daily']['labels']);
        $this->assertSame(1, $data['daily']['transactions'][12]);

        // A voided sale isn't a transaction
        $this->sale('2026-11-03 09:30:00', 100, 'voided');
        $this->assertSame(1, $this->getJson('/dashboard')->json('daily.transactions.12'));
    }

    public function test_the_week_runs_around_today_with_deliveries_and_expiring_lots(): void
    {
        $this->product('Milk', null, 5, 10, 0, '2026-11-06');

        $week = $this->actingAs($this->admin)->getJson('/dashboard')->json('week');

        $this->assertSame('November', $week['month']);
        $this->assertCount(7, $week['days']);
        $this->assertSame('2026-11-02', $week['days'][0]['date']);
        $this->assertTrue($week['days'][2]['today']);
        $this->assertSame(1, $week['days'][2]['deliveries']);
        $this->assertSame(1, $week['days'][4]['expiring']);
        $this->assertSame(0, $week['days'][3]['expiring']);
    }

    public function test_the_page_renders_and_cashiers_cannot_see_it(): void
    {
        $this->sale('2026-11-03 09:00:00', 10);
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('data-react="Dashboard"', false);
        $this->get('/products/batch')->assertOk()->assertSee('Log out')->assertSee('Master data')->assertSee('Create');

        $cashier = User::create(['name' => 'Cashier', 'username' => 'cash', 'email' => 'cash@example.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
        $this->actingAs($cashier)->get('/dashboard')->assertForbidden();
        $this->get('/dashboard/export')->assertForbidden();
        $this->get('/pos')->assertOk()->assertSee('Log out');

        // The sidebar only offers a cashier their own pages
        $this->get('/sales')->assertOk()->assertSee('My sales')->assertDontSee('Master data')->assertDontSee('Receive delivery');
    }
}
