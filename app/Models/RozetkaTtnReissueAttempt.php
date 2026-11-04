<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RozetkaTtnReissueAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'old_tracking_number',
        'new_tracking_number',
        'status',
        'cargo_seats',
        'error_message',
    ];

    protected $casts = [
        'cargo_seats' => 'array',
    ];
}
