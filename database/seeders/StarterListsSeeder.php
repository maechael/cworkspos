<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * The lists a new store starts with: grocery categories, units (products can't be saved without one)
 * and a walk-in supplier. Safe to run again; it only adds what is missing.
 */
class StarterListsSeeder extends Seeder
{
    public function run(): void
    {
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
    }
}
