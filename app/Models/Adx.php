<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adx extends Model
{
    protected $table = 'adx';
    protected $guarded = [];

    protected $casts = [
        'active'    => 'boolean',
        'last_sync' => 'datetime',
    ];
}
