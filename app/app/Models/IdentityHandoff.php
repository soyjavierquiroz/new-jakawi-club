<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IdentityHandoff extends Model
{
    protected $table = 'club_identity_handoffs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['*'];
    protected $hidden = ['request_body'];

    protected function casts(): array
    {
        return [
            'request_body' => 'encrypted',
            'attempts' => 'integer',
            'proof_expires_at' => 'datetime',
            'remote_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
