#!/usr/bin/env python3
"""CLUB release evidence, plus a signed read of an existing COM purchase (no writes)."""
import json, os, runpy, subprocess
from pathlib import Path
root=Path('/opt/sites/jakawi.club'); evidence=root/'artifacts/payment-report-11d'
os.environ['JAKAWI_EVIDENCE_DIR']=str(evidence)
os.environ['JAKAWI_ROLLBACK_DIR']='/srv/sites/jakawi.club/rollback-payment-report-11d'
runpy.run_path(str(root/'scripts/verify-public-checkout-live.py'),run_name='__main__')
php=r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$columns=Illuminate\Support\Facades\Schema::hasColumns('membership_purchases', ['beneficiary_name','reported_at','payment_confirmed','payment_confirmed_at','receipt_pending','receipt_received']);
$route=app('router')->getRoutes()->match(Illuminate\Http\Request::create('/activar/aviso','POST'));
$purchase=App\Models\MembershipPurchase::whereNotNull('payment_reference')->latest()->first();
$contract=null;
if ($purchase) {
    $data=app(App\Services\ClubPurchaseClient::class)->request('GET','/'.$purchase->id)->json();
    $contract=($data['contract']??null)==='jakawi-club-purchases-v1' && ($data['club_reference']??null)===$purchase->id
        && ($data['amount']??null)==='100.00' && ($data['currency']??null)==='BOB'
        && is_string($data['beneficiary_name']??null) && trim($data['beneficiary_name'])!=='';
    foreach (['payment_confirmed','receipt_pending','receipt_received'] as $key) $contract=$contract && is_bool($data[$key]??null);
    foreach (['reported_at','payment_confirmed_at'] as $key) $contract=$contract && array_key_exists($key,$data);
}
echo json_encode(['report_columns_ready'=>$columns, 'report_route_ready'=>str_ends_with($route->getActionName(),'PublicMembershipController@report'),
'existing_com_purchase_contract_valid'=>$contract, 'live_writes'=>0]);
'''
checks=json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=root,input=php.encode()))
assert checks['report_columns_ready'] and checks['report_route_ready']
assert checks['existing_com_purchase_contract_valid'] is not False
release=json.loads((evidence/'LIVE.json').read_text());release.update(checks)
(evidence/'LIVE.json').write_text(json.dumps(release,indent=2)+'\n')
print(json.dumps(checks))
