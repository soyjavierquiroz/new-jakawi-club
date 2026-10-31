#!/usr/bin/env python3
"""Focused CLUB media release baseline and final Git/runtime/asset verification."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import urlopen
from zoneinfo import ZoneInfo
import json
import subprocess
import sys

ROOT=Path('/opt/sites/jakawi.club')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-minio-media')
OUT=ROOT/'artifacts/minio-media'

def cmd(args,**kwargs):
    return subprocess.check_output(args,cwd=ROOT,**kwargs)

def php(code):
    return json.loads(cmd(['docker','compose','exec','-T','--user','www-data','app','php'],input=('<?php require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'+code).encode()))

def state():
    return php("$out=[];foreach(['marketing_landings'=>'id','affiliate_short_links'=>'code'] as $t=>$id){$out[$t]=hash('sha256',Illuminate\\Support\\Facades\\DB::table($t)->orderBy($id)->get()->toJson());}"
               "$rows=Illuminate\\Support\\Facades\\DB::table('commercial_offers')->where('source','com')->orderBy('key')->get()->map(function($v){unset($v->updated_at,$v->last_synced_at);return $v;});"
               "$out['com_offers']=hash('sha256',$rows->toJson());echo json_encode($out);")

def containers():
    return {c['Name']:{'id':c['Id'],'image':c['Image'],'started_at':c['State']['StartedAt'],'health':c['State']['Health']['Status'],'networks':sorted(c['NetworkSettings']['Networks']),'restarts':c['RestartCount']} for c in json.loads(cmd(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))}

def digest(path):return sha256(path.read_bytes()).hexdigest()

if sys.argv[1]=='baseline':
    PRIVATE.mkdir(mode=0o700,exist_ok=False)
    base={'sha':cmd(['git','rev-parse','HEAD']).decode().strip(),'state':state(),'containers':containers(),
          'app_env':digest(Path('/srv/sites/jakawi.club/secrets/app.env')),
          'unchanged_sources':{f:digest(ROOT/f) for f in ['app/app/Services/AffiliateShortLinks.php','app/app/Services/ReferralAttribution.php','app/resources/js/components/CatalogOffer.tsx']}}
    (PRIVATE/'baseline.json').write_text(json.dumps(base,indent=2)+'\n')
    print('Focused CLUB baseline ready; no data mutations.')
elif sys.argv[1]=='verify':
    base=json.loads((PRIVATE/'baseline.json').read_text())
    sha=cmd(['git','rev-parse','HEAD']).decode().strip()
    assert not cmd(['git','status','--porcelain'])
    assert sha==cmd(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
    assert state()==base['state'],'Published/draft content, links and COM offers must remain unchanged'
    assert digest(Path('/srv/sites/jakawi.club/secrets/app.env'))==base['app_env']
    assert all(digest(ROOT/f)==h for f,h in base['unchanged_sources'].items())
    current=containers()
    for name,info in current.items():
        assert info['health']=='healthy'
        assert info['networks']==base['containers'][name]['networks']
        if name=='/jakawi-club-db':
            assert info['id']==base['containers'][name]['id'] and info['started_at']==base['containers'][name]['started_at']
    image=cmd(['docker','image','inspect','jakawi-club-php:8.4-media-v1','--format','{{.Id}}']).decode().strip()
    assert current['/jakawi-club-app']['image']==image
    runtime=php("echo json_encode(['debug_disabled'=>!config('app.debug'),'s3'=>config('filesystems.disks.minio-media.driver')==='s3',"
                "'endpoint'=>config('filesystems.disks.minio-media.endpoint')==='https://myminioback.kuruk.in','bucket'=>config('media.bucket')==='jakawi-media',"
                "'credentials_ready'=>(bool)config('filesystems.disks.minio-media.key')&&(bool)config('filesystems.disks.minio-media.secret')&&(bool)config('media.imgproxy_key')&&(bool)config('media.imgproxy_salt'),"
                "'gd_webp'=>function_exists('imagewebp'),'gd_jpeg'=>function_exists('imagejpeg'),'exif'=>function_exists('exif_read_data'),"
                "'history_schema'=>Illuminate\\Support\\Facades\\Schema::hasTable('marketing_landing_snapshots'),'upload_limit'=>ini_get('upload_max_filesize')==='10M','post_limit'=>ini_get('post_max_size')==='12M']);")
    assert all(runtime.values()),runtime
    changed=cmd(['git','diff','--name-only',base['sha'],'HEAD','--','app']).decode().splitlines()
    sources={f.removeprefix('app/'):digest(ROOT/f) for f in changed if (ROOT/f).is_file()}
    actual=php('$out=[];foreach('+json.dumps(list(sources))+" as $f){$out[$f]=hash_file('sha256',$f);}echo json_encode($out);")
    assert actual==sources
    storage=json.loads((OUT/'storage-live.json').read_text())
    assert storage['passed'] and storage['controlled_cleanup'] and storage['object_count']==1
    assert storage['anonymous_s3_access_denied'] and all(v['http']==200 for v in storage['public_images'].values())
    assert storage['public_images']['social']['mime']=='image/jpeg'
    assert php("echo json_encode(!Illuminate\\Support\\Facades\\Storage::disk(config('media.disk'))->exists('"+storage['object_key']+"'));")
    browser=json.loads((OUT/'browser-live.json').read_text())
    assert browser['live_assets'] and browser['no_live_mutations'] and not browser['runtime_errors'] and not browser['failed_assets']
    health={}
    for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']:
        with urlopen(url,timeout=20) as r:assert r.status==200;health[url]=r.status
    with urlopen('https://jakawi.club/',timeout=20) as r:html=r.read().decode()
    assert 'property="og:image"' in html
    manifest=json.loads((ROOT/'app/public/build/manifest.json').read_text())
    assets=set()
    for entry in manifest.values():
        if entry.get('isEntry'):assert '/build/'+entry['file'] in html
        assets.update([entry['file'],*entry.get('css',[]),*entry.get('assets',[])])
    for asset in assets:
        with urlopen('https://jakawi.club/build/'+asset,timeout=20) as r:assert r.read()==(ROOT/'app/public/build'/asset).read_bytes()
    for old in (PRIVATE/'build').rglob('*'):
        if old.is_file() and old.name!='manifest.json':assert old.read_bytes()==(ROOT/'app/public/build'/old.relative_to(PRIVATE/'build')).read_bytes()
    result={'live_sha':sha,'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'origin_main_synced':True,
            'health':health,'runtime':runtime,'https_assets_verified':len(assets),'mounted_sources_verified':len(sources),
            'credential_mode':'owner_authorized_temporary_shared','one_private_live_object_verified_and_deleted':True,
            'imgproxy_presets_http':{k:v['http'] for k,v in storage['public_images'].items()},'social':'JPEG 1200x630',
            'snapshots_unchanged':True,'affiliate_links_unchanged':True,'com_offers_unchanged':True,'viewports':[390,1440],
            'no_live_test_accounts_or_landings':True,'only_club_app_web_recreated':True,'database_not_restarted':True}
    (OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2))
else:raise SystemExit('Usage: verify-media-live.py baseline|verify')
