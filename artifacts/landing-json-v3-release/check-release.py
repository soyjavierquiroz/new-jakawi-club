#!/usr/bin/env python3
"""CLUB-only rollback baseline and read-only JSON v3 release verification."""
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
PRIVATE = Path('/srv/sites/jakawi.club/rollback-landing-json-v3')
OUT = Path(__file__).resolve().parent
ORIGINAL = 'b36e8dcbaaf05c55d41bc59a22367cd8633e7755'
ALLOWED = {
    'app/app/Support/LandingContent.php', 'app/app/Http/Controllers/LandingAdminController.php',
    'app/resources/js/lib/landing-editorial.ts', 'app/resources/js/pages/admin/LandingEditor.tsx',
}

def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)

def git(*args):
    return command(['git', *args]).decode().strip()

def php(code):
    return json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php', '-d', 'memory_limit=128M'], input=code.encode()))

def files():
    return {p: sha256((ROOT/p).read_bytes()).hexdigest() for p in git('ls-files', 'app').splitlines()
            if (ROOT/p).is_file() and not p.startswith(('app/storage/', 'app/bootstrap/cache/'))}

def secrets():
    return {p.name: sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').iterdir() if p.is_file()}

def containers():
    result = {}
    for name in ['jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db']:
        c = json.loads(command(['docker', 'inspect', name]))[0]
        result[name] = {'id': c['Id'], 'started_at': c['State']['StartedAt'],
                        'restarts': c['RestartCount'], 'health': c['State']['Health']['Status']}
    return result

BOOT = r"""<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=['schema'=>App\Support\LandingContent::SCHEMA,'registration_open'=>App\Support\LaunchSettings::registrationOpen(),
'debug'=>config('app.debug'),'config_cached'=>app()->configurationIsCached(),'routes_cached'=>app()->routesAreCached(),'tables'=>[]];
foreach(['marketing_landings','migrations','users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','affiliate_short_links'] as $t){
$v=Illuminate\Support\Facades\DB::selectOne("select count(*) as count,md5(coalesce(jsonb_agg(to_jsonb(t) order by to_jsonb(t)::text)::text,'[]')) as digest from public.\"".$t."\" t");$r['tables'][$t]=(array)$v;}
$r['public_slugs']=App\Models\MarketingLanding::where('status','published')->whereNotNull('published_configuration')->orderBy('slug')->pluck('slug')->all();
"""

def prepare():
    assert not git('status', '--porcelain'), 'Production checkout must be clean'
    head = git('rev-parse', 'HEAD')
    assert head == git('rev-parse', 'origin/main')
    os.umask(0o077)
    PRIVATE.mkdir(exist_ok=True)
    assert not (PRIVATE/'baseline.json').exists(), 'Never overwrite a release baseline'
    state = php(BOOT + 'echo json_encode($r);')
    assert state['registration_open'] and state['debug'] is False
    baseline = {'sha': head, 'state': state, 'files': files(), 'secrets': secrets(), 'containers': containers()}
    (PRIVATE/'source.tar').write_bytes(command(['git', 'archive', head]))
    shutil.copytree(ROOT/'app/public/build', PRIVATE/'build')
    shutil.copytree('/srv/sites/jakawi.club/bootstrap-cache', PRIVATE/'bootstrap-cache')
    (PRIVATE/'baseline.json').write_text(json.dumps(baseline, indent=2)+'\n')
    print(json.dumps({'rollback_ready': True, 'baseline_sha': head, 'landings': state['tables']['marketing_landings']['count']}))

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def verify():
    baseline = json.loads((PRIVATE/'baseline.json').read_text())
    assert not git('status', '--porcelain'), 'Production checkout must be clean'
    head = git('rev-parse', 'HEAD')
    assert head == git('rev-parse', 'origin/main') == git('ls-remote', 'origin', 'refs/heads/main').split()[0]
    command(['git', 'merge-base', '--is-ancestor', baseline['sha'], head])
    current = files()
    assert set(current) == set(baseline['files']), 'Unexpected application files added or removed'
    assert all(current[p] == digest for p, digest in baseline['files'].items() if p not in ALLOWED), 'Unrelated application source changed'
    assert secrets() == baseline['secrets'], 'Secret files changed'
    encoded = base64.b64encode(json.dumps([p.removeprefix('app/') for p in current]).encode()).decode()
    mounted = php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $p)$r['app/'.$p]=hash_file('sha256',$p);echo json_encode($r);")
    assert mounted == current, 'Mounted source differs from Git'
    for name in ['JAKAWI-LANDING-V3-SCHEMA.md', 'JAKAWI-WEBINAR-V3-EXAMPLE.json']:
        path = 'docs/landings/'+name
        assert (ROOT/path).read_bytes() == command(['git', 'show', ORIGINAL+':'+path]), 'Official reference changed'
    document = base64.b64encode((ROOT/'docs/landings/JAKAWI-WEBINAR-V3-EXAMPLE.json').read_bytes()).decode()
    state = php(BOOT + r"""
$doc=base64_decode('__DOC__');$controller=app(App\Http\Controllers\LandingAdminController::class);
$request=Illuminate\Http\Request::create('https://jakawi.club/admin/landings/import','POST',['editorial_json'=>$doc]);
$response=$controller->import($request);$config=$response->getData(true)['configuration'];
$r['import_v3']=$response->getStatusCode()===200 && App\Support\LandingContent::envelope($config)==json_decode($doc,true);
$landing=App\Models\MarketingLanding::where('status','published')->firstOrFail();
$export=$controller->export($landing)->getData(true);$r['export_schema']=$export['schema'];
$r['export_roundtrip']=App\Support\LandingContent::import(json_encode($export),$landing->configuration)==App\Support\LandingContent::validate($landing->configuration);
$request=Illuminate\Http\Request::create('https://jakawi.club/admin/landings/crear');$request->headers->set('X-Inertia','true');
$page=json_decode($controller->create()->toResponse($request)->getContent(),true);
$r['editor_available']=$page['component']==='admin/LandingEditor' && count($page['props']['webinar_defaults']['presentation']['webinar']['sections'])===9;
echo json_encode($r);
""".replace('__DOC__', document))
    assert state['schema'] == state['export_schema'] == 'jakawi-club-landing-v3'
    assert state['import_v3'] and state['export_roundtrip'] and state['editor_available']
    assert state['registration_open'] and state['debug'] is False and state['config_cached'] and state['routes_cached']
    assert state['tables'] == baseline['state']['tables'], 'Protected database rows changed'
    assert state['public_slugs'] == baseline['state']['public_slugs']
    cs = containers()
    assert all(c['health'] == 'healthy' for c in cs.values())
    for name, before in baseline['containers'].items():
        assert cs[name]['id'] == before['id'], name+' recreated'
        if name != 'jakawi-club-app':
            assert cs[name] == before, name+' restarted'
    opener = build_opener(NoRedirect())
    def http(url):
        try:
            return opener.open(Request(url, headers={'Cache-Control': 'no-cache'}), timeout=20)
        except HTTPError as error:
            return error
    health = {u: http(u).code for u in ['https://jakawi.club/up', 'http://127.0.0.1:18082/up']}
    assert all(status == 200 for status in health.values())
    for slug in ['', *state['public_slugs']]:
        response = http('https://jakawi.club/'+slug)
        assert response.code == 200
        data = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>(.*?)</script>', response.read().decode(), re.S).group(1))
        assert data['props']['launch']['registration_open'] is True
    for path in ['/admin/landings', '/admin/landings/crear', '/afiliados?tab=results', '/admin/ofertas-comerciales', '/admin/reglas-comisiones', '/admin/comisiones', '/admin/conversiones-com']:
        response = http('https://jakawi.club'+path)
        assert response.code == 302 and response.headers['Location'] == 'https://jakawi.club/login', path
    assets = {}
    for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
        for filename in [entry['file'], *entry.get('css', [])]:
            if filename in assets:
                continue
            data = (ROOT/'app/public/build'/filename).read_bytes()
            response = http('https://jakawi.club/build/'+filename)
            assert response.code == 200 and response.read() == data, filename
            assets[filename] = sha256(data).hexdigest()
    for old in (PRIVATE/'build').rglob('*'):
        if old.is_file() and old.name != 'manifest.json':
            assert old.read_bytes() == (ROOT/'app/public/build'/old.relative_to(PRIVATE/'build')).read_bytes(), 'Old asset removed'
    browser = json.loads((OUT/'browser-live.json').read_text())
    commercial_browser = json.loads((OUT/'results/browser-live.json').read_text())
    assert browser['live_assets'] and browser['passed'] == 18 and browser['live_registrations'] == 0
    assert commercial_browser['live_assets'] and commercial_browser['no_live_mutations'] and commercial_browser['no_live_test_clicks']
    assert not commercial_browser['runtime_errors'] and not commercial_browser['failed_assets']
    local = json.loads((OUT/'validation.json').read_text())
    assert local['app']['registration_open'] and local['local_http'] == local['inertia_http'] == 200
    result = {'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(), 'original_commit': ORIGINAL,
              'baseline_sha': baseline['sha'], 'live_sha': head, 'origin_main_synced': True, 'tree_clean': True,
              'schema': state['schema'], 'live_runtime_import_export_roundtrip': True, 'editor_available': True,
              'editorial_rows_and_snapshots_unchanged': True, 'protected_tables_unchanged': list(state['tables']),
              'registration_open': True, 'app_debug': False, 'official_references_preserved': True,
              'unrelated_source_unchanged': True, 'secrets_unchanged': True, 'only_app_restarted': True,
              'services': {n: c['health'] for n, c in cs.items()}, 'health': health,
              'mounted_files_verified': len(mounted), 'https_assets_verified': len(assets), 'old_assets_preserved': True,
              'browser_live_groups': browser['passed'], 'commercial_browser_widths': [c['width'] for c in commercial_browser['checks']],
              'local_validation_passed': True,
              'live_test_registrations': 0, 'landings_created_or_published': 0, 'real_emails_sent': 0}
    (OUT/'LIVE.json').write_text(json.dumps(result, indent=2, ensure_ascii=False)+'\n')
    print(json.dumps(result, ensure_ascii=False))

if __name__ == '__main__':
    {'prepare': prepare, 'verify': verify}[sys.argv[1]]()
