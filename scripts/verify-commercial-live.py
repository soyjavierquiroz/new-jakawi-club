#!/usr/bin/env python3
"""Verify only the commercial CLUB release, without inserting a LIVE event."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, urlopen, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, hmac, json, subprocess, time

ROOT=Path('/opt/sites/jakawi.club')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-commercial-phase05')
OUT=ROOT/'artifacts/commercial-phase05'
base=json.loads((PRIVATE/'baseline.json').read_text())
def command(args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def php(source):return json.loads(command(['docker','exec','-i','jakawi-club-app','php'],input=source.encode()))
head=command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain'])
assert head==command(['git','rev-parse','origin/main']).decode().strip()==command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
for name,digest in base['env'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/name).read_bytes()).hexdigest()==digest, name
allowed={'app/bootstrap/app.php','app/routes/web.php','app/resources/js/pages/admin/Landings.tsx'}
protected=[p for p in base['files'] if p not in allowed and not p.startswith(('app/storage/','app/bootstrap/cache/'))]
assert all(sha256((ROOT/p).read_bytes()).hexdigest()==base['files'][p] for p in protected)
files=command(['git','ls-files','app']).decode().splitlines()
expected={p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in files if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded=base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);")==expected
encoded=base64.b64encode(json.dumps(base['tables']).encode()).decode()
state=php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before=json_decode(base64_decode('''+"'"+encoded+"'"+r'''),true);$tables=[];foreach($before as $table=>$b){$rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$table]=count($rows)===$b['count']&&hash('sha256',json_encode($rows))===$b['digest'];}
$route=app('router')->getRoutes()->getByName('commercial.receive');$admin=app('router')->getRoutes()->getByName('commercial.index');
echo json_encode(['protected_tables'=>$tables,'app_debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached(),
'receiver_middleware'=>$route->gatherMiddleware(),'admin_middleware'=>$admin->gatherMiddleware(),'key_id'=>config('commercial.key_id'),
'secret_digest'=>hash('sha256',trim(file_get_contents(config('commercial.secret_file')))),
'live_events'=>Illuminate\Support\Facades\DB::table('commercial_events')->count(),'live_conflicts'=>Illuminate\Support\Facades\DB::table('commercial_event_conflicts')->count()]);''')
assert all(state['protected_tables'].values()),state['protected_tables']
assert state['app_debug'] is False and state['routes_cached'] and state['config_cached']
assert state['receiver_middleware']==['App\\Http\\Middleware\\AuthenticateCommercialEvent']
assert all(x in state['admin_middleware'] for x in ['auth','verified','App\\Http\\Middleware\\MarketingAdmin'])
secret_file=Path('/srv/sites/jakawi.club/secrets/com-commercial-hmac')
assert secret_file.stat().st_mode&0o777==0o640 and secret_file.stat().st_uid==0
secret=secret_file.read_text().strip()
assert state.pop('secret_digest')==sha256(secret.encode()).hexdigest()
assert secret!=Path('/srv/sites/jakawi.club/secrets/crm-hmac').read_text().strip()
for name,old in base['containers'].items():
    if name=='jakawi-club-app':continue
    c=json.loads(command(['docker','inspect',name]))[0]
    assert c['Id']==old['id'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts']
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*a,**kw):return None
opener=build_opener(NoRedirect())
def http(url,data=None,headers=None):
    try:return opener.open(Request(url,data=data,headers=headers or {}),timeout=20)
    except HTTPError as error:return error
health={url:http(url).code for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']}
assert all(code==200 for code in health.values())
url='https://jakawi.club/internal/commercial/events'
assert http(url,b'{}',{'Content-Type':'application/json','Accept':'application/json'}).code==401
timestamp=str(int(time.time()))
canonical=timestamp+'\nPOST\n/internal/commercial/events\n'+sha256(b'{}').hexdigest()
headers={'Content-Type':'application/json','Accept':'application/json','X-Jakawi-Com-Key-Id':'com-commercial-v1','X-Jakawi-Com-Timestamp':timestamp,
    'X-Jakawi-Com-Signature':'sha256='+hmac.new(secret.encode(),canonical.encode(),'sha256').hexdigest()}
r=http(url,b'{}',headers)
assert r.code==422, (r.code,r.read())
assert 'schema_version' in json.loads(r.read())['errors']
assert not r.headers.get('Set-Cookie')
assert http(url,b'{ }',headers).code==401, 'Signature must cover exact bytes'
r=http('https://jakawi.club/admin/conversiones-com')
assert r.code==302 and r.headers['Location']=='https://jakawi.club/login'
after=php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo json_encode(['events'=>Illuminate\Support\Facades\DB::table('commercial_events')->count(),'conflicts'=>Illuminate\Support\Facades\DB::table('commercial_event_conflicts')->count()]);''')
assert after=={'events':state['live_events'],'conflicts':state['live_conflicts']}
assets={}
for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
    for filename in [entry['file'],*entry.get('css',[])]:
        if filename in assets:continue
        r=http('https://jakawi.club/build/'+filename);assert r.code==200
        content=r.read();assert content==(ROOT/'app/public/build'/filename).read_bytes()
        assets[filename]=sha256(content).hexdigest()
for file in (PRIVATE/'build').rglob('*'):
    if file.is_file() and file.name!='manifest.json':assert file.read_bytes()==(ROOT/'app/public/build'/file.relative_to(PRIVATE/'build')).read_bytes()
tests=json.loads((OUT/'tests.json').read_text())
browser=json.loads((OUT/'browser-live.json').read_text())
assert all(x['filters_work'] and x['no_overflow'] for x in browser['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,'scope':'CLUB only',
    'mounted_source_files_verified':len(expected),'previous_source_files_unchanged':len(protected),'existing_secrets_unchanged':True,'web_db_not_restarted':True,
    'health':health,'https_assets_verified':len(assets),'previous_chunks_preserved':True,'receiver_url':url,'live_unauthenticated_rejected':True,
    'live_dedicated_hmac_validated_without_inserting_event':True,'live_tampering_rejected':True,'admin_url':'https://jakawi.club/admin/conversiones-com',
    'live_guest_admin_denied':True,'isolated_http_checks_passed':tests['passed'],'admin_browser_checks':browser,'state':state}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
