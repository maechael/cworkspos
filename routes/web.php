<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BasketController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\PosSettingsController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoidApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', fn () => redirect()->route(auth()->user()->isAdmin() ? 'dashboard' : 'pos.index'));

    // Cashier and admin
    Route::get('/pos', [SaleController::class, 'pos'])->name('pos.index');
    Route::post('/pos/sales', [SaleController::class, 'store'])->name('sales.store');
    Route::put('/pos/draft', [SaleController::class, 'saveDraft'])->name('pos.draft');
    Route::post('/pos/void-approval', VoidApprovalController::class)->name('pos.void-approval');
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/find', [SaleController::class, 'find'])->name('sales.find');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');

    // Voids and returns: the owner does them straight away, a cashier with the owner's approval (checked in the controllers)
    Route::get('/sales/{sale}/void', [SaleController::class, 'voidForm'])->name('sales.void.form');
    Route::post('/sales/{sale}/void', [SaleController::class, 'void'])->name('sales.void');
    Route::get('/sales/{sale}/return', [SaleReturnController::class, 'create'])->name('sales.return');
    Route::post('/sales/{sale}/return', [SaleReturnController::class, 'store'])->name('sales.return.store');
    Route::get('/approvals/mode', [ApprovalController::class, 'mode'])->name('approvals.mode');
    Route::post('/approvals/ask', [ApprovalController::class, 'store'])->name('approvals.store');
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show'])->whereNumber('approval')->name('approvals.show');
    Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('approvals.cancel');
    Route::get('/api/products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
    Route::get('/api/products/search', [ProductController::class, 'search'])->name('products.search');
    Route::get('/api/products/catalog', [ProductController::class, 'catalog'])->name('products.catalog');

    // Owner / admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');

        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/switch', [ApprovalController::class, 'toggle'])->name('approvals.switch');
        Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{approval}/decline', [ApprovalController::class, 'decline'])->name('approvals.decline');
        Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');

        // Bringing in the product list from the old POS
        Route::get('/products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::post('/products/import', [ProductImportController::class, 'preview'])->name('products.import.preview');
        Route::post('/products/import/{token}', [ProductImportController::class, 'store'])->name('products.import.store');

        // Database backups (made every week by themselves)
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('/backups/{file}', [BackupController::class, 'download'])->where('file', '[A-Za-z0-9._-]+')->name('backups.download');

        // Setup pages: the super admin only, not the owner
        Route::middleware('super')->group(function () {
            Route::get('/settings/pos', [PosSettingsController::class, 'edit'])->name('settings.pos');
            Route::put('/settings/pos', [PosSettingsController::class, 'update'])->name('settings.pos.update');
            Route::put('/settings/features', [PosSettingsController::class, 'features'])->name('settings.features');
            Route::view('/settings/scanner', 'settings.scanner')->name('settings.scanner');
        });

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    // Stock work: the owner, and Cashier + Inventory staff
    Route::middleware('role:admin,inventory')->group(function () {
        Route::get('/products/labels', [ProductController::class, 'labels'])->name('products.labels');
        Route::get('/products/batch', [ProductController::class, 'batch'])->name('products.batch');
        Route::post('/products/batch', [ProductController::class, 'storeBatch'])->name('products.batch.store');
        Route::post('/products/{product}/barcodes', [ProductController::class, 'attachBarcode'])->name('products.barcodes.store');
        Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('products.adjust-stock');
        Route::get('/lots/{lot}', [LotController::class, 'edit'])->name('lots.edit');
        Route::put('/lots/{lot}/expiry', [LotController::class, 'updateExpiry'])->name('lots.expiry');
        Route::post('/lots/{lot}/adjust', [LotController::class, 'adjust'])->name('lots.adjust');
        Route::resource('products', ProductController::class);
        Route::resource('baskets', BasketController::class)->except('show');

        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/create', [DeliveryController::class, 'create'])->name('deliveries.create');
        Route::post('/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
        Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::put('/deliveries/{delivery}/expiry', [DeliveryController::class, 'updateExpiry'])->name('deliveries.expiry');
        Route::post('/deliveries/{delivery}/items/{item}/split', [DeliveryController::class, 'splitItem'])->name('deliveries.items.split');

        Route::get('/stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
        Route::get('/stock-counts/create', [StockCountController::class, 'create'])->name('stock-counts.create');
        Route::post('/stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
        Route::get('/stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');

        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
        Route::get('/reports/expiry', [ReportController::class, 'expiry'])->name('reports.expiry');
        Route::get('/reports/movements', [ReportController::class, 'movements'])->name('reports.movements');

        Route::get('/setup/{type}', [MasterDataController::class, 'index'])->name('master.index');
        Route::post('/setup/{type}', [MasterDataController::class, 'store'])->name('master.store');
        Route::put('/setup/{type}/{id}', [MasterDataController::class, 'update'])->name('master.update');
        Route::delete('/setup/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master.destroy');
    });
});
