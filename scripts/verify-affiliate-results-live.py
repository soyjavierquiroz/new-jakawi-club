#!/usr/bin/env python3
"""Verify CLUB results delivery without users, redemptions or synthetic LIVE clicks."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, json, subprocess

ROOT=Path('/opt/sites/jakawi.club')
OUT=ROOT/'artifacts/affiliate-results'
PRIVATE=Path('/srv/sites/jakawi.club/rollback-affiliate-results')
def command(args, **kw):
    return subprocess.check_output(args,cwd=ROOT,**kw)
def php(source):
    return json.loads(command(['docker','exec','-i','jakawi-club-app','php'],input=source.encode()))
head=command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain']), 'Tracked tree must be clean'
assert head==command(['git','rev-parse','origin/main']).decode().strip()==command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
expected={p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files','app']).decode().splitlines() if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded=base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);")==expected
state=php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::class;
$link=$db::table('affiliate_short_links')->where('code','Ms8xvfMr')->firstOrFail();
$offer=app(App\Services\CommercialCatalog::class)->query()->where('key',$link->offer_key)->firstOrFail();
$tables=[];foreach(['users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','jobs','affiliate_short_links'] as $table){$rows=$db::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}
echo json_encode(['debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached(),
'short_middleware'=>app('router')->getRoutes()->getByName('short-link')->gatherMiddleware(),
'migration_applied'=>$db::table('migrations')->where('migration','2026_10_30_000006_create_affiliate_link_daily_clicks')->exists(),
'columns'=>$db::table('information_schema.columns')->where('table_schema','public')->where('table_name','affiliate_link_daily_clicks')->orderBy('ordinal_position')->pluck('column_name'),
'fixture_schemas'=>$db::select("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'results_test_%'"),
'pilot_state'=>$offer->effective_state,'pilot_key'=>$link->offer_key,
'expected_location'=>app(App\Services\AffiliateShortLinks::class)->destination($offer->destination_url,$link->referral_code),
'permanent_links'=>$db::table('affiliate_short_links')->count(),'tables'=>$tables]);''')
assert state['debug'] is False and state['routes_cached'] and state['config_cached'] and state['migration_applied']
assert state['short_middleware']==[] and state['fixture_schemas']==[]
assert state['columns']==['link_code','day','clicks']
assert state['pilot_key']=='dossis-43' and state['pilot_state']=='active'
baseline=json.loads((PRIVATE/'baseline.json').read_text())
assert state['tables']==baseline['tables'], 'Protected links, users, conversions or commissions changed'
allowed={'app/app/Http/Controllers/AffiliateController.php','app/app/Http/Controllers/ShortLinkController.php','app/resources/js/components/CatalogOffer.tsx','app/resources/js/pages/Affiliates.tsx','app/resources/js/pages/Dashboard.tsx'}
for file,digest in baseline['files'].items():
    if file not in allowed: assert sha256((ROOT/file).read_bytes()).hexdigest()==digest, file
for file,digest in baseline['env'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/file).read_bytes()).hexdigest()==digest
for name in ['jakawi-club-web','jakawi-club-db']:
    old=baseline['containers'][name];c=json.loads(command(['docker','inspect',name]))[0]
    assert c['Id']==old['id'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts']
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*a,**kw): return None
opener=build_opener(NoRedirect())
def http(url,method='GET',headers=None):
    try: return opener.open(Request(url,method=method,headers=headers or {}),timeout=20)
    except HTTPError as error: return error
health={url:http(url).code for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']}
assert all(status==200 for status in health.values())
for path in ['/afiliados?tab=results','/admin/conversiones-com']:
    r=http('https://jakawi.club'+path)
    assert r.code==302 and r.headers['Location']=='https://jakawi.club/login'
# HEAD and explicitly labelled prefetch exercise the live redirect without counting.
for method,headers in [('HEAD',{}),('GET',{'Purpose':'prefetch'})]:
    r=http('https://jakawi.club/r/Ms8xvfMr?club_ref=PUBLIC_OVERRIDE',method,headers)
    assert r.code==302 and r.headers['Location']==state['expected_location']
    assert 'no-store' in r.headers['Cache-Control'] and not r.headers.get('Set-Cookie')
assets={}
for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
    for filename in [entry['file'],*entry.get('css',[])]:
        if filename in assets: continue
        r=http('https://jakawi.club/build/'+filename)
        assert r.code==200 and r.read()==(ROOT/'app/public/build'/filename).read_bytes()
        assets[filename]=sha256((ROOT/'app/public/build'/filename).read_bytes()).hexdigest()
for file in (PRIVATE/'build').rglob('*'):
    if file.is_file() and file.name!='manifest.json':assert file.read_bytes()==(ROOT/'app/public/build'/file.relative_to(PRIVATE/'build')).read_bytes()
browser=json.loads((OUT/'browser-live.json').read_text())
assert browser['live_assets'] and browser['no_live_mutations'] and browser['no_live_test_clicks']
assert all(c['legacy_anchor'] and c['no_explore_flash'] and c['back_forward'] and c['search_restored'] and c['no_overflow'] for c in browser['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,
'health':health,'permanent_links':state['permanent_links'],'existing_Ms8xvfMr_preserved':True,
'aggregates_columns':state['columns'],'protected_tables_unchanged':True,'protected_source_unchanged':True,'secrets_unchanged':True,
'web_db_not_restarted':True,'previous_chunks_preserved':True,'mounted_source_files_verified':len(expected),'https_assets_verified':len(assets),
'no_live_test_clicks':True,'live_fixtures':0,'isolated_tests':json.loads((OUT/'tests.json').read_text()),
'attribution_tests':json.loads((OUT/'attribution/tests.json').read_text()),'browser':browser}
(OUT/'LIVE.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(result,ensure_ascii=False,indent=2))
