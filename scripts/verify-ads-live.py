#!/usr/bin/env python3
"""Read-only CLUB release seal; no live purchases or provider API calls."""
import hashlib,json,re,subprocess,urllib.request
from pathlib import Path
root=Path('/opt/sites/jakawi.club');evidence=root/'artifacts/ads-phase09'
def run(args,**kwargs):return subprocess.check_output(args,cwd=root,**kwargs)
assert not run(['git','status','--porcelain']).strip()
sha=run(['git','rev-parse','HEAD'],text=True).strip();assert sha==run(['git','rev-parse','origin/main'],text=True).strip()
baseline=json.loads(Path('/srv/sites/jakawi.club/rollback-ads-phase09/baseline.json').read_text())
assert hashlib.sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest()==baseline['env_sha256']
for name,previous in baseline['containers'].items():
    item=json.loads(run(['docker','inspect',name]))[0]
    assert item['Id']==previous['id'] and item['Image']==previous['image']
    if name!='jakawi-club-app':assert item['State']['StartedAt']==previous['started_at']
checks=[]
for provider in [None,'meta','tiktok']:
    for route,component in [('membresia','MembershipSales'),('activar','MembershipCheckout')]:
        path=('/ads/'+provider if provider else '')+'/'+route
        response=urllib.request.urlopen('https://jakawi.club'+path,timeout=20);html=response.read().decode()
        assert response.status==200
        page=json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>(.*?)</script>',html,re.S)[1]);assert page['component']==component
        assert not page['props']['ads']['allowed'] and not page['props']['ads']['pixel_id']
        if provider:
            assert 'noindex' in response.headers['X-Robots-Tag']
            assert re.search(r'<link[^>]+rel="canonical"[^>]+href="https://jakawi.club/'+route+'"',html)
        checks.append(path)
manifest=root/'app/public/build/manifest.json';assert manifest.read_bytes()==(root/'app/public/build-next/manifest.json').read_bytes()
for entry in json.loads(manifest.read_text()).values():
    for asset in [entry['file'],*entry.get('css',[])]:
        assert hashlib.sha256(urllib.request.urlopen('https://jakawi.club/build/'+asset,timeout=20).read()).digest()==hashlib.sha256((root/'app/public/build'/asset).read_bytes()).digest()
php=r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a=app(App\Services\Advertising::class);$state=['debug'=>config('app.debug'),'privacy_ready'=>(bool)config('ads.privacy_ready')];
foreach(['meta','tiktok']as$p)$state[$p]=['enabled'=>(bool)config("ads.$p.enabled"),'pixel_present'=>filled(config("ads.$p.pixel_id")),'token_present'=>filled(config("ads.$p.token")),'ready'=>$a->ready($p)];
$state['tables']=Illuminate\Support\Facades\Schema::hasTable('ad_events')&&Illuminate\Support\Facades\Schema::hasTable('ad_visits');echo json_encode($state);
'''
state=json.loads(run(['docker','compose','exec','-T','--user','www-data','app','php'],input=php.encode()))
assert state['debug'] is False and state['tables'] and not state['meta']['ready'] and not state['tiktok']['ready']
assert urllib.request.urlopen('https://jakawi.club/up',timeout=20).status==200
assert urllib.request.urlopen('http://127.0.0.1:18082/up',timeout=20).status==200
result={'sha':sha,'routes':checks,'health':'ok','manifest_sha256':hashlib.sha256(manifest.read_bytes()).hexdigest(),'configuration':state,
 'private_environment_unchanged':True,'club_web_db_not_restarted':True,'live_purchase_fixtures':0,'real_provider_calls':0}
(evidence/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
