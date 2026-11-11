<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/ProductBarcodesTest.php
 */
class ProductBarcodesTest extends TestCase
{
    private User $admin;

    private Unit $piece;

    private Product $canton;

    private const MAIN = '4800016055017';

    private const BOX = '4800016055994';

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->canton = $this->product('Pancit Canton', self::MAIN);
        $this->actingAs($this->admin);
    }

    private function product(string $name, string $barcode): Product
    {
        return Product::create(['barcode' => $barcode, 'name' => $name, 'unit_id' => $this->piece->id, 'cost_price' => 14, 'selling_price' => 18, 'stock' => 200, 'is_active' => true]);
    }

    private function box(array $changes = []): ProductBarcode
    {
        return $this->canton->extraBarcodes()->create($changes + ['barcode' => self::BOX, 'pieces' => 30, 'label' => 'Box of 30']);
    }

    private function save(Product $product, array $changes = [])
    {
        return $this->put(route('products.update', $product), $changes + [
            'barcode' => $product->barcode, 'name' => $product->name, 'unit_id' => $product->unit_id,
            'cost_price' => 14, 'selling_price' => 18, 'reorder_level' => 5, 'is_active' => 1,
        ]);
    }

    public function test_any_barcode_finds_the_same_product(): void
    {
        $this->box();
        $this->canton->extraBarcodes()->create(['barcode' => '4800016055000', 'pieces' => 1]);

        $main = $this->getJson('/api/products/lookup?barcode='.self::MAIN)->assertOk();
        $this->assertSame($this->canton->id, $main->json('id'));
        $this->assertNull($main->json('scan_pieces'));

        $other = $this->getJson('/api/products/lookup?barcode=4800016055000')->assertOk();
        $this->assertSame($this->canton->id, $other->json('id'));
        $this->assertEquals(1, $other->json('scan_pieces'));

        $box = $this->getJson('/api/products/lookup?barcode='.self::BOX)->assertOk();
        $this->assertSame($this->canton->id, $box->json('id'));
        $this->assertSame(self::MAIN, $box->json('barcode'));
        $this->assertEquals(30, $box->json('scan_pieces'));
        $this->assertSame('Box of 30', $box->json('scan_label'));
    }

    public function test_an_extra_barcode_is_found_in_its_12_and_13_digit_forms(): void
    {
        $this->box(['barcode' => '012345678905']);

        $this->getJson('/api/products/lookup?barcode=0012345678905')->assertOk()->assertJsonPath('id', $this->canton->id);
    }

    public function test_a_deactivated_product_cant_be_scanned_by_any_of_its_barcodes(): void
    {
        $this->box();
        $this->canton->update(['is_active' => false]);

        $this->getJson('/api/products/lookup?barcode='.self::BOX)->assertNotFound();
    }

    public function test_searches_find_extra_barcodes(): void
    {
        $this->box();

        $this->getJson('/api/products/search?q='.self::BOX)->assertOk()->assertJsonPath('0.id', $this->canton->id);
        $this->getJson('/products?q='.self::BOX)->assertOk()->assertJsonPath('data.0.id', $this->canton->id)->assertJsonPath('data.0.more_barcodes', 1);
    }

    public function test_the_form_adds_changes_and_removes_barcodes(): void
    {
        $this->save($this->canton, ['extra_barcodes' => [
            ['barcode' => self::BOX, 'pieces' => 30, 'label' => 'Box of 30'],
            ['barcode' => '4800016055000', 'pieces' => 1, 'label' => ''],
            ['barcode' => '', 'pieces' => 1, 'label' => ''], // a row never filled in
        ]])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->canton->extraBarcodes()->count());
        $this->assertEquals(30, $this->canton->extraBarcodes()->where('barcode', self::BOX)->value('pieces'));

        $this->save($this->canton, ['extra_barcodes' => [['barcode' => self::BOX, 'pieces' => 24, 'label' => 'Box of 24']]])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->canton->extraBarcodes()->count());
        $this->assertEquals(24, $this->canton->extraBarcodes()->value('pieces'));

        $this->save($this->canton)->assertSessionHasNoErrors();
        $this->assertSame(0, $this->canton->extraBarcodes()->count());
    }

    public function test_a_new_product_can_start_with_several_barcodes(): void
    {
        $this->post(route('products.store'), [
            'barcode' => '4800000000017', 'name' => 'Matcha', 'unit_id' => $this->piece->id, 'cost_price' => 23, 'selling_price' => 25,
            'reorder_level' => 5, 'is_active' => 1, 'extra_barcodes' => [['barcode' => '8936059330558', 'pieces' => 30, 'label' => '']],
        ])->assertSessionHasNoErrors();

        $this->getJson('/api/products/lookup?barcode=8936059330558')->assertOk()->assertJsonPath('name', 'Matcha');
    }

    public function test_one_barcode_can_only_ever_lead_to_one_product(): void
    {
        $this->box();
        $other = $this->product('Sardines', '4800024555011');

        // Another product's main barcode, as an extra
        $this->save($other, ['extra_barcodes' => [['barcode' => self::MAIN, 'pieces' => 1]]])->assertSessionHasErrors('extra_barcodes.0.barcode');
        // Another product's extra barcode, as an extra
        $this->save($other, ['extra_barcodes' => [['barcode' => self::BOX, 'pieces' => 1]]])->assertSessionHasErrors('extra_barcodes.0.barcode');
        // Another product's extra barcode, as a main barcode, with the owner named
        $this->save($other, ['barcode' => self::BOX])->assertSessionHasErrors(['barcode' => 'Barcode '.self::BOX.' already belongs to Pancit Canton.']);
        // The same code in its other form
        $this->canton->extraBarcodes()->create(['barcode' => '012345678905', 'pieces' => 1]);
        $this->save($other, ['extra_barcodes' => [['barcode' => '0012345678905', 'pieces' => 1]]])->assertSessionHasErrors('extra_barcodes.0.barcode');
        // Its own main barcode, or the same code twice
        $this->save($other, ['extra_barcodes' => [['barcode' => $other->barcode, 'pieces' => 1]]])->assertSessionHasErrors('extra_barcodes.0.barcode');
        $this->save($other, ['extra_barcodes' => [['barcode' => '111111', 'pieces' => 1], ['barcode' => '111111', 'pieces' => 2]]])->assertSessionHasErrors('extra_barcodes.0.barcode');

        $this->assertSame(0, $other->extraBarcodes()->count());
    }

    public function test_a_deactivated_products_barcodes_stay_reserved(): void
    {
        $this->box();
        $this->canton->update(['is_active' => false]);
        $other = $this->product('Sardines', '4800024555011');

        $this->save($other, ['extra_barcodes' => [['barcode' => self::BOX, 'pieces' => 1]]])
            ->assertSessionHasErrors(['extra_barcodes.0.barcode' => 'Barcode '.self::BOX.' already belongs to Pancit Canton, which is deactivated.']);
    }

    public function test_a_new_products_batch_barcode_cant_be_an_extra_one(): void
    {
        $this->box();

        $this->postJson('/products/batch', ['items' => [
            ['barcode' => self::BOX, 'name' => 'Duplicate', 'unit_id' => $this->piece->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.barcode');

        $this->assertFalse(Product::where('name', 'Duplicate')->exists());
    }

    public function test_pieces_per_scan_must_suit_how_the_item_is_sold(): void
    {
        $this->save($this->canton, ['extra_barcodes' => [['barcode' => self::BOX, 'pieces' => 2.5]]])->assertSessionHasErrors('extra_barcodes.0.pieces');
        $this->save($this->canton, ['extra_barcodes' => [['barcode' => self::BOX, 'pieces' => 0]]])->assertSessionHasErrors('extra_barcodes.0.pieces');

        $kilo = Unit::firstOrCreate(['name' => 'Kilo'], ['abbreviation' => 'kg', 'allows_decimal' => true]);
        $rice = Product::create(['barcode' => '2000896606939', 'name' => 'Rice', 'unit_id' => $kilo->id, 'cost_price' => 50, 'selling_price' => 62, 'stock' => 100, 'is_active' => true]);
        $this->save($rice, ['extra_barcodes' => [['barcode' => '2000000000011', 'pieces' => 2.5, 'label' => 'Small sack']]])->assertSessionHasNoErrors();
        $this->assertEquals(2.5, $rice->extraBarcodes()->value('pieces'));
    }

    public function test_a_scanned_code_can_be_attached_to_an_existing_product(): void
    {
        $response = $this->postJson(route('products.barcodes.store', $this->canton), ['barcode' => self::BOX, 'pieces' => 30, 'label' => 'Box of 30'])->assertCreated();

        $response->assertJsonPath('id', $this->canton->id)->assertJsonPath('scan_label', 'Box of 30');
        $this->assertEquals(30, $response->json('scan_pieces'));
        $this->getJson('/api/products/lookup?barcode='.self::BOX)->assertOk()->assertJsonPath('id', $this->canton->id);

        // Already taken, even by this same product
        $this->postJson(route('products.barcodes.store', $this->canton), ['barcode' => self::BOX, 'pieces' => 1])->assertUnprocessable();
        $this->postJson(route('products.barcodes.store', $this->canton), ['barcode' => '999', 'pieces' => 1.5])->assertUnprocessable()->assertJsonValidationErrors('pieces');
    }

    public function test_only_an_admin_can_add_barcodes_but_anyone_can_scan_them(): void
    {
        $this->box();
        $cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);

        $this->actingAs($cashier)->postJson(route('products.barcodes.store', $this->canton), ['barcode' => '999', 'pieces' => 1])->assertForbidden();
        $this->save($this->canton, ['extra_barcodes' => [['barcode' => '999', 'pieces' => 1]]])->assertForbidden();
        $this->getJson('/api/products/lookup?barcode='.self::BOX)->assertOk();
    }

    public function test_a_box_sold_at_the_counter_comes_off_stock_in_pieces(): void
    {
        $this->postJson('/pos/sales', [
            'items' => [['product_id' => $this->canton->id, 'quantity' => 30]],
            'amount_paid' => 540,
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('total', 540);

        $this->assertEquals(170, $this->canton->fresh()->stock);
    }

    public function test_a_new_product_delivered_in_a_box_goes_into_stock_as_pieces(): void
    {
        $this->postJson('/products/batch', ['items' => [[
            'barcode' => '8936059330999', 'piece_barcode' => '8936059330558', 'box_pieces' => 30, 'name' => '3 in 1 Coffee', 'unit_id' => $this->piece->id,
            'quantity' => 60, 'unit_cost' => 23, 'selling_price' => 25,
        ]]])->assertCreated();

        $coffee = Product::where('name', '3 in 1 Coffee')->first();
        $this->assertSame('8936059330558', $coffee->barcode);
        $this->assertEquals(60, $coffee->stock);

        // One sachet at the counter is one piece, and the box still counts all 30
        $this->assertNull($this->getJson('/api/products/lookup?barcode=8936059330558')->assertOk()->json('scan_pieces'));
        $box = $this->getJson('/api/products/lookup?barcode=8936059330999')->assertOk();
        $this->assertSame($coffee->id, $box->json('id'));
        $this->assertEquals(30, $box->json('scan_pieces'));

        $this->postJson('/pos/sales', ['items' => [['product_id' => $coffee->id, 'quantity' => 1]], 'amount_paid' => 25, 'payment_method' => 'cash'])
            ->assertCreated()->assertJsonPath('total', 25);
        $this->assertEquals(59, $coffee->fresh()->stock);
    }

    public function test_a_box_with_no_barcode_on_its_pieces_gets_an_in_store_code_for_them(): void
    {
        $this->postJson('/products/batch', ['items' => [[
            'barcode' => '8936059330999', 'box_pieces' => 30, 'name' => '3 in 1 Coffee', 'unit_id' => $this->piece->id, 'quantity' => 30, 'unit_cost' => 23, 'selling_price' => 25,
        ]]])->assertCreated();

        $this->assertMatchesRegularExpression('/^20\d{11}$/', Product::where('name', '3 in 1 Coffee')->value('barcode'));
    }

    public function test_a_box_and_its_piece_cant_share_a_barcode_or_split_a_piece(): void
    {
        $item = ['barcode' => '8936059330999', 'name' => 'Coffee', 'unit_id' => $this->piece->id, 'quantity' => 30, 'unit_cost' => 23, 'selling_price' => 25];

        $this->postJson('/products/batch', ['items' => [$item + ['box_pieces' => 30, 'piece_barcode' => '8936059330999']]])->assertUnprocessable()->assertJsonValidationErrors('items.0.piece_barcode');
        $this->postJson('/products/batch', ['items' => [$item + ['box_pieces' => 2.5]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.box_pieces');
        $this->postJson('/products/batch', ['items' => [$item + ['box_pieces' => 30, 'piece_barcode' => self::MAIN]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.piece_barcode');
    }

    public function test_a_main_barcode_can_be_moved_down_as_the_box(): void
    {
        $this->save($this->canton, ['barcode' => '', 'extra_barcodes' => [['barcode' => self::MAIN, 'pieces' => 30, 'label' => 'Box']]])->assertSessionHasNoErrors();

        $fresh = $this->canton->fresh();
        $this->assertMatchesRegularExpression('/^20\d{11}$/', $fresh->barcode);
        $this->assertEquals(30, $this->getJson('/api/products/lookup?barcode='.self::MAIN)->assertOk()->json('scan_pieces'));
    }

    public function test_a_blank_main_barcode_still_keeps_the_current_one_otherwise(): void
    {
        $this->save($this->canton, ['barcode' => ''])->assertSessionHasNoErrors();

        $this->assertSame(self::MAIN, $this->canton->fresh()->barcode);
    }

    public function test_the_product_pages_show_the_barcodes(): void
    {
        $this->box();

        $this->get(route('products.show', $this->canton))->assertOk()->assertSee(self::BOX)->assertSee('Box of 30')->assertSee('30 pc per scan');
        $this->get(route('products.edit', $this->canton))->assertOk()->assertSee('data-react="ProductBarcodes"', false)->assertSee(self::BOX);
        $this->get(route('products.create'))->assertOk()->assertSee('data-react="ProductBarcodes"', false);
    }
}
