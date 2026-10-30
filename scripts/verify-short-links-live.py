#!/usr/bin/env python3
"""Verify only the 07A CLUB release. No sales, sessions or COM API calls."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, json, subprocess

ROOT = Path('/opt/sites/jakawi.club')
OUT = ROOT/'artifacts/short-links-07a'
PRIVATE = Path('/srv/sites/jakawi.club/rollback-short-links-07a')
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
$user=App\Models\User::whereNotNull('activated_at')->whereNotNull('email_verified_at')->orderByDesc('is_marketing_admin')->orderBy('id')->firstOrFail();
$link=Illuminate\Support\Facades\DB::table('affiliate_short_links')->where('affiliate_id',$user->id)->where('offer_key','dossis-43')->first();
$offer=Illuminate\Support\Facades\DB::table('commercial_offers')->where('key','dossis-43')->first();
$tables=[];foreach(['users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','jobs'] as $table){$rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}
echo json_encode(['app_debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached(),
'short_middleware'=>app('router')->getRoutes()->getByName('short-link')->gatherMiddleware(),
'admin_middleware'=>app('router')->getRoutes()->getByName('commercial-offers.index')->gatherMiddleware(),
'legacy_controller'=>app('router')->getRoutes()->getByName('referral')->getActionName(),
'migration_applied'=>Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_30_000004_create_affiliate_short_links')->exists(),
'offer_status'=>$offer->status,'offers'=>Illuminate\Support\Facades\DB::table('commercial_offers')->count(),
'links'=>Illuminate\Support\Facades\DB::table('affiliate_short_links')->count(),
'first_short_url'=>app(App\Services\AffiliateShortLinks::class)->url($link->code),
'expected_location'=>app(App\Services\AffiliateShortLinks::class)->destination($offer->destination_url,$user->referral_code),
'link_referral_correct'=>$link->referral_code===$user->referral_code,'tables'=>$tables]);''')
assert state['app_debug'] is False and state['routes_cached'] and state['config_cached'] and state['migration_applied']
assert state['short_middleware']==[] and state['link_referral_correct'] and state['offer_status']=='active' and state['offers']==1
assert all(x in state['admin_middleware'] for x in ['auth','verified','App\\Http\\Middleware\\MarketingAdmin'])
assert state['legacy_controller']=='App\\Http\\Controllers\\ReferralController@show'
baseline = json.loads((PRIVATE/'baseline.json').read_text())
assert state['tables']==baseline['tables'], 'Existing users, events, commissions and jobs changed'
for name in ['jakawi-club-web','jakawi-club-db']:
    old=baseline['containers'][name]
    c=json.loads(command(['docker','inspect',name]))[0]
    assert c['Id']==old['id'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts']
allowed = {'app/app/Http/Controllers/AffiliateController.php','app/bootstrap/app.php','app/resources/js/components/CommissionUI.tsx','app/resources/js/pages/Affiliates.tsx','app/resources/js/pages/Dashboard.tsx','app/resources/js/pages/admin/Landings.tsx','app/routes/console.php','app/routes/web.php','app/vite.config.ts','docker/nginx/nginx.conf'}
for file,digest in baseline['files'].items():
    if file not in allowed: assert sha256((ROOT/file).read_bytes()).hexdigest()==digest, file
for file,digest in baseline['env'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/file).read_bytes()).hexdigest()==digest
assert command(['docker','exec','jakawi-club-web','cat','/etc/nginx/nginx.conf'])==(ROOT/'docker/nginx/nginx.conf').read_bytes()
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
# Do not follow into COM: this is a redirect check, never a conversion.
r=http(state['first_short_url']+'?club_ref=PUBLIC_OVERRIDE&url=https://example.test/')
assert r.code==302 and r.headers['Location']==state['expected_location']
assert 'no-store' in r.headers['Cache-Control'] and not r.headers.get('Set-Cookie')
r=http('https://jakawi.club/r/Unknown8')
assert r.code==404 and not r.headers.get('Set-Cookie') and 'no-store' in r.headers['Cache-Control']
assert 'No encontramos este enlace' in r.read().decode()
log=Path('/srv/sites/jakawi.club/nginx-logs/access.log').read_text()
assert state['first_short_url'].removeprefix('https://jakawi.club') not in log
assert '/r/Unknown8' not in log
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
assert browser['live_assets'] and browser['no_live_mutations'] and all(c['no_overflow'] and c['paused'] and c['finished'] and c['history'] and c['clipboard'] and c['whatsapp'] and c['admin_update'] for c in browser['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,
'first_short_url':state['first_short_url'],'offer_status':state['offer_status'],'permanent_links':state['links'],
'health':health,'redirect_302':True,'attribution_exact':True,'public_parameters_ignored':True,'no_public_cookies':True,'no_short_link_access_logs':True,
'protected_tables_unchanged':True,'protected_source_unchanged':True,'web_db_not_restarted':True,'previous_chunks_preserved':True,
'mounted_source_files_verified':len(expected),'https_assets_verified':len(assets),
'isolated_tests':json.loads((OUT/'tests.json').read_text()),'browser':browser}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
