<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TtnBatch extends Model
{
    protected $fillable = [
        'status',
        'total_count',
        'success_count',
        'failed_count',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(
            TtnBatchOrder::class,
            'ttn_batch_id'
        );
    }

    public function orders()
    {
        return $this->belongsToMany(
            Order::class,
            'ttn_batch_orders',
            'ttn_batch_id',
            'order_id'
        )
            ->withPivot([
                'status',
                'tracking_number',
                'attempts',
                'error_message',
                'processed_at',
                'last_attempt_at',
            ])
            ->withTimestamps();
    }

    public function exports()
    {
        return $this->hasMany(
            OrderExport::class,
            'ttn_batch_id'
        );
    }
}