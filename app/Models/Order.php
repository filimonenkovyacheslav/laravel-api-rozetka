<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model {
    protected $fillable = [
        'guid','comment','status','delivery_type',
        'cash_on_delivery','delivery_company','delivery_address_id',
        'delivery_city','delivery_street',
        'delivery_phone','customer_name',
        'created_at_partner','updated_at_partner','tracking_number'
    ];

    public function items() {
        return $this->hasMany(OrderItem::class);
    }

    public function files()
    {
        return $this->hasMany(OrderFile::class, 'guid', 'guid');
    }
}

