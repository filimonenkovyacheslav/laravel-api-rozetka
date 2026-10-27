<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model {
    protected $fillable = [
        'guid',
        'order_num',
        'comment',
        'status',
        'rozetka_status',
        'uzd_status',
        'delivery_type',
        'cash_on_delivery',
        'delivery_company',
        'delivery_address_id',
        'delivery_city',
        'delivery_street',
        'delivery_phone',
        'customer_name',
        'tracking_number',
        'tracking_status_code',
        'tracking_status',
        'return_ttn',
        'document_link',
        'incoming_invoice_number',
        'sn_required',
        'created_at_partner',
        'updated_at_partner',
        'rozetka_synced_at',
    ];

    protected $casts = [
        'sn_required' => 'boolean',
        'rozetka_synced_at' => 'datetime',
    ];

    public function items() {
        return $this->hasMany(OrderItem::class);
    }

    public function files()
    {
        return $this->hasMany(OrderFile::class, 'guid', 'guid');
    }

    public function exports()
    {
        return $this->belongsToMany(
            \App\Models\OrderExport::class,
            'order_export_order',
            'order_id',
            'order_export_id'
        )->withTimestamps();
    }

    public function ttnBatchEntries()
    {
        return $this->hasMany(
            \App\Models\TtnBatchOrder::class,
            'order_id'
        );
    }
}

