<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A short code a supervisor can use instead of their account password to approve a voided line. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('void_passkey')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('void_passkey');
        });
    }
};
