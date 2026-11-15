<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner approvals for a cashier's voids and returns, given at the counter (password or passkey)
 * or from the owner's own login anywhere. Every one is kept, approved or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type');                   // line_void, clear_order, sale_void, return
            $table->string('status')->default('pending'); // pending, approved, declined, cancelled, expired, failed
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary');
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('payload')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_via')->nullable(); // remote, password, passkey
            $table->timestamp('decided_at')->nullable();
            $table->string('message')->nullable();    // why it was declined or failed, or what it did (a return number)
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->foreignId('void_approved_by')->nullable()->after('voided_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_approved_by');
            $table->dropConstrainedForeignId('voided_by');
        });
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropConstrainedForeignId('approved_by'));
        Schema::dropIfExists('approval_requests');
    }
};
