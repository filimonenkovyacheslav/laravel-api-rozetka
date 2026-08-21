<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDimension extends Model
{
    protected $fillable = [
        'rz_code',
        'name',
        'weight',
        'length',
        'width',
        'height',
    ];

    protected $casts = [
        'weight' => 'float',
        'length' => 'float',
        'width' => 'float',
        'height' => 'float',
    ];

    public function places()
    {
        return $this->hasMany(
            ProductDimensionPlace::class,
            'product_dimension_id'
        )->orderBy('place_number');
    }
}