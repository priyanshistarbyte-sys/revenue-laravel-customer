<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamData extends Model
{
    protected $table = 'gam_data';
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
