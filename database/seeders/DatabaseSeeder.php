<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A clean store: the two accounts and the starter lists, no products or sales.
     * Sample data for trying the app out: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        // firstOrCreate, so seeding again never puts a changed password back to the starting one
        User::firstOrCreate(['username' => 'arnold'], [
            'name' => 'Arnold Bulan',
            'role' => 'admin',
            'password' => 'arnold123',
            'is_active' => true,
        ]);
        User::firstOrCreate(['username' => 'cashier'], [
            'name' => 'Cashier 1',
            'role' => 'cashier',
            'password' => 'cashier123',
            'is_active' => true,
        ]);

        // The super admin, only when .env gives it a password (it never lives in the code). An existing account keeps its password.
        $super = config('app.super_admin');
        if ($super['username'] && $super['password']) {
            $exists = User::where('username', $super['username'])->exists();
            User::makeSuperAdmin($super['username'], $exists ? null : $super['password'], 'CWorks');
        }

        $this->call(StarterListsSeeder::class);
    }
}
