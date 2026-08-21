<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TtnBatchOrder extends Model
{
    protected $fillable = [
        'ttn_batch_id',
        'order_id',
        'status',
        'tracking_number',
        'attempts',
        'error_message',
        'processed_at',
        'last_attempt_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'processed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(
            TtnBatch::class,
            'ttn_batch_id'
        );
    }

    public function order()
    {
        return $this->belongsTo(
            Order::class,
            'order_id'
        );
    }
}