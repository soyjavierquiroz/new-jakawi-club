<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class MembershipPurchase extends Model {
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['intent', 'buyer_email_digest', 'beneficiary_name'];
    protected function casts(): array {
        return ['intent' => 'encrypted:array', 'qr_ready' => 'boolean', 'activated_at' => 'immutable_datetime', 'beneficiary_name' => 'encrypted', 'reported_at' => 'immutable_datetime', 'payment_confirmed' => 'boolean', 'payment_confirmed_at' => 'immutable_datetime', 'receipt_pending' => 'boolean', 'receipt_received' => 'boolean'];
    }
}
