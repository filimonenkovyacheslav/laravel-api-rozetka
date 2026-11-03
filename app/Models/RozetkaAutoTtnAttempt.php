<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RozetkaAutoTtnAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'status',
        'tracking_number',
        'error_message',
        'attempted_at',
        'completed_at',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
