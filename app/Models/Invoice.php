<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
