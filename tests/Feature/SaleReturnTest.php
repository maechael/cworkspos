<?php

namespace Tests\Feature;

use App\Models\Basket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A shopper brings items back: the sale is edited to what they kept, the refund is right, and stock follows.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/SaleReturnTest.php
 */
class SaleReturnTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    private Unit $kilo;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        Carbon::setTestNow('2026-11-15 10:00:00');
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

    private function sell(array $items, float $discount = 0, float $paid = 10000): Sale
    {
        $id = $this->postJson(route('sales.store'), ['items' => $items, 'discount' => $discount, 'amount_paid' => $paid, 'payment_method' => 'cash'])
            ->assertCreated()->json('id');

        return Sale::with('items')->find($id);
    }

    private function line(Sale $sale, Product $product): SaleItem
    {
        return $sale->items()->where('product_id', $product->id)->first();
    }

    private function giveBack(Sale $sale, array $lines, string $reason = 'Changed their mind', array $extra = [])
    {
        return $this->post(route('sales.return.store', $sale), $extra + ['lines' => $lines, 'reason' => $reason, 'refund_method' => 'cash']);
    }

    /** What was paid always equals what was kept plus what was given back. */
    private function assertMoneyAddsUp(Sale $sale): void
    {
        $sale->refresh();
        $this->assertEqualsWithDelta((float) $sale->amount_paid - (float) $sale->change, (float) $sale->total + (float) $sale->refunded, 0.001);
        $this->assertEqualsWithDelta((float) $sale->subtotal - (float) $sale->discount, (float) $sale->total, 0.001);
        $this->assertEqualsWithDelta((float) $sale->items()->sum('line_total'), (float) $sale->subtotal, 0.001);
    }

    public function test_returning_some_of_an_item_edits_the_sale_refunds_and_puts_it_back_on_the_shelf(): void
    {
        $sardines = $this->product('Sardines', 24);
        $cola = $this->product('Cola', 75);
        $sale = $this->sell([['product_id' => $sardines->id, 'quantity' => 3], ['product_id' => $cola->id, 'quantity' => 1]], 0, 200);
        $item = $this->line($sale, $sardines);

        $this->giveBack($sale, ['item-'.$item->id => ['qty' => 2, 'restock' => 1]])
            ->assertRedirect(route('sales.show', $sale))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '₱48.00'));

        $sale->refresh();
        $this->assertEquals(99, (float) $sale->total);
        $this->assertEquals(48, (float) $sale->refunded);
        $item->refresh();
        $this->assertEquals(1, (float) $item->quantity);
        $this->assertEquals(2, (float) $item->returned_qty);
        $this->assertEquals(24, (float) $item->line_total);
        $this->assertEquals(99, $sardines->fresh()->stock); // 100 - 3 sold + 2 back
        $this->assertDatabaseHas('stock_movements', ['product_id' => $sardines->id, 'type' => 'return', 'quantity' => 2, 'reference' => 'RT-261115-00001']);
        $this->assertDatabaseHas('sale_returns', ['sale_id' => $sale->id, 'return_no' => 'RT-261115-00001', 'refund' => 48, 'reason' => 'Changed their mind', 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('sale_return_items', ['product_id' => $sardines->id, 'quantity' => 2, 'amount' => 48, 'restocked' => true]);
        $this->assertMoneyAddsUp($sale);

        // The sales report now counts only the one tin kept
        $this->assertEquals(24 + 75, (float) DB::table('sale_items')->sum('line_total'));
    }

    public function test_items_not_put_back_stay_out_of_stock(): void
    {
        $eggs = $this->product('Eggs', 9);
        $sale = $this->sell([['product_id' => $eggs->id, 'quantity' => 6]]);

        $this->giveBack($sale, ['item-'.$this->line($sale, $eggs)->id => ['qty' => 2, 'restock' => 0]], 'Damaged or defective')->assertSessionHasNoErrors();

        $this->assertEquals(94, $eggs->fresh()->stock);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'return']);
        $this->assertDatabaseHas('sale_return_items', ['product_id' => $eggs->id, 'restocked' => false]);
        $this->assertEquals(18, (float) $sale->fresh()->refunded);
    }

    public function test_the_sale_discount_is_shared_out_in_the_refund(): void
    {
        $a = $this->product('Rice Bag', 100);
        $b = $this->product('Oil', 100);
        $sale = $this->sell([['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]], 20, 200);

        $this->giveBack($sale, ['item-'.$this->line($sale, $a)->id => ['qty' => 1, 'restock' => 1]])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertEquals(90, (float) $sale->refunded); // 100 of a 200 subtotal, with half the 20 discount
        $this->assertEquals(90, (float) $sale->total);
        $this->assertEquals(10, (float) $sale->discount);
        $this->assertMoneyAddsUp($sale);
    }

    public function test_free_promo_items_come_back_first(): void
    {
        $coffee = $this->product('Coffee', 15, ['promo_buy' => 1, 'promo_take' => 1]);
        $sale = $this->sell([['product_id' => $coffee->id, 'quantity' => 2]]);
        $key = 'item-'.$this->line($sale, $coffee)->id;

        $this->giveBack($sale, [$key => ['qty' => 1, 'restock' => 1]])->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) $sale->fresh()->refunded); // they keep the one they paid for
        $this->assertEquals(0, (float) $this->line($sale, $coffee)->free_qty);

        $this->giveBack($sale, [$key => ['qty' => 1, 'restock' => 1]])->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertEquals(15, (float) $sale->refunded);
        $this->assertEquals(0, (float) $sale->total);
        $this->assertEquals(100, $coffee->fresh()->stock);
        $this->assertMoneyAddsUp($sale);
    }

    public function test_a_basket_comes_back_by_the_whole_basket(): void
    {
        $noodles = $this->product('Noodles', 18);
        $sauce = $this->product('Soy Sauce', 58);
        $basket = Basket::create(['barcode' => '2000000000017', 'name' => 'Gift Pack', 'price' => 100, 'is_active' => true]);
        $basket->items()->create(['product_id' => $noodles->id, 'quantity' => 2]);
        $basket->items()->create(['product_id' => $sauce->id, 'quantity' => 1]);
        $sale = $this->sell([['basket_id' => $basket->id, 'quantity' => 2]]);
        $key = 'basket-'.$sale->items->first()->id;

        $this->giveBack($sale, [$key => ['qty' => 0.5, 'restock' => 1]])->assertSessionHasErrors('lines');

        $this->giveBack($sale, [$key => ['qty' => 1, 'restock' => 1]])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertEquals(100, (float) $sale->refunded);
        $this->assertEquals(100, (float) $sale->total);
        $this->assertEquals(2, (float) $this->line($sale, $noodles)->quantity);
        $this->assertEquals(1, (float) $this->line($sale, $noodles)->basket_qty);
        $this->assertEquals(98, $noodles->fresh()->stock); // 4 sold, 2 back
        $this->assertEquals(99, $sauce->fresh()->stock);
        $this->assertMoneyAddsUp($sale);
    }

    public function test_kilo_items_can_come_back_by_part_of_a_kilo_but_pieces_cannot(): void
    {
        $rice = $this->product('Rice', 52, ['unit_id' => $this->kilo->id]);
        $soap = $this->product('Soap', 40);
        $sale = $this->sell([['product_id' => $rice->id, 'quantity' => 2], ['product_id' => $soap->id, 'quantity' => 2]]);

        $this->giveBack($sale, ['item-'.$this->line($sale, $soap)->id => ['qty' => 1.5, 'restock' => 1]])->assertSessionHasErrors('items');
        $this->giveBack($sale, ['item-'.$this->line($sale, $rice)->id => ['qty' => 0.5, 'restock' => 1]])->assertSessionHasNoErrors();

        $this->assertEquals(26, (float) $sale->fresh()->refunded);
        $this->assertEquals(98.5, $rice->fresh()->stock); // 2 kg sold, half a kilo back
    }

    public function test_more_than_the_shopper_has_or_nothing_at_all_is_refused(): void
    {
        $cola = $this->product('Cola', 75);
        $sale = $this->sell([['product_id' => $cola->id, 'quantity' => 2]]);
        $key = 'item-'.$this->line($sale, $cola)->id;

        $this->giveBack($sale, [$key => ['qty' => 3, 'restock' => 1]])->assertSessionHasErrors('lines');
        $this->giveBack($sale, [$key => ['qty' => 0, 'restock' => 1]])->assertSessionHasErrors('lines');
        $this->giveBack($sale, [$key => ['qty' => 1, 'restock' => 1]], 'Other')->assertSessionHasErrors('note');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertEquals(98, $cola->fresh()->stock);
    }

    public function test_returning_everything_refunds_the_whole_total_and_closes_the_return_form(): void
    {
        $a = $this->product('Bread', 35);
        $b = $this->product('Milk', 33);
        $sale = $this->sell([['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]], 7, 100);

        $this->giveBack($sale, [
            'item-'.$this->line($sale, $a)->id => ['qty' => 1, 'restock' => 1],
            'item-'.$this->line($sale, $b)->id => ['qty' => 1, 'restock' => 1],
        ])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertEquals(61, (float) $sale->refunded);
        $this->assertEquals(0, (float) $sale->total);
        $this->assertMoneyAddsUp($sale);
        $this->get(route('sales.return', $sale))->assertRedirect(route('sales.show', $sale));
    }

    public function test_voiding_after_a_return_only_puts_back_what_the_shopper_kept(): void
    {
        $cola = $this->product('Cola', 75);
        $sale = $this->sell([['product_id' => $cola->id, 'quantity' => 3]]);
        $this->giveBack($sale, ['item-'.$this->line($sale, $cola)->id => ['qty' => 1, 'restock' => 1]]);

        $this->post(route('sales.void', $sale));

        $this->assertEquals(100, $cola->fresh()->stock);
        $this->giveBack($sale, ['item-'.$this->line($sale, $cola)->id => ['qty' => 1, 'restock' => 1]])->assertSessionHasErrors('lines');
    }

    public function test_a_cashier_cannot_return_items_without_the_owners_approval(): void
    {
        $cola = $this->product('Cola', 75);
        $cashier = User::create(['name' => 'Liza', 'username' => 'liza', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
        $this->actingAs($cashier);
        $sale = $this->sell([['product_id' => $cola->id, 'quantity' => 1]]);

        $this->get(route('sales.return', $sale))->assertOk()->assertSee("Send to the owner's phone", false);
        $this->giveBack($sale, ['item-'.$this->line($sale, $cola)->id => ['qty' => 1, 'restock' => 1]])->assertSessionHasErrors('approval_via');
        $this->giveBack($sale, ['item-'.$this->line($sale, $cola)->id => ['qty' => 1, 'restock' => 1]], 'Changed their mind', ['approval_via' => 'here'])
            ->assertSessionHasErrors('credential');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertEquals(99, $cola->fresh()->stock);
    }

    public function test_the_sale_page_the_receipt_and_the_sales_list_show_the_return(): void
    {
        $sardines = $this->product('Sardines', 24);
        $cola = $this->product('Cola', 75);
        $sale = $this->sell([['product_id' => $sardines->id, 'quantity' => 2], ['product_id' => $cola->id, 'quantity' => 1]]);

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Return items');
        $this->get(route('sales.return', $sale))->assertOk()->assertSee('Coming back')->assertSee('Sardines');

        $this->giveBack($sale, ['item-'.$this->line($sale, $cola)->id => ['qty' => 1, 'restock' => 1]]);

        $this->get(route('sales.show', $sale))->assertOk()
            ->assertSee('RT-261115-00001')->assertSee('All returned')->assertSee('Refunded')->assertSee('back on the shelf');
        $this->get(route('sales.receipt', $sale))->assertOk()
            ->assertSee('RETURNED')->assertSee('Refunded')->assertSee('1 item(s)');
        $this->getJson(route('sales.index'))->assertOk()->assertJsonPath('data.0.returned', true);
    }
}
