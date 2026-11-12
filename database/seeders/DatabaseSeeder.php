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

        $this->call(StarterListsSeeder::class);
    }
}
