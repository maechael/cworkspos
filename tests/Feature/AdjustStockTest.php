<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/AdjustStockTest.php
 */
class AdjustStockTest extends TestCase
{
    private User $admin;

    private Product $coffee;

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
    }

    private function receive(float $qty, ?string $expiry): void
    {
        DB::transaction(function () use ($qty, $expiry) {
            $delivery = Delivery::create(['received_at' => now()->toDateString(), 'user_id' => $this->admin->id]);
            app(Inventory::class)->receive($delivery, $this->coffee->fresh(), ['quantity' => $qty, 'unit_cost' => 10, 'expiry_date' => $expiry], $this->admin->id);
        });
    }

    private function adjust(array $data)
    {
        return $this->post(route('products.adjust-stock', $this->coffee), $data);
    }

    private function lots(): array
    {
        return DB::table('delivery_items')->orderBy('expiry_date')->pluck('remaining_qty')->map(fn ($q) => (float) $q)->all();
    }

    public function test_lowering_stock_records_why_and_takes_the_soonest_expiry_first(): void
    {
        $this->receive(10, '2027-06-01');
        $this->receive(10, '2027-01-01');

        $this->adjust(['counted' => 7, 'reason' => 'Damaged', 'note' => '13 sachets got wet'])
            ->assertRedirect(route('products.show', $this->coffee))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Stock adjusted by -13 pc.');

        $this->assertEquals(7, $this->coffee->fresh()->stock);
        $this->assertSame([0.0, 7.0], $this->lots());
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->coffee->id, 'type' => 'adjust', 'quantity' => -13, 'balance_after' => 7,
            'reference' => 'Damaged: 13 sachets got wet', 'user_id' => $this->admin->id,
        ]);
    }

    public function test_raising_stock_goes_back_into_lots_that_have_room(): void
    {
        $this->receive(10, '2027-01-01');
        $this->adjust(['counted' => 4, 'reason' => 'Wrong count']);

        $this->adjust(['counted' => 12, 'reason' => 'Wrong count'])->assertSessionHasNoErrors();

        $this->assertEquals(12, $this->coffee->fresh()->stock);
        $this->assertSame([10.0], $this->lots()); // the 2 extra have no lot to go back to
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjust', 'quantity' => 8, 'balance_after' => 12, 'reference' => 'Wrong count']);
    }

    public function test_negative_stock_can_be_corrected(): void
    {
        $this->coffee->update(['stock' => -441]);

        $this->adjust(['counted' => 50, 'reason' => 'Wrong count'])->assertSessionHasNoErrors();

        $this->assertEquals(50, $this->coffee->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjust', 'quantity' => 491, 'balance_after' => 50]);
    }

    public function test_the_same_number_changes_nothing(): void
    {
        $this->receive(10, null);

        $this->adjust(['counted' => 10, 'reason' => 'Wrong count'])->assertSessionHas('success', 'No change. 3 in 1 Coffee already had that stock on record.');

        $this->assertDatabaseMissing('stock_movements', ['type' => 'adjust']);
    }

    public function test_bad_input_changes_nothing(): void
    {
        $this->receive(10, null);

        $this->adjust(['counted' => 5])->assertSessionHasErrors('reason');
        $this->adjust(['counted' => 5, 'reason' => 'Because'])->assertSessionHasErrors('reason');
        $this->adjust(['reason' => 'Damaged'])->assertSessionHasErrors('counted');
        $this->adjust(['counted' => -1, 'reason' => 'Damaged'])->assertSessionHasErrors('counted');
        $this->adjust(['counted' => 5, 'reason' => 'Other'])->assertSessionHasErrors('note');
        $this->adjust(['counted' => 2.5, 'reason' => 'Damaged'])->assertSessionHasErrors('items');

        $this->assertEquals(10, $this->coffee->fresh()->stock);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'adjust']);
    }

    public function test_other_works_with_a_note_and_kilo_items_can_use_fractions(): void
    {
        $this->adjust(['counted' => 5, 'reason' => 'Other', 'note' => 'Given to the barangay'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_movements', ['reference' => 'Other: Given to the barangay']);

        $kilo = Unit::firstOrCreate(['name' => 'Kilo'], ['abbreviation' => 'kg', 'allows_decimal' => true]);
        $this->coffee->update(['unit_id' => $kilo->id]);
        $this->adjust(['counted' => 2.5, 'reason' => 'Wrong count'])->assertSessionHasNoErrors();
        $this->assertEquals(2.5, $this->coffee->fresh()->stock);
    }

    public function test_a_cashier_cant_adjust_stock(): void
    {
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->post(route('products.adjust-stock', $this->coffee), ['counted' => 500, 'reason' => 'Wrong count'])->assertForbidden();

        $this->assertEquals(0, $this->coffee->fresh()->stock);
    }

    public function test_the_change_shows_on_the_product_and_in_the_report(): void
    {
        $this->receive(10, null);
        $this->adjust(['counted' => 7, 'reason' => 'Expired', 'note' => 'Past date']);

        $this->get(route('products.show', $this->coffee))->assertOk()
            ->assertSee('Adjust stock')->assertSee('Expired: Past date')->assertSee('by Owner')->assertSee('Adjustment');
        $this->get(route('reports.movements', ['type' => 'adjust']))->assertOk()->assertSee('Expired: Past date')->assertSee('Stock adjustment');
    }
}
