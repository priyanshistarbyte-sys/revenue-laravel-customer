<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Amaira user — PIN authenticated (no email/password). See App\Support\Auth.
 */
class User extends Model
{
    protected $guarded = [];

    protected $hidden = ['pin_hash', 'pin2_hash'];

    protected $casts = [
        'is_admin' => 'boolean',
        'active'   => 'boolean',
    ];

    public function hasSecondPin(): bool
    {
        return !empty($this->pin2_hash);
    }
}
