<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model {
    protected $fillable = [
        'order_id','supplier_code','rz_code','quantity','price',
        'reserved_quantity','serial_number'
    ];
}

