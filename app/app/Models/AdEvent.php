<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class AdEvent extends Model {
    public $timestamps = false; public $incrementing = false; protected $keyType = 'string'; protected $guarded = [];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array', 'occurred_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime']; }
}
