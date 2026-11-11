<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/BatchAddTest.php
 */
class BatchAddTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.test', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
    }

    private function existingProduct(): Product
    {
        return Product::create(['barcode' => '4800000000017', 'name' => 'Sardines', 'unit_id' => $this->piece->id, 'cost_price' => 15, 'selling_price' => 20, 'stock' => 5, 'is_active' => true]);
    }

    public function test_it_adds_stock_and_creates_new_products_in_one_delivery(): void
    {
        $existing = $this->existingProduct();
        $category = Category::create(['name' => 'Canned goods']);

        $response = $this->actingAs($this->admin)->postJson('/products/batch', ['items' => [
            ['product_id' => $existing->id, 'quantity' => 3, 'unit_cost' => 16, 'selling_price' => 22, 'expiry_date' => '2027-06-01'],
            ['barcode' => '4800000000024', 'name' => 'Corned Beef', 'category_id' => $category->id, 'unit_id' => $this->piece->id, 'quantity' => 4, 'unit_cost' => 30, 'selling_price' => 40],
            ['barcode' => null, 'name' => 'Loose Eggs', 'unit_id' => $this->piece->id, 'quantity' => 2, 'unit_cost' => 8, 'selling_price' => 10],
        ]]);

        $response->assertCreated();
        $this->assertSame(1, Delivery::count());
        $delivery = Delivery::with('items')->first();
        $this->assertCount(3, $delivery->items);
        $this->assertEquals(184, $delivery->total_cost);

        $existing->refresh();
        $this->assertEquals(8, $existing->stock);
        $this->assertEquals(16, $existing->cost_price);
        $this->assertEquals(22, $existing->selling_price);

        $beef = Product::where('barcode', '4800000000024')->first();
        $this->assertEquals(4, $beef->stock);
        $this->assertEquals(40, $beef->selling_price);
        $this->assertSame($category->id, $beef->category_id);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $beef->id, 'type' => 'delivery', 'quantity' => 4]);

        // No printed barcode: the system makes an in-store one
        $this->assertMatchesRegularExpression('/^20\d{11}$/', Product::where('name', 'Loose Eggs')->first()->barcode);
    }

    public function test_a_barcode_already_in_use_saves_nothing(): void
    {
        $existing = $this->existingProduct();
        $existing->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->postJson('/products/batch', ['items' => [
            ['barcode' => '4800000000031', 'name' => 'Good Row', 'unit_id' => $this->piece->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 2],
            ['barcode' => $existing->barcode, 'name' => 'Duplicate', 'unit_id' => $this->piece->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 2],
        ]]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('already belongs to another product', $response->json('message'));
        $this->assertSame(0, Delivery::count());
        $this->assertFalse(Product::where('name', 'Good Row')->exists());
    }

    public function test_a_failure_partway_through_rolls_everything_back(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/products/batch', ['items' => [
            ['barcode' => '4800000000048', 'name' => 'Saved First', 'unit_id' => $this->piece->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 2],
            ['barcode' => '4800000000055', 'name' => 'Half a Piece', 'unit_id' => $this->piece->id, 'quantity' => 1.5, 'unit_cost' => 1, 'selling_price' => 2],
        ]]);

        $response->assertUnprocessable();
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Delivery::count());
    }

    public function test_a_new_product_needs_a_name_and_a_cashier_cannot_use_this(): void
    {
        $this->actingAs($this->admin)->postJson('/products/batch', ['items' => [
            ['barcode' => '4800000000062', 'unit_id' => $this->piece->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.name');

        $cashier = User::create(['name' => 'Cashier', 'username' => 'cash', 'email' => 'cash@example.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
        $this->actingAs($cashier)->postJson('/products/batch', ['items' => []])->assertForbidden();
        $this->actingAs($cashier)->get('/products/batch')->assertForbidden();
    }
}
