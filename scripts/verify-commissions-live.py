#!/usr/bin/env python3
"""Seal this CLUB release, its mounted code and assets; never create a LIVE sale."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, hmac, json, subprocess, time

ROOT = Path('/opt/sites/jakawi.club')
OUT = ROOT/'artifacts/commissions-06c'
PRIVATE = Path('/srv/sites/jakawi.club/rollback-commissions-06c')
def command(args, **kw):
    return subprocess.check_output(args, cwd=ROOT, **kw)
def php(source):
    return json.loads(command(['docker','exec','-i','jakawi-club-app','php'], input=source.encode()))
head = command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain'])
assert head == command(['git','rev-parse','origin/main']).decode().strip() == command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
expected = {p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files','app']).decode().splitlines() if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded = base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);") == expected
state = php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['app_debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached(),
'affiliate_middleware'=>app('router')->getRoutes()->getByName('affiliates.index')->gatherMiddleware(),
'receiver_middleware'=>app('router')->getRoutes()->getByName('commercial.receive')->gatherMiddleware(),
'live_events'=>Illuminate\Support\Facades\DB::table('commercial_events')->count(),
'rules'=>Illuminate\Support\Facades\DB::table('commission_rules')->count(),
'active_rules'=>Illuminate\Support\Facades\DB::table('commission_rules')->where('status','active')->count(),
'ledger_entries'=>Illuminate\Support\Facades\DB::table('commission_ledger')->count(),
'approved'=>Illuminate\Support\Facades\DB::table('commission_ledger')->where('status','approved')->count(),
'rules_middleware'=>app('router')->getRoutes()->getByName('commissions.rules')->gatherMiddleware(),
'ledger_middleware'=>app('router')->getRoutes()->getByName('commissions.ledger')->gatherMiddleware(),
'migration_applied'=>Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_30_000002_create_commission_engine')->exists(),
'money_column'=>Illuminate\Support\Facades\DB::selectOne("select data_type,numeric_precision,numeric_scale from information_schema.columns where table_schema=current_schema() and table_name='commission_ledger' and column_name='amount'")]);''')
assert state['app_debug'] is False and state['routes_cached'] and state['config_cached']
assert all(x in state['affiliate_middleware'] for x in ['auth','verified','App\\Http\\Middleware\\ActiveAffiliate'])
assert state['receiver_middleware'] == ['App\\Http\\Middleware\\AuthenticateCommercialEvent']
assert state['migration_applied'] and state['active_rules']==0 and state['rules']==0 and state['approved']==0
assert state['money_column']=={'data_type':'numeric','numeric_precision':20,'numeric_scale':4}
for key in ['rules_middleware','ledger_middleware']:
    assert all(x in state[key] for x in ['auth','verified','App\\Http\\Middleware\\MarketingAdmin'])
baseline = json.loads((PRIVATE/'baseline.json').read_text())
for name, old in baseline['containers'].items():
    c = json.loads(command(['docker','inspect',name]))[0]
    assert {'id':c['Id'],'started_at':c['State']['StartedAt']} == old
for name, digest in baseline['protected_files'].items():
    assert sha256((ROOT/name).read_bytes()).hexdigest() == digest, name
for name, digest in baseline['secret_files'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/name).read_bytes()).hexdigest() == digest, name
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*a,**kw): return None
opener = build_opener(NoRedirect())
def http(url, data=None, headers=None):
    try: return opener.open(Request(url,data=data,headers=headers or {}),timeout=20)
    except HTTPError as error: return error
health = {url:http(url).code for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']}
assert all(code == 200 for code in health.values())
for path in ['/afiliados','/admin/conversiones-com','/admin/reglas-comisiones','/admin/comisiones']:
    r=http('https://jakawi.club'+path)
    assert r.code==302 and r.headers['Location']=='https://jakawi.club/login'
url='https://jakawi.club/internal/commercial/events'
assert http(url,b'{}',{'Content-Type':'application/json','Accept':'application/json'}).code==401
secret=Path('/srv/sites/jakawi.club/secrets/com-commercial-hmac').read_text().strip()
timestamp=str(int(time.time()))
canonical=timestamp+'\nPOST\n/internal/commercial/events\n'+sha256(b'{}').hexdigest()
headers={'Content-Type':'application/json','Accept':'application/json','X-Jakawi-Com-Key-Id':'com-commercial-v1','X-Jakawi-Com-Timestamp':timestamp,
'X-Jakawi-Com-Signature':'sha256='+hmac.new(secret.encode(),canonical.encode(),'sha256').hexdigest()}
r=http(url,b'{}',headers)
assert r.code==422 and 'schema_version' in json.loads(r.read())['errors']
assert not r.headers.get('Set-Cookie')
assets={}
for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
    for filename in [entry['file'],*entry.get('css',[])]:
        if filename in assets: continue
        r=http('https://jakawi.club/build/'+filename)
        assert r.code==200 and r.read()==(ROOT/'app/public/build'/filename).read_bytes()
        assets[filename]=sha256((ROOT/'app/public/build'/filename).read_bytes()).hexdigest()
for file in (PRIVATE/'build').rglob('*'):
    if file.is_file() and file.name!='manifest.json': assert file.read_bytes()==(ROOT/'app/public/build'/file.relative_to(PRIVATE/'build')).read_bytes()
browser=json.loads((OUT/'browser-live.json').read_text())
assert browser['live_assets'] and all(c['no_overflow'] and c['decimal_string'] and c['approval_guard'] and c['exact_currency_totals'] for c in browser['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,
'rules_url':'https://jakawi.club/admin/reglas-comisiones','ledger_url':'https://jakawi.club/admin/comisiones','affiliate_url':'https://jakawi.club/afiliados','health':health,'mounted_source_files_verified':len(expected),'https_assets_verified':len(assets),
'previous_chunks_preserved':True,'web_db_unchanged':True,'protected_integrations_unchanged':True,'receiver_authenticated_without_live_event':True,
'guest_private_panels_denied':True,'isolated_tests':json.loads((OUT/'tests.json').read_text()),'browser':browser,'state':state}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
