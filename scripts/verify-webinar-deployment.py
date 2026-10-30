#!/usr/bin/env python3
"""Read-only deployment checks against a private CLUB baseline; never emit account data."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
import base64, json, subprocess

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-webinar-mvp')
OUT = ROOT/'artifacts/webinar-release'

def command(args, **kw):
    return subprocess.check_output(args, cwd=ROOT, **kw)

def php(source):
    return json.loads(command(['docker','exec','-i','jakawi-club-app','php'], input=source.encode()))

baseline = json.loads((PRIVATE/'baseline.json').read_text())
head = command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain']), 'Working tree must be clean'
assert head == command(['git','rev-parse','origin/main']).decode().strip()
assert head == command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
command(['git','merge-base','--is-ancestor',baseline['sha'],head])
allowed = {
    'app/app/Http/Controllers/LandingAdminController.php', 'app/app/Support/LandingContent.php',
    'app/resources/js/components/forms/RegistrationForm.tsx', 'app/resources/js/components/marketing/MobileStickyCTA.tsx',
    'app/resources/js/components/marketing/primitives.tsx', 'app/resources/js/components/marketing/WebinarComposition.tsx',
    'app/resources/js/lib/landing-editorial.ts', 'app/resources/js/pages/Welcome.tsx',
    'app/resources/js/pages/admin/LandingEditor.tsx', 'app/resources/js/types.ts',
}
for path, digest in baseline['files'].items():
    if path not in allowed:
        assert sha256((ROOT/path).read_bytes()).hexdigest() == digest, path
for name, digest in baseline['secrets'].items():
    assert sha256((Path('/srv/sites/jakawi.club/secrets')/name).read_bytes()).hexdigest() == digest
expected = {p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files','app']).decode().splitlines() if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded = base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $p)$r[$p]=hash_file('sha256',$p);echo json_encode($r);") == expected
state = php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=['registration_open'=>App\Support\LaunchSettings::registrationOpen(),'app_debug'=>config('app.debug'),'oidc_enabled'=>config('oidc.enabled'),'economic_cutoff'=>config('commissions.economic_cutoff'),'tables'=>[]];
foreach(['marketing_landings','users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','commercial_offers','affiliate_short_links','club_identity_handoffs','password_reset_tokens'] as $table){$rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$r['tables'][$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}
$r['config_cached']=app()->configurationIsCached();$r['routes_cached']=app()->routesAreCached();
$request=Illuminate\Http\Request::create('https://jakawi.club/admin/landings/crear');$request->headers->set('X-Inertia','true');
$page=json_decode(app(App\Http\Controllers\LandingAdminController::class)->create()->toResponse($request)->getContent(),true);
$r['webinar_editor_available']=$page['component']==='admin/LandingEditor' && $page['props']['webinar_defaults']['presentation']['template']==='WEBINAR' && count($page['props']['webinar_defaults']['presentation']['webinar']['sections'])===9;
$r['no_seed_date']=$page['props']['webinar_defaults']['presentation']['webinar']['starts_at']===null;
echo json_encode($r);''')
assert state['tables'] == baseline['state']['tables'], 'Protected database state changed'
assert state['registration_open'] is True and state['app_debug'] is False
assert state['config_cached'] and state['routes_cached'] and state['webinar_editor_available'] and state['no_seed_date']
assert state['economic_cutoff'] == baseline['state']['economic_cutoff']
assert state['oidc_enabled'] == baseline['state']['oidc_enabled']
containers_unchanged = []
for name, old in baseline['containers'].items():
    c = json.loads(command(['docker','inspect',name]))[0]
    assert c['Id'] == old['id'], name
    if name != 'jakawi-club-app':
        assert c['State']['StartedAt'] == old['started_at'] and c['RestartCount'] == old['restarts'], name
        containers_unchanged.append(name)
for name in ['jakawi-club-app','jakawi-club-web','jakawi-club-db']:
    c=json.loads(command(['docker','inspect',name]))[0]
    assert c['State']['Running'] and c['State']['Health']['Status']=='healthy', name

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*a,**kw): return None
opener = build_opener(NoRedirect())
def http(path):
    try: return opener.open(Request(path),timeout=20)
    except HTTPError as error: return error
health = {url:http(url).code for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']}
assert all(code == 200 for code in health.values())
for slug in baseline['state']['public_slugs']:
    assert http('https://jakawi.club/'+slug).code == 200
for path in ['/admin/landings','/admin/landings/crear','/afiliados','/admin/ofertas-comerciales','/admin/reglas-comisiones','/admin/comisiones','/admin/conversiones-com']:
    r=http('https://jakawi.club'+path)
    assert r.code==302 and r.headers['Location']=='https://jakawi.club/login', path
r=http('https://jakawi.club/auth/jakawi')
assert r.code==302 and r.headers['Location'].startswith('https://jakawi.com/oauth/authorize?')
from urllib.parse import parse_qs, urlparse
query=parse_qs(urlparse(r.headers['Location']).query)
assert query['redirect_uri']==['https://jakawi.club/auth/jakawi/callback'] and query['code_challenge_method']==['S256']
assert query.get('state') and query.get('nonce') and query.get('code_challenge')
r=http('https://jakawi.club/auth/jakawi/callback?state=invalid&code=invalid')
assert r.code==302 and r.headers['Location']=='https://jakawi.club/login'
assets={}
manifest=json.loads((ROOT/'app/public/build/manifest.json').read_text())
for entry in manifest.values():
    for file in [entry['file'],*entry.get('css',[])]:
        if file in assets: continue
        response=http('https://jakawi.club/build/'+file)
        data=(ROOT/'app/public/build'/file).read_bytes()
        assert response.code==200 and response.read()==data, file
        assets[file]=sha256(data).hexdigest()
assert b'Usar contenido inicial WEBINAR' in (ROOT/'app/public/build'/manifest['resources/js/pages/admin/LandingEditor.tsx']['file']).read_bytes()
for file in (PRIVATE/'build').rglob('*'):
    if file.is_file() and file.name!='manifest.json':
        assert file.read_bytes()==(ROOT/'app/public/build'/file.relative_to(PRIVATE/'build')).read_bytes()
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'baseline_live_sha':baseline['sha'],'live_sha':head,'origin_main_synced':True,'tree_clean':True,'mounted_files_verified':len(expected),'https_assets_verified':len(assets),'previous_chunks_preserved':True,'only_app_restarted':True,'other_containers_unchanged':containers_unchanged,'secret_files_unchanged':True,'protected_sources_unchanged':True,'editorial_rows_and_snapshots_unchanged':True,'landing_count':state['tables']['marketing_landings']['count'],'protected_database_tables_unchanged':list(state['tables']),'registration_open':True,'app_debug':False,'webinar_editor_available':True,'sso_start_pkce_and_invalid_callback_verified':True,'sso_real_provider_login_tested':False,'health':health}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps({k:v for k,v in result.items() if k!='other_containers_unchanged'},indent=2))
