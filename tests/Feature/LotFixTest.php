<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fixing one delivery batch: its expiry date, how many are left, or removing all of it.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/LotFixTest.php
 */
class LotFixTest extends TestCase
{
    private User $admin;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->milk = Product::create(['barcode' => '4800000000024', 'name' => "Mama's Milk 1L", 'unit_id' => $piece->id, 'cost_price' => 80, 'selling_price' => 95, 'stock' => 0, 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receive(float $qty, ?string $expiry): DeliveryItem
    {
        DB::transaction(function () use ($qty, $expiry) {
            $delivery = Delivery::create(['received_at' => now()->toDateString(), 'user_id' => $this->admin->id]);
            app(Inventory::class)->receive($delivery, $this->milk->fresh(), ['quantity' => $qty, 'unit_cost' => 80, 'expiry_date' => $expiry], $this->admin->id);
        });

        return DeliveryItem::latest('id')->first();
    }

    public function test_the_expiry_date_can_be_corrected_and_the_history_says_so(): void
    {
        $lot = $this->receive(12, '2026-11-20');

        $this->get(route('lots.edit', $lot))->assertOk()->assertSee('Change the expiry date')->assertSee('12 pc left');
        $this->put(route('lots.expiry', $lot), ['expiry_date' => '2027-01-31'])
            ->assertRedirect(route('products.show', $this->milk))->assertSessionHas('success', 'Expiry date changed from Nov 20, 2026 to Jan 31, 2027.');

        $this->assertSame('2027-01-31', $lot->fresh()->expiry_date->toDateString());
        $this->assertEquals(12, $this->milk->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'expiry', 'quantity' => 0, 'user_id' => $this->admin->id,
            'reference' => 'batch received Nov 15, 2026: Nov 20, 2026 → Jan 31, 2027']);

        $this->put(route('lots.expiry', $lot), ['expiry_date' => ''])->assertSessionHas('success');
        $this->assertNull($lot->fresh()->expiry_date);
    }

    public function test_the_count_of_one_batch_can_be_corrected_without_touching_the_others(): void
    {
        $soon = $this->receive(10, '2026-11-20');
        $later = $this->receive(10, '2027-02-01');

        $this->post(route('lots.adjust', $later), ['left' => 6, 'reason' => 'Lost or missing'])->assertSessionHas('success', 'This batch now has 6 pc (-4).');

        $this->assertEquals(10, (float) $soon->fresh()->remaining_qty);
        $this->assertEquals(6, (float) $later->fresh()->remaining_qty);
        $this->assertEquals(16, $this->milk->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjust', 'quantity' => -4, 'reference' => 'Lost or missing · batch received Nov 15, 2026']);

        // Not more than the batch came with, and pieces stay whole
        $this->post(route('lots.adjust', $later), ['left' => 11, 'reason' => 'Wrong count'])->assertSessionHasErrors('left');
        $this->post(route('lots.adjust', $later), ['left' => 2.5, 'reason' => 'Wrong count'])->assertSessionHasErrors('items');
        $this->post(route('lots.adjust', $later), ['left' => 2, 'reason' => 'Other'])->assertSessionHasErrors('note');
        $this->assertEquals(16, $this->milk->fresh()->stock);
    }

    public function test_an_expiring_batch_is_removed_from_stock_from_the_expiry_report(): void
    {
        $expiring = $this->receive(8, '2026-11-14');
        $this->receive(5, '2027-03-01');

        $this->get(route('reports.expiry'))->assertOk()->assertSee('Remove')->assertSee(route('lots.adjust', $expiring), false);
        $this->from(route('reports.expiry'))->post(route('lots.adjust', $expiring), [
            'left' => 0, 'reason' => 'Expired', 'note' => 'Expired, taken off the shelf', 'back' => route('reports.expiry'),
        ])->assertRedirect(route('reports.expiry'))->assertSessionHas('success', 'Removed all 8 pc of this batch from stock.');

        $this->assertEquals(0, (float) $expiring->fresh()->remaining_qty);
        $this->assertEquals(5, $this->milk->fresh()->stock);
        $this->get(route('reports.expiry'))->assertDontSee(route('lots.adjust', $expiring), false);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjust', 'quantity' => -8, 'balance_after' => 5]);
    }

    public function test_only_the_owner_fixes_batches_and_never_sends_you_off_site(): void
    {
        $lot = $this->receive(3, '2026-12-01');
        $this->post(route('lots.adjust', $lot), ['left' => 2, 'reason' => 'Damaged', 'back' => 'https://example.com/x'])
            ->assertRedirect(route('products.show', $this->milk));

        $cashier = User::create(['name' => 'Liza', 'username' => 'liza', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
        $this->actingAs($cashier)->get(route('lots.edit', $lot))->assertForbidden();
        $this->post(route('lots.adjust', $lot), ['left' => 0, 'reason' => 'Expired'])->assertForbidden();
        $this->put(route('lots.expiry', $lot), ['expiry_date' => '2030-01-01'])->assertForbidden();
    }

    public function test_the_product_page_links_each_batch_to_its_fix_page(): void
    {
        $lot = $this->receive(4, '2026-12-01');

        $this->get(route('products.show', $this->milk))->assertOk()->assertSee(route('lots.edit', $lot), false);
    }
}
