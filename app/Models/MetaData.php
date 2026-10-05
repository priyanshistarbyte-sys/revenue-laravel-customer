<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaData extends Model
{
    protected $table = 'meta_data';
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
