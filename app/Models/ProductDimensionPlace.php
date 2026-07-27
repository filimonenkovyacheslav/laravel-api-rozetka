<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDimensionPlace extends Model
{
    protected $fillable = [
        'product_dimension_id',
        'place_number',
        'weight',
        'length',
        'width',
        'height',
    ];

    public function product()
    {
        return $this->belongsTo(
            ProductDimension::class,
            'product_dimension_id'
        );
    }
}