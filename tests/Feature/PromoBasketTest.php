<?php

namespace Tests\Feature;

use App\Models\Basket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/PromoBasketTest.php
 */
class PromoBasketTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    private Unit $kilo;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->kilo = Unit::firstOrCreate(['name' => 'Kilo'], ['abbreviation' => 'kg', 'allows_decimal' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, array $extra = []): Product
    {
        return Product::create($extra + [
            'barcode' => '48'.str_pad((string) crc32($name), 11, '0'), 'name' => $name, 'unit_id' => $this->piece->id,
            'cost_price' => round($price * 0.7, 2), 'selling_price' => $price, 'stock' => 100, 'is_active' => true,
        ]);
    }

    private function basket(string $name, float $price, array $items, array $extra = []): Basket
    {
        $basket = Basket::create($extra + ['barcode' => '20'.str_pad((string) crc32($name), 11, '0'), 'name' => $name, 'price' => $price, 'is_active' => true]);
        foreach ($items as [$product, $qty]) {
            $basket->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
        }

        return $basket;
    }

    private function sell(array $items, float $paid = 10000)
    {
        return $this->postJson(route('sales.store'), ['items' => $items, 'amount_paid' => $paid, 'payment_method' => 'cash']);
    }

    public function test_buy_1_take_1_charges_for_half(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1]);

        $this->sell([['product_id' => $coffee->id, 'quantity' => 3]])->assertCreated()->assertJson(['total' => 30]);

        $line = DB::table('sale_items')->first();
        $this->assertEquals(3, $line->quantity);
        $this->assertEquals(1, $line->free_qty);
        $this->assertEquals(30, $line->line_total);
        $this->assertSame('Buy 1 Take 1', $line->promo);
        $this->assertEquals(97, $coffee->fresh()->stock); // every piece leaves the shelf, free or not
    }

    public function test_buy_2_take_1(): void
    {
        $soap = $this->product('Soap', 20, ['promo_buy' => 2, 'promo_take' => 1]);

        $this->sell([['product_id' => $soap->id, 'quantity' => 6]])->assertCreated()->assertJson(['total' => 80]);
        $this->sell([['product_id' => $soap->id, 'quantity' => 2]])->assertCreated()->assertJson(['total' => 40]);
    }

    public function test_a_promo_runs_through_its_last_day_then_stops(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1, 'promo_until' => '2026-11-11']);
        $this->sell([['product_id' => $coffee->id, 'quantity' => 2]])->assertJson(['total' => 15]);

        Carbon::setTestNow('2026-11-12 08:00:00');
        $this->sell([['product_id' => $coffee->id, 'quantity' => 2]])->assertJson(['total' => 30]);
        $this->assertNull(DB::table('sale_items')->latest('id')->first()->promo);
    }

    public function test_the_product_form_saves_and_clears_a_promo(): void
    {
        $coffee = $this->product('Coffee', 15);
        $form = ['name' => 'Coffee', 'barcode' => $coffee->barcode, 'unit_id' => $this->piece->id, 'cost_price' => 10, 'selling_price' => 15, 'reorder_level' => 5, 'is_active' => 1];

        $this->put(route('products.update', $coffee), $form + ['promo_buy' => 1, 'promo_take' => 1, 'promo_until' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->assertTrue($coffee->fresh()->promoActive());
        $this->get(route('products.index'), ['Accept' => 'application/json'])->assertJsonPath('data.0.promo', 'Buy 1 Take 1');

        $this->put(route('products.update', $coffee), $form + ['promo_buy' => '', 'promo_take' => '', 'promo_until' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->assertNull($coffee->fresh()->promo_buy);
        $this->assertNull($coffee->fresh()->promo_until);

        $this->put(route('products.update', $coffee), $form + ['promo_buy' => 1])->assertSessionHasErrors('promo_take');
    }

    public function test_items_sold_by_weight_cant_have_a_promo(): void
    {
        $rice = $this->product('Rice', 50, ['unit_id' => $this->kilo->id]);
        $form = ['name' => 'Rice', 'barcode' => $rice->barcode, 'unit_id' => $this->kilo->id, 'cost_price' => 40, 'selling_price' => 50, 'reorder_level' => 5, 'is_active' => 1];

        $this->put(route('products.update', $rice), $form + ['promo_buy' => 1, 'promo_take' => 1])->assertSessionHasErrors('promo_take');

        // Even if one got in some other way, it doesn't apply
        $rice->update(['promo_buy' => 1, 'promo_take' => 1]);
        $this->sell([['product_id' => $rice->id, 'quantity' => 2]])->assertJson(['total' => 100]);
    }

    public function test_a_basket_takes_each_item_out_of_stock_at_the_basket_price(): void
    {
        $coffee = $this->product('Coffee', 15);
        $sugar = $this->product('Sugar', 50);
        $basket = $this->basket('Gift basket', 70, [[$coffee, 2], [$sugar, 1]]);

        $this->sell([['basket_id' => $basket->id, 'quantity' => 2]])->assertCreated()->assertJson(['total' => 140]);

        $lines = DB::table('sale_items')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertEquals([4, 2], $lines->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEquals(140, $lines->sum('line_total'));
        $this->assertEquals(['Gift basket'], $lines->pluck('basket_name')->unique()->values()->all());
        // Shared by regular price: coffee 60 of 160, sugar 100 of 160
        $this->assertEquals(52.5, $lines[0]->line_total);
        $this->assertEquals(87.5, $lines[1]->line_total);
        $this->assertEquals(96, $coffee->fresh()->stock);
        $this->assertEquals(98, $sugar->fresh()->stock);

        // Voiding puts every item back
        $sale = Sale::first();
        $this->post(route('sales.void', $sale))->assertRedirect();
        $this->assertEquals(100, $coffee->fresh()->stock);
        $this->assertEquals(100, $sugar->fresh()->stock);
    }

    public function test_basket_shares_always_add_up_to_its_price(): void
    {
        $a = $this->product('A', 10);
        $b = $this->product('B', 10);
        $c = $this->product('C', 10);
        $basket = $this->basket('Trio', 100, [[$a, 1], [$b, 1], [$c, 1]]);

        $this->sell([['basket_id' => $basket->id, 'quantity' => 1]])->assertJson(['total' => 100]);

        $this->assertEquals([33.33, 33.33, 33.34], DB::table('sale_items')->orderBy('id')->pluck('line_total')->map(fn ($t) => (float) $t)->all());
    }

    public function test_a_basket_and_a_promo_item_in_one_sale(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1]);
        $sugar = $this->product('Sugar', 50);
        $basket = $this->basket('Gift basket', 70, [[$coffee, 2], [$sugar, 1]]);

        // The coffee inside the basket is at the basket price, not on top of the promo
        $this->sell([['basket_id' => $basket->id, 'quantity' => 1], ['product_id' => $coffee->id, 'quantity' => 2]])->assertJson(['total' => 85]);
    }

    public function test_a_turned_off_basket_or_part_of_one_cant_be_sold(): void
    {
        $coffee = $this->product('Coffee', 15);
        $off = $this->basket('Old basket', 50, [[$coffee, 1]], ['is_active' => false]);
        $on = $this->basket('Basket', 50, [[$coffee, 1]]);

        $this->sell([['basket_id' => $off->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->sell([['basket_id' => $on->id, 'quantity' => 1.5]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->sell([['quantity' => 1]])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_the_cashier_finds_baskets_but_receiving_doesnt(): void
    {
        $coffee = $this->product('Coffee', 15);
        $sugar = $this->product('Sugar', 50, ['stock' => 3]);
        $basket = $this->basket('Gift basket', 70, [[$coffee, 2], [$sugar, 1]]);

        $this->getJson(route('products.lookup', ['barcode' => $basket->barcode]))->assertNotFound();
        $this->getJson(route('products.lookup', ['barcode' => $basket->barcode, 'baskets' => 1]))->assertOk()
            ->assertJson(['id' => 'basket-'.$basket->id, 'basket_id' => $basket->id, 'basket' => true, 'price' => 70, 'regular' => 80, 'stock' => 3]);

        $catalog = collect($this->getJson(route('products.catalog'))->assertOk()->json());
        $this->assertSame('Baskets', $catalog->firstWhere('id', 'basket-'.$basket->id)['category']);
        $this->assertCount(3, $catalog);
    }

    public function test_a_promo_shows_on_the_cashier_screen(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1]);

        $this->getJson(route('products.lookup', ['barcode' => $coffee->barcode]))->assertJsonPath('promo', ['buy' => 1, 'take' => 1, 'label' => 'Buy 1 Take 1']);
    }

    public function test_barcodes_cant_be_shared_between_a_product_and_a_basket(): void
    {
        $coffee = $this->product('Coffee', 15);
        $basket = $this->basket('Gift basket', 70, [[$coffee, 1]]);

        $this->post(route('baskets.store'), ['name' => 'Another', 'barcode' => $coffee->barcode, 'price' => 10, 'is_active' => 1, 'items' => [['product_id' => $coffee->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('barcode');
        $this->post(route('products.store'), ['name' => 'Tea', 'barcode' => $basket->barcode, 'unit_id' => $this->piece->id, 'cost_price' => 1, 'selling_price' => 2, 'reorder_level' => 0, 'is_active' => 1])
            ->assertSessionHasErrors('barcode');
    }

    public function test_the_owner_sets_up_a_basket(): void
    {
        $coffee = $this->product('Coffee', 15);
        $sugar = $this->product('Sugar', 50);

        $this->get(route('baskets.create'))->assertOk()->assertSee('Basket name')->assertSee('data-react="BasketItems"', false);
        $this->post(route('baskets.store'), ['name' => 'Gift basket', 'barcode' => '', 'price' => 70, 'is_active' => 1, 'items' => [
            ['product_id' => $coffee->id, 'quantity' => 2], ['product_id' => $sugar->id, 'quantity' => 1], ['product_id' => '', 'quantity' => 1],
        ]])->assertRedirect(route('baskets.index'))->assertSessionHasNoErrors();

        $basket = Basket::with('items')->first();
        $this->assertStringStartsWith('20', $basket->barcode); // an in-store code to print
        $this->assertCount(2, $basket->items);
        $this->get(route('baskets.index'))->assertOk()->assertSee('Gift basket')->assertSee('Saves ₱10.00');
        $this->get(route('baskets.edit', $basket))->assertOk()->assertSee('Gift basket');
        $this->get(route('products.labels', ['basket_ids' => [$basket->id]]))->assertOk()->assertSee($basket->barcode);

        $this->put(route('baskets.update', $basket), ['name' => 'Gift basket', 'barcode' => $basket->barcode, 'price' => 75, 'is_active' => 1, 'items' => [['product_id' => $sugar->id, 'quantity' => 2]]])
            ->assertSessionHasNoErrors();
        $this->assertEquals(75, $basket->fresh()->price);
        $this->assertEquals([$sugar->id], $basket->fresh()->items->pluck('product_id')->all());

        $this->post(route('baskets.store'), ['name' => 'Empty', 'price' => 5, 'items' => []])->assertSessionHasErrors('items');
        $this->post(route('baskets.store'), ['name' => 'Twice', 'price' => 5, 'items' => [['product_id' => $sugar->id, 'quantity' => 1], ['product_id' => $sugar->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items.1.product_id');
        $this->post(route('baskets.store'), ['name' => 'Half', 'price' => 5, 'items' => [['product_id' => $sugar->id, 'quantity' => 1.5]]])->assertSessionHasErrors('items.0.quantity');
    }

    public function test_a_sold_basket_is_turned_off_instead_of_deleted(): void
    {
        $coffee = $this->product('Coffee', 15);
        $sold = $this->basket('Sold', 20, [[$coffee, 1]]);
        $unsold = $this->basket('Unsold', 20, [[$coffee, 1]]);
        $this->sell([['basket_id' => $sold->id, 'quantity' => 1]])->assertCreated();

        $this->delete(route('baskets.destroy', $sold))->assertRedirect();
        $this->delete(route('baskets.destroy', $unsold))->assertRedirect();

        $this->assertFalse($sold->fresh()->is_active);
        $this->assertNull($unsold->fresh());
    }

    public function test_the_receipt_shows_the_basket_and_the_free_items(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1]);
        $sugar = $this->product('Sugar', 50);
        $basket = $this->basket('Gift basket', 70, [[$sugar, 1]]);
        $id = $this->sell([['basket_id' => $basket->id, 'quantity' => 1], ['product_id' => $coffee->id, 'quantity' => 2]])->json('id');
        $sale = Sale::find($id);

        $this->get(route('sales.receipt', $sale))->assertOk()
            ->assertSeeInOrder(['Gift basket', '1 basket x 70.00', '70.00', '- 1 Sugar'])
            ->assertSeeInOrder(['Coffee', '30.00', 'Buy 1 Take 1 (1 free)', '-15.00'])
            ->assertSee('You saved 15.00 on promos')
            ->assertSee('2 item(s)');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Basket')->assertSee('1 free');
    }

    public function test_voiding_a_basket_line_on_the_cashier_screen_asks_for_approval(): void
    {
        Log::spy();
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
        $coffee = $this->product('Coffee', 15);
        $basket = $this->basket('Gift basket', 70, [[$coffee, 1]]);

        $this->actingAs($cashier)->postJson(route('pos.void-approval'), ['credential' => 'wrong', 'lines' => [['basket_id' => $basket->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonMissingValidationErrors('lines.0.product_id');
        $this->actingAs($cashier)->postJson(route('pos.void-approval'), ['credential' => 'secret123', 'lines' => [['basket_id' => $basket->id, 'quantity' => 1]]])->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['lines'] === ['Basket Gift basket x 1']);
    }

    public function test_a_cashier_cant_set_up_baskets(): void
    {
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->get(route('baskets.index'))->assertForbidden();
    }
}
