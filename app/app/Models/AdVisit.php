<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class AdVisit extends Model {
    public $timestamps = false; public $incrementing = false; protected $keyType = 'string'; protected $guarded = [];
    protected $hidden = ['context'];
    protected function casts(): array { return ['context' => 'encrypted:array', 'expires_at' => 'immutable_datetime']; }
}
