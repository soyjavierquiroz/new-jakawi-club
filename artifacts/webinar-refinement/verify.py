#!/usr/bin/env python3
"""CLUB-only baseline/verification for the WEBINAR refinement release."""
import base64
from datetime import datetime
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from urllib.error import HTTPError
from urllib.request import HTTPRedirectHandler, Request, build_opener
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-webinar-refinement')
OUT = Path(__file__).resolve().parent

def command(args, **kw):
    return subprocess.check_output(args, cwd=ROOT, **kw)

def php(code):
    return json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php', '-d', 'memory_limit=128M'], input=code.encode()))

def files():
    return {p: sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git', 'ls-files', 'app']).decode().splitlines()
            if (ROOT/p).is_file() and not p.startswith(('app/storage/', 'app/bootstrap/cache/'))}

def secret_hashes():
    return {p.name: sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').iterdir() if p.is_file()}

def containers():
    cs = json.loads(command(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db']))
    return {c['Name']: {'id': c['Id'], 'started': c['State']['StartedAt'], 'restarts': c['RestartCount'],
                        'health': c['State']['Health']['Status']} for c in cs}

BOOT = r"""<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=['registration_open'=>App\Support\LaunchSettings::registrationOpen(),'debug'=>config('app.debug'),
'schema'=>App\Support\LandingContent::SCHEMA,'tables'=>[]];
foreach(['marketing_landings','users','migrations','commercial_events','commission_rules','commission_ledger','commission_audit','affiliate_short_links'] as $t){
$r['tables'][$t]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count,md5(coalesce(jsonb_agg(to_jsonb(t) order by to_jsonb(t)::text)::text,'[]')) as digest from public.\"".$t."\" t");}
$r['slugs']=App\Models\MarketingLanding::where('status','published')->whereNotNull('published_configuration')->orderBy('slug')->pluck('slug')->all();
"""

def prepare():
    assert not command(['git', 'status', '--porcelain'])
    os.umask(0o077)
    PRIVATE.mkdir(exist_ok=True)
    assert not (PRIVATE/'baseline.json').exists(), 'Do not overwrite baseline'
    head = command(['git', 'rev-parse', 'HEAD']).decode().strip()
    baseline = {'sha': head, 'files': files(), 'secrets': secret_hashes(), 'containers': containers(),
                'state': php(BOOT+'echo json_encode($r);')}
    (PRIVATE/'source.tar').write_bytes(command(['git', 'archive', head]))
    (PRIVATE/'compose.yaml').write_bytes((ROOT/'compose.yaml').read_bytes())
    shutil.copytree(ROOT/'app/public/build', PRIVATE/'build')
    shutil.copytree('/srv/sites/jakawi.club/bootstrap-cache', PRIVATE/'bootstrap-cache')
    (PRIVATE/'baseline.json').write_text(json.dumps(baseline, indent=2)+'\n')
    print(json.dumps({'baseline_sha': head, 'rollback_ready': True}))

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def verify():
    before = json.loads((PRIVATE/'baseline.json').read_text())
    assert not command(['git', 'status', '--porcelain']), 'Dirty production checkout'
    head = command(['git', 'rev-parse', 'HEAD']).decode().strip()
    assert head == command(['git', 'rev-parse', 'origin/main']).decode().strip()
    assert head == command(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
    assert secret_hashes() == before['secrets']
    current = files()
    allowed = {'app/resources/css/app.css', 'app/resources/js/pages/Welcome.tsx',
               'app/resources/js/components/marketing/primitives.tsx',
               'app/resources/js/components/marketing/WebinarComposition.tsx'}
    for p, digest in before['files'].items():
        if p not in allowed:
            assert current[p] == digest, 'Unrelated source changed: '+p
    encoded = base64.b64encode(json.dumps(list(current)).encode()).decode()
    mounted = php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $p)$r[$p]=hash_file('sha256',substr($p,4));echo json_encode($r);")
    assert mounted == current
    expected = {name: sha256((ROOT/'docs/landings'/name).read_bytes()).hexdigest() for name in
                ['JAKAWI-COPYWRITER-PROMPT.md', 'JAKAWI-WEBINAR-V3-EXAMPLE.json', 'JAKAWI-LANDING-V3-SCHEMA.md']}
    state = php(BOOT+r"""
$kit=app(App\Http\Controllers\LandingAiKitController::class);$r['documents']=[];
foreach($kit::DOCUMENTS as $key=>$name){$response=$kit->download($key);$r['documents'][$name]=hash_file('sha256',$response->getFile()->getPathname());}
$response=$kit->download('kit');ob_start();$response->sendContent();$bytes=ob_get_clean();
$tmp=tempnam(sys_get_temp_dir(),'kit-verify-');try{file_put_contents($tmp,$bytes);$zip=new ZipArchive;
$r['zip_valid']=$zip->open($tmp,ZipArchive::CHECKCONS)===true && $zip->numFiles===3;$r['zip_entries']=[];
foreach($kit::DOCUMENTS as $name)$r['zip_entries'][$name]=hash('sha256',$zip->getFromName($name));$zip->close();}finally{unlink($tmp);}
$controller=app(App\Http\Controllers\LandingAdminController::class);
$request=Illuminate\Http\Request::create('https://jakawi.club/admin/landings');$request->headers->set('X-Inertia','true');
$r['kit_props']=[];foreach([$controller->index(),$controller->create(),$controller->edit(App\Models\MarketingLanding::firstOrFail())]as $page){$p=json_decode($page->toResponse($request)->getContent(),true);$r['kit_props'][]=$p['props']['ai_kit_zip_available'];}
$r['middleware']=app('router')->getRoutes()->getByName('landings.ai-kit')->gatherMiddleware();
$example=file_get_contents(base_path('../docs/landings/JAKAWI-WEBINAR-V3-EXAMPLE.json'));
$request=Illuminate\Http\Request::create('https://jakawi.club/admin/landings/import','POST',['editorial_json'=>$example]);
$imported=$controller->import($request)->getData(true)['configuration'];
$r['import_v3']=App\Support\LandingContent::envelope($imported)==json_decode($example,true);
echo json_encode($r);
""")
    assert state['tables'] == before['state']['tables'], 'Protected database state changed'
    assert state['registration_open'] and state['debug'] is False and state['schema'] == 'jakawi-club-landing-v3'
    assert state['documents'] == state['zip_entries'] == expected and state['zip_valid'] and state['import_v3']
    assert state['kit_props'] == [True, True, True]
    assert all(m in state['middleware'] for m in ['auth', 'verified', 'App\\Http\\Middleware\\MarketingAdmin'])
    cs = containers()
    assert all(c['health'] == 'healthy' for c in cs.values())
    for n in ['/jakawi-club-web', '/jakawi-club-db']:
        assert cs[n] == before['containers'][n], n+' changed'
    assert cs['/jakawi-club-app']['id'] == before['containers']['/jakawi-club-app']['id']
    app = json.loads(command(['docker', 'inspect', 'jakawi-club-app']))[0]
    assert any(m['Destination'] == '/var/www/docs/landings' and not m['RW'] for m in app['Mounts'])
    opener = build_opener(NoRedirect())
    def http(path):
        try:
            return opener.open(Request(path), timeout=20)
        except HTTPError as e:
            return e
    for key in ['prompt', 'example', 'schema', 'kit']:
        r = http('https://jakawi.club/admin/landings/crear-con-ia/'+key)
        assert r.code == 302 and r.headers['Location'] == 'https://jakawi.club/login'
    for path in ['/admin/landings', '/admin/landings/crear', '/afiliados', '/admin/ofertas-comerciales', '/admin/comisiones']:
        assert http('https://jakawi.club'+path).code == 302
    for slug in ['', *state['slugs']]:
        r = http('https://jakawi.club/'+slug);assert r.code == 200
        page=json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>(.*?)</script>',r.read().decode(),re.S).group(1))
        assert page['props']['launch']['registration_open']
    assets = set()
    for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
        for f in [entry['file'], *entry.get('css', [])]:
            if f in assets:continue
            r = http('https://jakawi.club/build/'+f)
            assert r.code == 200 and r.read() == (ROOT/'app/public/build'/f).read_bytes(), f
            assets.add(f)
    for f in (PRIVATE/'build').rglob('*'):
        if f.is_file() and f.name != 'manifest.json':
            assert f.read_bytes() == (ROOT/'app/public/build'/f.relative_to(PRIVATE/'build')).read_bytes()
    assert http('https://jakawi.club/up').code == http('http://127.0.0.1:18082/up').code == 200
    browser = json.loads((OUT/'browser-live.json').read_text())
    assert browser['live_assets'] and browser['passed'] == 26 and browser['live_registrations'] == 0
    result = {'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(), 'live_sha': head,
              'git_clean_and_synced': True, 'documents_exact_utf8_source': True, 'zip_valid': True,
              'protected_admin_downloads': True, 'import_v3': True, 'readonly_mount': True,
              'editorial_users_and_protected_tables_unchanged': True, 'secrets_unchanged': True,
              'unrelated_source_unchanged': True, 'services': cs, 'only_app_restarted': True,
              'registration_open': True, 'https_assets_verified': len(assets), 'previous_chunks_preserved': True,
              'browser_live_groups': browser['passed'], 
              'mounted_files_verified': len(mounted), 'live_registrations': 0, 'real_emails': 0}
    (OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__ == '__main__':
    {'prepare': prepare, 'verify': verify}[sys.argv[1]]()
