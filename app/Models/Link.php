<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    protected $table = 'links';
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
