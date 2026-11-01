#!/usr/bin/env python3
"""Read-only CLUB release checks. Never issues a ticket or claims a real purchase."""
import hashlib,json,subprocess,urllib.request,urllib.error
from pathlib import Path
root=Path('/opt/sites/jakawi.club');evidence=root/'artifacts/member-access-11h'
def command(*args):return subprocess.check_output(args,cwd=root).decode().strip()
def get(path):
    try:
        with urllib.request.urlopen(path,timeout=20) as r:return r.status,r.read()
    except urllib.error.HTTPError as r:return r.code,r.read()
sha=command('git','rev-parse','HEAD')
assert sha==command('git','rev-parse','origin/main')
assert not command('git','status','--porcelain')
manifest=json.loads((root/'app/public/build/manifest.json').read_text())
for key in ['resources/js/pages/Dashboard.tsx','resources/js/pages/Affiliates.tsx']:
    asset=root/'app/public/build'/manifest[key]['file']
    status,body=get('https://jakawi.club/build/'+manifest[key]['file'])
    assert status==200 and hashlib.sha256(body).digest()==hashlib.sha256(asset.read_bytes()).digest()
assert get('https://jakawi.club/up')[0]==200
assert get('http://127.0.0.1:18082/up')[0]==200
php=r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$route=app('router')->getRoutes()->match(Illuminate\Http\Request::create('/cuenta/beneficios','POST'));
$p=App\Models\MembershipPurchase::findOrFail('97cb9a04-9f79-448d-be20-7a0b2bbb795d');
$u=App\Models\User::findOrFail($p->user_id);$referrer=App\Models\User::findOrFail($u->referred_by_id);
$r=app(App\Services\AffiliateResults::class)->summary($referrer);
echo json_encode(['route_ready'=>str_ends_with($route->getActionName(),'MemberBenefitsController@store'),'route_middleware'=>$route->gatherMiddleware(),
 'debug_false'=>config('app.debug')===false,'identity_enabled'=>(bool)config('identity.enabled'),
 'janny'=>['membership'=>$p->membership_reference,'status'=>$p->status,'identity'=>$p->identity_status,'original_claim_present'=>!empty($p->intent['claim_secret']),'evidence_present'=>(bool)app(App\Services\Identity\ActivationEvidence::class)->find($u)],
 'affiliate'=>['leads'=>$r['leads_captured'],'memberships'=>$r['memberships_activated'],'verification_pending'=>$r['commission_verification_pending']],
 'controller_sha256'=>hash_file('sha256',app_path('Http/Controllers/MemberBenefitsController.php'))]);
'''
checks=json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],input=php.encode(),cwd=root))
assert checks['route_ready'] and checks['debug_false'] and checks['identity_enabled']
assert all(m in checks['route_middleware'] for m in ['web','auth','verified','throttle:6,1'])
assert checks['controller_sha256']==hashlib.sha256((root/'app/app/Http/Controllers/MemberBenefitsController.php').read_bytes()).hexdigest()
assert checks['janny']['membership']=='2' and checks['janny']['status']=='approved'
assert checks['affiliate']['verification_pending']
checks.update({'sha_live':sha,'club_health':200,'loopback_health':200,'served_assets_match':True,'live_tickets_issued_by_verification':0,'live_fixtures':0,'browser':json.loads((evidence/'browser-live.json').read_text())})
(evidence/'LIVE.json').write_text(json.dumps(checks,indent=2)+'\n')
print(json.dumps(checks,indent=2))
