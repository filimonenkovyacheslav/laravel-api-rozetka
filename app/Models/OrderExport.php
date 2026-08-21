<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderExport extends Model
{
    protected $fillable = [
        'period_from',
        'period_to',
        'file_name',
        'file_path',
        'recipient_email',
        'status',
        'generated_at',
        'sent_at',
        'error_message',
        'created_by',
        'ttn_batch_id',
    ];

    protected $casts = [
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'generated_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function orders()
    {
        return $this->belongsToMany(
            Order::class,
            'order_export_order',
            'order_export_id',
            'order_id'
        )->withTimestamps();
    }

    public function ttnBatch()
    {
        return $this->belongsTo(
            \App\Models\TtnBatch::class,
            'ttn_batch_id'
        );
    }
}