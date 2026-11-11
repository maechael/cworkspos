<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name', 'abbreviation', 'allows_decimal'];

    protected $casts = ['allows_decimal' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
