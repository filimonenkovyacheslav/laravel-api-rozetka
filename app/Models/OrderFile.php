<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderFile extends Model
{
    protected $fillable = [
        'guid',
        'path',
    ];
}
