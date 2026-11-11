<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base setup: accounts, categories and units. Demo products and sales come from DemoSeeder.
     */
    public function run(): void
    {
        User::updateOrCreate(['username' => 'arnold'], [
            'name' => 'Arnold Bulan',
            'role' => 'admin',
            'password' => 'arnold123',
            'is_active' => true,
        ]);
        User::updateOrCreate(['username' => 'cashier'], [
            'name' => 'Cashier 1',
            'role' => 'cashier',
            'password' => 'cashier123',
            'is_active' => true,
        ]);

        foreach (['Canned Goods', 'Rice', 'Snacks', 'Beverages', 'Frozen Goods', 'Toiletries', 'Condiments', 'Noodles'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        foreach ([
            ['Piece', 'pc', false],
            ['Pack', 'pack', false],
            ['Case', 'case', false],
            ['Kilo', 'kg', true],
        ] as [$name, $abbr, $decimal]) {
            Unit::firstOrCreate(['name' => $name], ['abbreviation' => $abbr, 'allows_decimal' => $decimal]);
        }

        Supplier::firstOrCreate(['name' => 'Walk-in / Market'], ['contact_person' => null]);

        $this->call(DemoSeeder::class);
    }
}
