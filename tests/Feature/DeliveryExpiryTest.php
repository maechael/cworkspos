<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/DeliveryExpiryTest.php
 */
class DeliveryExpiryTest extends TestCase
{
    private User $admin;

    private Product $coffee;

    private Delivery $delivery;

    private DeliveryItem $line;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->coffee = Product::create(['barcode' => '4800000000017', 'name' => '3 in 1 Coffee', 'unit_id' => $piece->id, 'cost_price' => 10, 'selling_price' => 12, 'stock' => 0, 'is_active' => true]);
        $this->actingAs($this->admin);

        [$this->delivery, $this->line] = $this->received($this->coffee, 60);
    }

    private function received(Product $product, float $qty): array
    {
        return DB::transaction(function () use ($product, $qty) {
            $delivery = Delivery::create(['received_at' => now()->toDateString(), 'user_id' => $this->admin->id, 'total_cost' => $qty * 10]);
            app(Inventory::class)->receive($delivery, $product->fresh(), ['quantity' => $qty, 'unit_cost' => 10, 'expiry_date' => null], $this->admin->id);

            return [$delivery, $delivery->items()->first()];
        });
    }

    private function split(array $data)
    {
        return $this->post(route('deliveries.items.split', [$this->delivery, $this->line]), $data);
    }

    public function test_a_date_can_be_added_changed_and_cleared_after_saving(): void
    {
        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => '2027-03-01']])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved 1 expiry date.');
        $this->assertSame('2027-03-01', $this->line->fresh()->expiry_date->format('Y-m-d'));

        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => '2027-03-01']])->assertSessionHas('success', 'No dates changed.');

        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => '']])->assertSessionHasNoErrors();
        $this->assertNull($this->line->fresh()->expiry_date);
    }

    public function test_adding_a_date_changes_no_stock_and_turns_on_the_warning(): void
    {
        $this->assertSame(0, DeliveryItem::expiringSoon()->count());
        $movements = DB::table('stock_movements')->count();

        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => now()->addDays(10)->toDateString()]]);

        $this->assertEquals(60, $this->coffee->fresh()->stock);
        $this->assertEquals(60, $this->line->fresh()->remaining_qty);
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame(1, DeliveryItem::expiringSoon()->count());
    }

    public function test_a_line_from_another_delivery_cant_be_changed_through_this_one(): void
    {
        $other = Product::create(['barcode' => '4800000000024', 'name' => 'Tea', 'unit_id' => $this->coffee->unit_id, 'cost_price' => 10, 'selling_price' => 12, 'stock' => 0, 'is_active' => true]);
        [, $otherLine] = $this->received($other, 5);

        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$otherLine->id => '2027-03-01']])->assertSessionHas('success', 'No dates changed.');
        $this->assertNull($otherLine->fresh()->expiry_date);

        $this->post(route('deliveries.items.split', [$this->delivery, $otherLine]), ['quantity' => 1, 'expiry_date' => '2027-03-01'])->assertNotFound();
    }

    public function test_one_box_can_get_its_own_date_and_totals_stay_the_same(): void
    {
        $this->split(['quantity' => 30, 'expiry_date' => '2027-01-15'])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Moved 30 pc of 3 in 1 Coffee to its own expiry date.');

        $lines = $this->delivery->items()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(30, $lines[0]->quantity);
        $this->assertEquals(30, $lines[0]->remaining_qty);
        $this->assertNull($lines[0]->expiry_date);
        $this->assertEquals(30, $lines[1]->quantity);
        $this->assertEquals(30, $lines[1]->remaining_qty);
        $this->assertSame('2027-01-15', $lines[1]->expiry_date->format('Y-m-d'));
        $this->assertEquals(10, $lines[1]->unit_cost);

        $this->assertEquals(60, $this->coffee->fresh()->stock);
        $this->assertEquals(600, $lines->sum(fn ($l) => $l->quantity * $l->unit_cost));
    }

    public function test_sales_take_the_box_that_expires_first(): void
    {
        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => '2027-06-01']]);
        $this->split(['quantity' => 30, 'expiry_date' => '2027-01-15']);

        $this->postJson('/pos/sales', ['items' => [['product_id' => $this->coffee->id, 'quantity' => 5]], 'amount_paid' => 60, 'payment_method' => 'cash'])->assertCreated();

        $this->assertEquals(25, $this->delivery->items()->whereDate('expiry_date', '2027-01-15')->value('remaining_qty'));
        $this->assertEquals(30, $this->delivery->items()->whereDate('expiry_date', '2027-06-01')->value('remaining_qty'));
    }

    public function test_only_pieces_still_on_the_shelf_can_be_moved(): void
    {
        $this->line->update(['remaining_qty' => 10]);

        $this->split(['quantity' => 11, 'expiry_date' => '2027-01-15'])->assertSessionHasErrors('quantity');

        $this->split(['quantity' => 10, 'expiry_date' => '2027-01-15'])->assertSessionHasNoErrors();
        $fresh = $this->line->fresh();
        $this->assertEquals(50, $fresh->quantity); // the 50 already sold stay on this line
        $this->assertEquals(0, $fresh->remaining_qty);
    }

    public function test_bad_splits_change_nothing(): void
    {
        $this->split(['quantity' => 60, 'expiry_date' => '2027-01-15'])->assertSessionHasErrors('quantity');
        $this->split(['quantity' => 2.5, 'expiry_date' => '2027-01-15'])->assertSessionHasErrors('items');
        $this->split(['quantity' => 0, 'expiry_date' => '2027-01-15'])->assertSessionHasErrors('quantity');
        $this->split(['quantity' => 5])->assertSessionHasErrors('expiry_date');
        $this->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => 'soon']])->assertSessionHasErrors();

        $this->assertSame(1, $this->delivery->items()->count());
        $this->assertEquals(60, $this->line->fresh()->quantity);
    }

    public function test_a_cashier_cant_change_dates(): void
    {
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->put(route('deliveries.expiry', $this->delivery), ['expiry' => [$this->line->id => '2027-03-01']])->assertForbidden();
        $this->split(['quantity' => 5, 'expiry_date' => '2027-03-01'])->assertForbidden();
        $this->assertNull($this->line->fresh()->expiry_date);
    }

    public function test_the_page_offers_dates_only_for_what_is_on_the_shelf(): void
    {
        $this->get(route('deliveries.show', $this->delivery))->assertOk()
            ->assertSee('Save dates')->assertSee('No date yet')->assertSee('Some have another date')->assertSee('1 line still needs a date');

        $this->line->update(['remaining_qty' => 0]);
        $this->get(route('deliveries.show', $this->delivery))->assertOk()
            ->assertDontSee('Save dates')->assertSee('all sold');
    }
}
