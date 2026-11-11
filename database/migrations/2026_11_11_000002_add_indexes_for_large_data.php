<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries that slow down as years of sales pile up. Each one serves a page that was timed
 * against 400,000 sales and 1.4 million sale lines; the comment says which.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Left behind by down() after a rollback; the new indexes below cover them
        foreach (['sales' => 'sales_user_id_index', 'sale_items' => 'sale_items_sale_id_index', 'stock_movements' => 'stock_movements_product_id_index'] as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                $this->afterUp[] = [$table, $index];
            }
        }

        Schema::table('sales', function (Blueprint $table) {
            // Reports, the dashboard and the inventory estimate read completed sales by date. Date first:
            // with status first, MariaDB 10.4 reads every completed sale ever and only then checks the date.
            $table->index(['created_at', 'status', 'total', 'discount', 'payment_method'], 'sales_created_status_index');
            $table->dropIndex('sales_created_at_index'); // the new one starts with created_at, so this is extra
            // A cashier's own sales, newest first
            $table->index(['user_id', 'created_at'], 'sales_user_created_at_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // Every sales total reads these columns by sale; with them in the index the rows themselves aren't read
            $table->index(['sale_id', 'product_id', 'quantity', 'line_total', 'unit_cost'], 'sale_items_sale_totals_index');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Stock history filtered by type, and a product's own history, both newest first
            $table->index(['type', 'id'], 'stock_movements_type_id_index');
            $table->index(['product_id', 'id'], 'stock_movements_product_id_id_index');
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            // Lots still on the shelf by expiry: the alert bell on every owner page, the dashboard and the expiry report.
            // Most old lots are used up, so starting from remaining_qty skips them.
            $table->index(['remaining_qty', 'expiry_date'], 'delivery_items_remaining_expiry_index');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->index(['received_at', 'id'], 'deliveries_received_at_id_index');
        });

        Schema::table('products', function (Blueprint $table) {
            // The cashier's product list and the products page: active ones by name
            $table->index(['is_active', 'name'], 'products_active_name_index');
        });

        foreach ($this->afterUp as [$table, $index]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
        }
    }

    private array $afterUp = [];

    /**
     * MySQL drops a foreign key's own index once a new index starts with the same column, and then won't let the
     * new one go. So each foreign key gets a plain index back first.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index('created_at', 'sales_created_at_index');
            $table->index('user_id', 'sales_user_id_index');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_created_status_index');
            $table->dropIndex('sales_user_created_at_index');
        });
        Schema::table('sale_items', fn (Blueprint $table) => $table->index('sale_id', 'sale_items_sale_id_index'));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropIndex('sale_items_sale_totals_index'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->index('product_id', 'stock_movements_product_id_index'));
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_type_id_index');
            $table->dropIndex('stock_movements_product_id_id_index');
        });
        Schema::table('delivery_items', fn (Blueprint $table) => $table->dropIndex('delivery_items_remaining_expiry_index'));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropIndex('deliveries_received_at_id_index'));
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_active_name_index'));
    }
};
