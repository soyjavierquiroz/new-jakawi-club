#!/usr/bin/env python3
"""Verify only CLUB catalog delivery, without creating users, sessions or sales."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, json, subprocess

ROOT = Path('/opt/sites/jakawi.club')
OUT = ROOT/'artifacts/catalog-07b2'
PRIVATE = Path('/srv/sites/jakawi.club/rollback-catalog-07b2')
def command(args, **kw):
    return subprocess.check_output(args, cwd=ROOT, **kw)
def php(source):
    return json.loads(command(['docker','exec','-i','jakawi-club-app','php'], input=source.encode()))
head = command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain']), 'Tracked tree must be clean'
assert head == command(['git','rev-parse','origin/main']).decode().strip() == command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
expected = {p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files','app']).decode().splitlines() if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded = base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);") == expected
state = php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$catalog=app(App\Services\CommercialCatalog::class);
$link=Illuminate\Support\Facades\DB::table('affiliate_short_links')->where('code','Ms8xvfMr')->firstOrFail();
$offer=$catalog->query()->where('key','dossis-43')->firstOrFail();
$tables=[];foreach(['users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','jobs','affiliate_short_links'] as $table){$rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}
echo json_encode(['app_debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached(),
'short_middleware'=>app('router')->getRoutes()->getByName('short-link')->gatherMiddleware(),
'generate_middleware'=>app('router')->getRoutes()->getByName('affiliates.generate')->gatherMiddleware(),
'legacy_controller'=>app('router')->getRoutes()->getByName('referral')->getActionName(),
'migration_applied'=>Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_30_000005_extend_commercial_catalog')->exists(),
'pilot_source_id'=>$offer->source_id,'pilot_key'=>$link->offer_key,'pilot_state'=>$offer->effective_state,
'expected_location'=>app(App\Services\AffiliateShortLinks::class)->destination($offer->destination_url,$link->referral_code),
'offers'=>$catalog->query()->orderBy('source')->orderBy('source_id')->get(['key','source','source_id','name','effective_state','catalog_present','last_synced_at']),
'last_success'=>Illuminate\Support\Facades\DB::table('catalog_sync_runs')->where('status','success')->orderByDesc('id')->first(),
'fixture_schemas'=>Illuminate\Support\Facades\DB::select("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'catalog_test_%'"),
'links'=>Illuminate\Support\Facades\DB::table('affiliate_short_links')->count(),'tables'=>$tables]);''')
assert state['app_debug'] is False and state['routes_cached'] and state['config_cached'] and state['migration_applied']
assert state['short_middleware']==[] and state['pilot_source_id']=='benefit:1' and state['pilot_key']=='dossis-43'
assert all(x in state['generate_middleware'] for x in ['auth','verified','App\\Http\\Middleware\\ActiveAffiliate'])
assert state['legacy_controller']=='App\\Http\\Controllers\\ReferralController@show'
assert state['fixture_schemas']==[] and state['last_success']['offers']>=3
assert len([o for o in state['offers'] if o['source_id']=='benefit:1'])==1
baseline = json.loads((PRIVATE/'baseline.json').read_text())
assert state['tables']==baseline['tables'], 'Existing links, users, events, commissions or jobs changed'
for name in ['jakawi-club-web','jakawi-club-db']:
    old=baseline['containers'][name]
    c=json.loads(command(['docker','inspect',name]))[0]
    assert c['Id']==old['id'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts']
allowed = {'app/app/Http/Controllers/AffiliateController.php','app/app/Http/Controllers/CommercialOfferAdminController.php','app/app/Http/Controllers/ShortLinkController.php','app/app/Services/AffiliateShortLinks.php','app/resources/js/pages/Affiliates.tsx','app/resources/js/pages/admin/CommercialOffers.tsx','app/resources/views/short-link-unavailable.blade.php','app/routes/web.php'}
for file,digest in baseline['files'].items():
    if file not in allowed: assert sha256((ROOT/file).read_bytes()).hexdigest()==digest, file
for file,digest in baseline['env'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/file).read_bytes()).hexdigest()==digest
assert command(['systemctl','is-active','jakawi-club-catalog.timer']).decode().strip()=='active'
assert command(['systemctl','is-enabled','jakawi-club-catalog.timer']).decode().strip()=='enabled'
for suffix in ['service','timer']:
    assert (Path('/etc/systemd/system')/f'jakawi-club-catalog.{suffix}').read_bytes()==(ROOT/'scripts/systemd'/f'jakawi-club-catalog.{suffix}').read_bytes()
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*a,**kw): return None
opener=build_opener(NoRedirect())
def http(url):
    try: return opener.open(Request(url),timeout=20)
    except HTTPError as error: return error
health={url:http(url).code for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']}
assert all(code==200 for code in health.values())
for path in ['/afiliados','/admin/ofertas-comerciales']:
    r=http('https://jakawi.club'+path)
    assert r.code==302 and r.headers['Location']=='https://jakawi.club/login'
# Never follow into COM or create a conversion.
r=http('https://jakawi.club/r/Ms8xvfMr?club_ref=PUBLIC_OVERRIDE&url=https://example.test/')
assert state['pilot_state']=='active' and r.code==302 and r.headers['Location']==state['expected_location']
assert 'no-store' in r.headers['Cache-Control'] and not r.headers.get('Set-Cookie')
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
assert browser['live_assets'] and browser['no_live_mutations'] and all(c['no_overflow'] and c['history'] and c['clipboard'] and c['on_demand'] and c['admin_pause'] for c in browser['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,
'offers':state['offers'],'last_success':state['last_success'],'permanent_links':state['links'],
'health':health,'existing_Ms8xvfMr_preserved':True,'redirect_302_with_real_club_ref':True,'no_public_cookies':True,
'protected_tables_unchanged':True,'protected_source_unchanged':True,'web_db_not_restarted':True,'previous_chunks_preserved':True,
'catalog_timer_active_enabled':True,'sync_interval_minutes':10,'live_fixtures':0,
'mounted_source_files_verified':len(expected),'https_assets_verified':len(assets),
'isolated_tests':json.loads((OUT/'tests.json').read_text()),'browser':browser}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
print(json.dumps(result,indent=2,ensure_ascii=False))
