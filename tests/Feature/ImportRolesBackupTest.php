<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\LegacyImport;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The expiry switch, the Cashier + Inventory role, the old POS import and the weekly backups.
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/ImportRolesBackupTest.php
 */
class ImportRolesBackupTest extends TestCase
{
    private User $owner;

    private User $super;

    private User $stock;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }
        $this->artisan('migrate');

        Unit::create(['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->owner = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->super = User::create(['name' => 'Setup', 'username' => 'setup', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->super->forceFill(['is_super_admin' => true])->save();
        $this->stock = User::create(['name' => 'Joy Stock', 'username' => 'joy', 'role' => 'inventory', 'is_active' => true, 'password' => 'secret123']);
        $this->cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
    }

    // ---- Expiry switch ---------------------------------------------------------------------

    public function test_expiry_dates_are_off_until_the_super_admin_turns_them_on(): void
    {
        $this->assertFalse(track_expiry());
        $this->actingAs($this->owner)->get(route('deliveries.create'))->assertOk()->assertSee('"trackExpiry":false', false);
        $this->get(route('reports.expiry'))->assertRedirect(route('reports.inventory'));
        $this->get(route('reports.inventory'))->assertOk()->assertDontSee('Expiring items');
        $this->get('/dashboard')->assertOk()->assertDontSee('expiring within 30 days');

        // Only the super admin has the switch
        $this->put(route('settings.features'), ['track_expiry' => '1'])->assertForbidden();
        $this->actingAs($this->super)->get(route('settings.pos'))->assertOk()->assertSee('Turn expiry dates on');
        $this->put(route('settings.features'), ['track_expiry' => '1'])->assertRedirect();

        $this->assertTrue(track_expiry());
        $this->actingAs($this->owner)->get(route('deliveries.create'))->assertSee('"trackExpiry":true', false);
        $this->get(route('reports.expiry'))->assertOk();
        $this->get(route('reports.inventory'))->assertSee('Expiring items');
    }

    // ---- Cashier + Inventory role ----------------------------------------------------------

    public function test_cashier_plus_inventory_can_sell_and_keep_stock_but_not_see_the_owner_pages(): void
    {
        $product = Product::create(['barcode' => '111', 'name' => 'Cola', 'unit_id' => 1, 'cost_price' => 10, 'selling_price' => 15, 'stock' => 5, 'reorder_level' => 1]);
        $this->actingAs($this->stock);

        foreach (['/pos', '/sales', route('products.index'), route('products.show', $product), route('products.create'), route('products.batch'),
            route('deliveries.index'), route('deliveries.create'), route('stock-counts.index'), route('stock-counts.create'),
            route('reports.inventory'), route('reports.movements'), route('baskets.index'), route('master.index', 'categories')] as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/pos')->assertSee('Cashier + Inventory')->assertSee('Receive delivery')->assertSee('Stock count');

        foreach (['/dashboard', route('reports.sales'), route('users.index'), route('approvals.index'), route('products.import'), route('backups.index'), route('settings.pos')] as $page) {
            $this->get($page)->assertForbidden();
        }
        $this->get(route('reports.inventory'))->assertDontSee('>Sales</a>', false);

        // They can change stock
        $this->post(route('products.adjust-stock', $product), ['new_stock' => 8, 'reason' => 'Wrong count'])->assertRedirect();

        // A plain cashier still can't
        $this->actingAs($this->cashier)->get(route('products.index'))->assertForbidden();
        $this->get('/pos')->assertDontSee('Receive delivery');
    }

    public function test_the_owner_can_give_someone_the_new_role(): void
    {
        $this->actingAs($this->owner)->post(route('users.store'), ['name' => 'Ana', 'username' => 'ana', 'role' => 'inventory', 'password' => 'secret123'])->assertRedirect();
        $this->assertSame('inventory', User::where('username', 'ana')->value('role'));
        $this->get(route('users.index'))->assertSee('Cashier + Inventory');
    }

    // ---- Old POS import --------------------------------------------------------------------

    private function oldPosCsv(): string
    {
        // Same columns as the old POS export, in its order
        $rows = [
            ['Name', 'Price', 'Member Price 1', 'Member Price 2', 'Member Price 3', 'Barcode 1', 'Barcode 2', 'Barcode 3', 'Tax', 'Stop Sale(QTY=0)', 'Ask Price', 'Scale', 'Cost', 'Qty', 'Warn Qty', 'Background', 'Font', 'Sequence', 'Enable', 'Discountable'],
            ['DATU PUTI VINEGAR 350ML', '26', '0', '0', '0', '4801668100103', '', '', '0,0,0', '', '', '', '16.75', '3', '1', '#ffffffff', '#ff000000', '60', '', ''],
            ['SPRINGMADE PALM OIL 1L', '85', '0', '0', '0', 'OIL1L', 'S1L', '', '0,0,0', '', '', '', '70', '0', '2', '', '', '', '', ''],
            ['MY SAN GRAHAM CRUSHED 200G', '55', '0', '0', '0', '750515021358', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['MY SAN GRAHAM CRUSHED 200G', '62', '0', '0', '0', '750515021358', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['UFC CATSUP 200G', '25', '0', '0', '0', '14285003656', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['UFC TAMIS ANGHANG PACK 200G', '25', '0', '0', '0', '14285003656', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['DATU PUTI PATIS 350ML', '30', '0', '0', '0', '111', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['DATU PUTI PATIS 350ML', '30', '0', '0', '0', '222', '', '', '', '', '', '', '0', '0', '0', '', '', '', '', ''],
            ['MONGGO 1/2kg', '45', '0', '0', '0', '', '', '', '', '', '', '', '20', '4', '0', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Existing Cola', '20', '0', '0', '0', '999', '', '', '', '', '', '', '12', '7', '2', '', '', '', '', ''],
        ];
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);

        return stream_get_contents($handle);
    }

    public function test_the_import_plan_handles_the_old_pos_quirks(): void
    {
        Product::create(['barcode' => '999', 'name' => 'Cola old name', 'unit_id' => 1, 'cost_price' => 10, 'selling_price' => 18, 'stock' => 2, 'reorder_level' => 1]);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $this->oldPosCsv());

        $plan = app(LegacyImport::class)->plan(SpreadsheetReader::rows($path));
        $byLine = collect($plan['rows'])->keyBy('line');

        $this->assertSame('create', $byLine[2]['action']);
        $this->assertSame(['OIL1L', 'S1L'], $byLine[3]['barcodes']);
        $this->assertSame('skip', $byLine[5]['action']);                 // identical row
        $this->assertSame([], $byLine[7]['barcodes']);                   // barcode taken by row 6
        $this->assertStringContainsString('row 6', $byLine[7]['notes'][0]);
        $this->assertSame('merge', $byLine[9]['action']);                // same name, another barcode
        $this->assertSame(['111', '222'], $byLine[8]['barcodes']);
        $this->assertSame('update', $byLine[12]['action']);              // already here (by barcode)
        $this->assertSame(['create' => 7, 'update' => 1, 'merge' => 1, 'skip' => 1], array_intersect_key($plan['counts'], array_flip(['create', 'update', 'merge', 'skip'])));
        $this->assertContains('Member Price 1', $plan['ignored']);
        unlink($path);
    }

    public function test_the_owner_uploads_checks_and_imports_the_file(): void
    {
        Storage::fake('local');
        $cola = Product::create(['barcode' => '999', 'name' => 'Cola old name', 'unit_id' => 1, 'cost_price' => 10, 'selling_price' => 18, 'stock' => 2, 'reorder_level' => 1]);
        $file = UploadedFile::fake()->createWithContent('Product_2026_11_16.csv', $this->oldPosCsv());

        $this->actingAs($this->owner)->get(route('products.import'))->assertOk();
        $preview = $this->post(route('products.import.preview'), ['file' => $file])->assertOk()
            ->assertSee('7</div><div class="text-sm text-slate-500">new products', false)
            ->assertSee('Barcode 14285003656 is already on row 6');
        $token = $preview->viewData('token');
        $this->assertSame(0, Product::count() - 1, 'nothing saved by the preview');

        $this->post(route('products.import.store', $token), ['update_existing' => '1'])->assertRedirect(route('products.index'));

        $this->assertSame(8, Product::count());
        $vinegar = Product::where('barcode', '4801668100103')->first();
        $this->assertEquals(26, $vinegar->selling_price);
        $this->assertEquals(16.75, $vinegar->cost_price);
        $this->assertEquals(3, $vinegar->stock);
        $this->assertSame('opening', $vinegar->movements()->value('type'));
        $this->assertEquals(1, $vinegar->reorder_level);

        $this->assertSame(['S1L'], Product::where('barcode', 'OIL1L')->first()->extraBarcodes->pluck('barcode')->all());
        $this->assertSame('222', ProductBarcode::where('product_id', Product::where('barcode', '111')->value('id'))->value('barcode'));
        $this->assertSame(1, Product::where('name', 'MY SAN GRAHAM CRUSHED 200G')->count());
        $this->assertNull(Product::where('name', 'UFC TAMIS ANGHANG PACK 200G')->first()->extraBarcodes->first());
        $this->assertStringStartsWith('20', Product::where('name', 'MONGGO 1/2kg')->value('barcode'));

        // The product already here: updated, stock left alone
        $cola->refresh();
        $this->assertSame('Existing Cola', $cola->name);
        $this->assertEquals(20, $cola->selling_price);
        $this->assertEquals(2, $cola->stock);

        // The upload is gone, so the same import can't run twice
        $this->post(route('products.import.store', $token))->assertRedirect(route('products.import'));
        $this->assertSame(8, Product::count());
    }

    public function test_a_file_that_is_not_a_product_list_is_refused(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('notes.csv', "Date,Amount\n2026-11-01,500\n");

        $this->actingAs($this->owner)->from(route('products.import'))->post(route('products.import.preview'), ['file' => $file])
            ->assertRedirect(route('products.import'))
            ->assertSessionHas('error');
        $this->assertSame([], Storage::disk('local')->files('imports'));
    }

    // ---- Backups ---------------------------------------------------------------------------

    public function test_a_backup_holds_the_data_and_only_the_newest_are_kept(): void
    {
        Storage::fake('local');
        Product::create(['barcode' => '4800', 'name' => "Mama Sita's Oyster", 'unit_id' => 1, 'cost_price' => 10, 'selling_price' => 15, 'stock' => 5, 'reorder_level' => 1]);
        $backup = app(DatabaseBackup::class);

        $this->assertTrue($backup->isDue());
        $name = $backup->run();
        $this->assertFalse($backup->isDue());
        $sql = gzdecode(Storage::disk('local')->get('backups/'.$name));
        $this->assertStringContainsString('CREATE TABLE "products"', $sql);
        $this->assertStringContainsString("'Mama Sita''s Oyster'", $sql);

        // A week later it is due again
        $this->travel(8)->days();
        $this->assertTrue($backup->isDue());
        $this->assertNotNull($backup->runIfDue());
        $this->assertNull($backup->runIfDue());

        foreach (range(1, 9) as $i) {
            Storage::disk('local')->put("backups/backup-2026-01-0{$i}-000000.sql.gz", 'x');
        }
        $backup->run();
        $this->assertCount(DatabaseBackup::KEEP, $backup->list());
    }

    public function test_the_owner_downloads_backups_and_staff_cannot(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)->post(route('backups.store'))->assertRedirect()->assertSessionHas('success');
        $name = app(DatabaseBackup::class)->list()[0]['name'];

        $this->get(route('backups.index'))->assertOk()->assertSee($name);
        $this->get(route('backups.download', $name))->assertOk()->assertDownload($name);
        $this->get(route('backups.download', '..%2F.env'))->assertNotFound();

        $this->actingAs($this->stock)->get(route('backups.download', $name))->assertForbidden();
        $this->artisan('db:backup --if-due')->expectsOutputToContain('Not due yet')->assertSuccessful();
    }

    public function test_the_weekly_backup_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('db:backup --if-due')->assertSuccessful();
    }
}
