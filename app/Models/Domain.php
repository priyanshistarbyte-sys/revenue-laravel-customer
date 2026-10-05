<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $table = 'domains';
    protected $guarded = [];

    protected $casts = [
        'approved' => 'boolean',
        'active'   => 'boolean',
    ];

    public function links()
    {
        return $this->hasMany(Link::class, 'domain_id');
    }

    public function adx()
    {
        return $this->belongsTo(Adx::class, 'adx_id');
    }
}
