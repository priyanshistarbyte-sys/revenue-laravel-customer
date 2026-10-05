<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $table = 'expenses';
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
