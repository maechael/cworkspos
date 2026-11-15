<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cashier's open order is kept on the server as it changes, so a refresh or a brownout doesn't lose it,
 * and a paid order never comes back.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/PosDraftTest.php
 */
class PosDraftTest extends TestCase
{
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }
        $this->artisan('migrate');
        Cache::flush();
        $this->cashier = User::create(['name' => 'Liza', 'username' => 'liza', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
    }

    private function order(): array
    {
        return ['cart' => [['id' => 7, 'name' => 'Cola 1.5L', 'qty' => 2, 'price' => 75]], 'held' => [], 'savedAt' => 1763190000000];
    }

    public function test_the_open_order_is_kept_and_handed_back_to_the_cashier_screen(): void
    {
        $this->actingAs($this->cashier)->putJson(route('pos.draft'), $this->order())->assertNoContent();

        $props = $this->get(route('pos.index'))->assertOk()->viewData('props');
        $this->assertSame('Cola 1.5L', $props['draft']['cart'][0]['name']);
        $this->assertSame($this->cashier->id, $props['userId']);
    }

    public function test_each_cashier_has_their_own(): void
    {
        $this->actingAs($this->cashier)->putJson(route('pos.draft'), $this->order());
        $other = User::create(['name' => 'Ana', 'username' => 'ana', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->assertNull($this->actingAs($other)->get(route('pos.index'))->viewData('props')['draft']);
    }

    public function test_paying_clears_the_kept_order_but_not_held_sales(): void
    {
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false]);
        $cola = Product::create(['barcode' => '4801981118502', 'name' => 'Cola 1.5L', 'unit_id' => $piece->id, 'cost_price' => 62, 'selling_price' => 75, 'stock' => 10, 'is_active' => true]);
        $held = [['id' => 1, 'cart' => [['id' => $cola->id, 'qty' => 1]], 'at' => '2026-11-15T02:00:00Z']];
        $this->actingAs($this->cashier)->putJson(route('pos.draft'), ['held' => $held] + $this->order());

        $this->postJson(route('sales.store'), ['items' => [['product_id' => $cola->id, 'quantity' => 2]], 'amount_paid' => 150, 'payment_method' => 'cash'])->assertCreated();

        $draft = $this->get(route('pos.index'))->viewData('props')['draft'];
        $this->assertSame([], $draft['cart']);
        $this->assertCount(1, $draft['held']);
    }

    public function test_it_needs_a_login_and_a_proper_order(): void
    {
        $this->putJson(route('pos.draft'), $this->order())->assertUnauthorized();
        $this->actingAs($this->cashier)->putJson(route('pos.draft'), ['cart' => 'x', 'held' => [], 'savedAt' => 1])->assertUnprocessable();
    }
}
