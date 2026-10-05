<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaAccount extends Model
{
    protected $table = 'meta_accounts';
    protected $guarded = [];

    protected $casts = [
        'active'    => 'boolean',
        'last_sync' => 'datetime',
    ];
}
