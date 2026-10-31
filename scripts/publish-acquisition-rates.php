<?php
// Run only on the explicitly authorized CLUB deployment. Never creates a purchase.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor = App\Models\User::where('is_marketing_admin',true)->orderBy('id')->firstOrFail();
$rules = app(App\Services\Commissions\AcquisitionRates::class)->publish($actor);
echo json_encode(array_map(fn(object $r): array => [
    'id'=>$r->id,'beneficiary_type'=>$r->beneficiary_type,'membership_status'=>$r->membership_status,
    'amount'=>$r->fixed_amount,'currency'=>$r->currency,'nature'=>$r->nature,'version'=>$r->rule_version,
    'effective_at'=>$r->effective_at,'status'=>$r->status,
],$rules),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
