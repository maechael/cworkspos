<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phones and tablets: the menu slides in, the small top bar has the tools, and the receipt fits a phone.
 * How the pages look at each width was checked in a browser; this keeps the pieces from going missing.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/ResponsiveLayoutTest.php
 */
class ResponsiveLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }
        $this->artisan('migrate');
    }

    public function test_the_owner_gets_a_slide_in_menu_and_the_tools_on_a_small_screen(): void
    {
        $admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
            ->assertSee('id="app-menu"', false)
            ->assertSee('data-drawer-open', false)
            ->assertSee('data-drawer-close', false)
            ->assertSee('data-autofocus', false) // the phone search
            ->assertSee('aria-label="Create"', false);
    }

    public function test_a_cashier_gets_the_same_menu_without_the_owner_tools(): void
    {
        $cashier = User::create(['name' => 'Liza Reyes', 'username' => 'liza', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->get(route('sales.index'))->assertOk()
            ->assertSee('data-drawer-open', false)
            ->assertSee('pos-mobile-bar', false)
            ->assertDontSee('aria-label="Create"', false);
    }

    public function test_the_receipt_fits_a_phone_screen(): void
    {
        $admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $sale = DB::table('sales')->insertGetId(['receipt_no' => 'OR-261111-00001', 'user_id' => $admin->id, 'subtotal' => 10, 'discount' => 0, 'total' => 10,
            'amount_paid' => 10, 'change' => 0, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get(route('sales.receipt', $sale))->assertOk()
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false);
    }
}
