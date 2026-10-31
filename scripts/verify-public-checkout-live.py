#!/usr/bin/env python3
"""Read-only proof of the deployed CLUB checkout, without LIVE purchase fixtures."""
import hashlib, json, re, subprocess, urllib.request
from pathlib import Path
root=Path('/opt/sites/jakawi.club');evidence=root/'artifacts/public-checkout'
baseline=json.loads(Path('/srv/sites/jakawi.club/rollback-public-checkout/baseline.json').read_text())
def run(args,**kwargs):return subprocess.check_output(args,cwd=root,**kwargs)
assert not run(['git','status','--porcelain']).strip(), 'Working tree differs from release'
sha=run(['git','rev-parse','HEAD'],text=True).strip()
assert run(['git','rev-parse','origin/main'],text=True).strip()==sha
for name,before in baseline['containers'].items():
    item=json.loads(run(['docker','inspect',name]))[0]
    assert {'id':item['Id'],'image':item['Image'],'started_at':item['State']['StartedAt']}==before, name+' changed'
for path,component in [('/membresia','MembershipSales'),('/activar','MembershipCheckout')]:
    response=urllib.request.urlopen('https://jakawi.club'+path,timeout=20)
    assert response.status==200 and response.url.endswith(path)
    body=response.read().decode()
    page=json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>(.*?)</script>',body,re.S)[1])
    assert page['component']==component
    if path=='/membresia':
        assert page['props']['landing']['social']['canonical']=='https://jakawi.club/membresia'
        assert page['props']['landing']['social']['robots']=='index, follow'
    else: assert not page['props']['signed_in'] and page['props']['purchase'] is None
manifest=root/'app/public/build/manifest.json'
assert manifest.read_bytes()==(root/'app/public/build-next/manifest.json').read_bytes()
for entry in json.loads(manifest.read_text()).values():
    for asset in [entry['file'],*entry.get('css',[])]:
        data=urllib.request.urlopen('https://jakawi.club/build/'+asset,timeout=20).read()
        assert hashlib.sha256(data).digest()==hashlib.sha256((root/'app/public/build'/asset).read_bytes()).digest()
php=r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$qr=app(App\Services\ClubPurchaseClient::class)->request('GET','/qr');
$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($qr->body());
$home=Illuminate\Support\Facades\DB::table('marketing_landings')->where('slug',config('jakawi.home_landing_slug'))->first();
echo json_encode(['debug'=>config('app.debug'),'purchase_secret_ready'=>strlen((string)config('purchases.secret'))>=32,
'recipient_ready'=>filled(config('purchases.recipient')),'qr_mime'=>$mime,'qr_sha256'=>hash('sha256',$qr->body()),
'home_snapshot_sha256'=>hash('sha256',json_encode($home,JSON_THROW_ON_ERROR))]);
'''
state=json.loads(run(['docker','compose','exec','-T','--user','www-data','app','php'],input=php.encode()))
assert state['debug'] is False and state['purchase_secret_ready'] and state['recipient_ready']
assert state['qr_mime'] in ['image/png','image/jpeg']
assert state['home_snapshot_sha256']==baseline['home_snapshot_sha256']
assert urllib.request.urlopen('https://jakawi.club/up',timeout=20).status==200
checks={'sha':sha,'manifest_sha256':hashlib.sha256(manifest.read_bytes()).hexdigest(),'public_routes':True,'real_com_qr_hmac_read_only':True,
'home_snapshot_unchanged':True,'com_and_other_containers_unchanged':True,'live_purchase_fixtures':0,'real_bank_payments':0,'backend':state}
(evidence/'LIVE.json').write_text(json.dumps(checks,indent=2)+'\n')
print(json.dumps({k:v for k,v in checks.items() if k!='backend'},indent=2))
