<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rates = Illuminate\Support\Facades\DB::table('commission_rules')->where('rule_version', 'PRELAUNCH-10E-v1')
    ->where('status','active')->orderBy('id')->get(['beneficiary_type','membership_status','fixed_amount','currency','effective_at']);
if ($rates->count() !== 3 || $rates->pluck('fixed_amount')->all() !== ['15.0000','25.0000','30.0000']
    || $rates->contains(fn(object $rate): bool => $rate->currency !== 'BOB')) throw new RuntimeException('Official rates unavailable');
// Query only: an absent reference returns UNKNOWN and creates no purchase.
$response = app(App\Services\ClubPurchaseClient::class)->acquisitionEvidence('club-10g-readonly-contract-probe', null);
$body = $response->json();
$unsigned = $body; unset($unsigned['evidence_reference']);
$valid = ($body['contract'] ?? null) === App\Services\Commissions\MembershipAcquisitionEvidence::CONTRACT
    && ($body['purchase_reference'] ?? null) === 'club-10g-readonly-contract-probe'
    && ($body['first_paid_acquisition'] ?? null) === 'unknown'
    && hash_equals('com:acquisition-evidence:sha256:'.hash('sha256',json_encode($unsigned,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $body['evidence_reference'] ?? '');
if (!$valid) throw new RuntimeException('COM evidence contract check failed');
echo json_encode(['http'=>$response->status(),'hmac_authenticated'=>true,'contract'=>$body['contract'],
    'digest_verified'=>true,'official_rates_bob'=>[15,25,30],'unknown_without_purchase'=>true,'live_fixtures'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
