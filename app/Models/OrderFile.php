<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderFile extends Model
{
    protected $fillable = [
        'guid',
        'path',
        'downloaded_at',
        'download_count',
        'downloaded_by',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'download_count' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(
            Order::class,
            'guid',
            'guid'
        );
    }
}