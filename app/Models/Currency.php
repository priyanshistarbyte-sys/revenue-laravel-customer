<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'currencies';
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'active'     => 'boolean',
    ];
}
