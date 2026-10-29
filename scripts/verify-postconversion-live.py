#!/usr/bin/env python3
"""Seal Git, mounted code, HTTPS assets, receipt guards and project isolation."""
from datetime import datetime
from hashlib import sha1, sha256
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import unquote
from urllib.request import Request, build_opener, HTTPCookieProcessor, HTTPRedirectHandler, urlopen
from zoneinfo import ZoneInfo
import json
import re
import subprocess

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-postconversion-c315fe625b40')
EVIDENCE = ROOT / 'artifacts/postconversion'

def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, **kwargs)

def php(source):
    return json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=source))

sha = cmd(['git', 'rev-parse', 'HEAD']).strip()
assert sha == cmd(['git', 'rev-parse', 'origin/main']).strip()
assert sha == cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).split()[0]
assert cmd(['git', 'status', '--porcelain']) == ''
assert cmd(['git', 'branch', '--show-current']).strip() == 'main'
files = {}
for record in cmd(['git', 'ls-tree', '-r', 'HEAD', 'app']).splitlines():
    meta, path = record.split('\t', 1)
    if path.startswith(('app/storage/', 'app/bootstrap/cache/')):
        continue
    content = (ROOT / path).read_bytes()
    assert sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() == meta.split()[2]
    files[path.removeprefix('app/')] = sha256(content).hexdigest()
paths = json.dumps(list(files))
mounted = php("<?php $paths=json_decode('" + paths + "',true); $result=[]; foreach($paths as $path){$result[$path]=hash_file('sha256',$path);} echo json_encode($result);")
assert mounted == files

cookies = CookieJar()
client = build_opener(HTTPCookieProcessor(cookies))
with client.open('https://jakawi.club/', timeout=20) as response:
    assert response.status == 200
    html = response.read().decode()
page = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>', html)[1])
assert page['component'] == 'Welcome' and page['props']['landing']['slug'] == 'principal'
assert page['props']['launch']['registration_open'] is False and not page['props']['landing']['preview']
with urlopen('https://jakawi.club/principal', timeout=20) as response:
    direct = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>', response.read().decode())[1])
    assert direct['props']['landing']['content'] == page['props']['landing']['content']
xsrf = next(unquote(cookie.value) for cookie in cookies if cookie.name == 'XSRF-TOKEN')
try:
    client.open(Request('https://jakawi.club/registro', data=b'{}', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrf,
    }), timeout=20)
    raise AssertionError('Registration must stay closed')
except HTTPError as error:
    assert error.code == 403
protected = {}
for path in ['/admin/landings', '/admin/landings/1/preview', '/admin/landings/1/preview/confirmado', '/admin/landings/1/export']:
    try:
        client.open(Request('https://jakawi.club'+path, headers={'Accept':'application/json'}), timeout=20)
        raise AssertionError('Administrative authentication required')
    except HTTPError as error:
        assert error.code == 401
        protected[path] = error.code

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

routes = {}
for path, expected, location in [
    ('/principal/confirmado', 302, '/principal'),
    ('/principal/confirmado?success=1&activation=confirmed', 302, '/principal'),
    ('/no-existe/confirmado', 404, None), ('/admin/confirmado', 404, None),
    ('/registro/confirmado', 404, None), ('/bienvenida', 302, '/'),
    ('/l/principal', 301, '/principal'), ('/l/no-existe', 404, None),
    ('/login', 200, None), ('/registro', 405, None), ('/up', 200, None),
]:
    try:
        with build_opener(NoRedirect).open('https://jakawi.club'+path, timeout=20) as response:
            status = response.status
    except HTTPError as error:
        status = error.code
        if location:
            assert error.headers['Location'] == 'https://jakawi.club'+location
    assert status == expected, (path, status)
    routes[path] = status

manifest = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
assets = {}
for entry in manifest.values():
    if entry.get('isEntry'):
        assert 'https://jakawi.club/build/' + entry['file'] in html
    for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]:
        if name in assets:
            continue
        content = (ROOT / 'app/public/build' / name).read_bytes()
        with urlopen('https://jakawi.club/build/' + name, timeout=20) as response:
            assert response.status == 200 and sha256(response.read()).digest() == sha256(content).digest()
        assets[name] = sha256(content).hexdigest()
for path in (PRIVATE/'build').rglob('*'):
    if path.is_file() and path.name != 'manifest.json':
        assert sha256(path.read_bytes()).digest() == sha256((ROOT/'app/public/build'/path.relative_to(PRIVATE/'build')).read_bytes()).digest()
health = {}
for domain in ['jakawi.club', 'jakawi.com']:
    with urlopen('https://' + domain + '/up', timeout=20) as response:
        assert response.status == 200
        health[domain] = response.status
with urlopen('http://127.0.0.1:18082/up', timeout=20) as response:
    assert response.status == 200
    health['local'] = response.status
baseline = json.loads((PRIVATE/'baseline.json').read_text())
current = {item['Name']:item for item in json.loads(cmd(['docker','inspect',*cmd(['docker','ps','-aq']).split()]))}
for name, before in baseline['containers'].items():
    item = current[name]
    assert item['Id'] == before['id']
    assert sorted(item['NetworkSettings']['Networks']) == before['networks']
    if name != '/jakawi-club-app':
        assert item['State']['StartedAt'] == before['started']
    if name.startswith('/jakawi-club-'):
        assert item['State']['Health']['Status'] == 'healthy'
    if name == '/jakawi-club-db':
        assert not item['HostConfig']['PortBindings']
for filename, digest in baseline['traefik'].items():
    assert sha256(Path(filename).read_bytes()).hexdigest() == digest
state = php(r'''<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s=[]; foreach(['users','marketing_landings','migrations','marketing_event_totals','marketing_landing_event_totals'] as $table){$s[$table]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from {$table} u");}
$m=config('mail.mailers.'.config('mail.default'));
echo json_encode(['tables'=>$s,'mailer'=>config('mail.default'),'smtp_remote_host_configured'=>!empty($m['host'])&&!in_array($m['host'],['localhost','127.0.0.1','::1']),'smtp_credentials_present'=>!empty($m['username'])&&!empty($m['password']),'debug'=>config('app.debug'),'registration_flag'=>config('jakawi.registration_open'),'transactional_mail_approved'=>config('jakawi.transactional_mail_ready'),'mail_ready'=>App\Support\LaunchSettings::mailReady(),'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'whatsapp_group_configured'=>!empty(config('jakawi.whatsapp_url')),
'published_slugs'=>App\Models\MarketingLanding::where('status','published')->pluck('slug')->all(),
'confirmation_route'=>app('router')->getRoutes()->getByName('registration.confirmed')?->uri(),
'preview_route'=>app('router')->getRoutes()->getByName('landings.confirmation-preview')?->uri(),
'reserved_confirmation'=>!App\Support\LandingSlug::allowed('confirmado'),
'privacy_version'=>config('jakawi.privacy_version'),
'isolated_test_schemas_remaining'=>(int)Illuminate\Support\Facades\DB::selectOne("select count(*) as total from pg_namespace where nspname like 'postconversion_test_%' or nspname like 'landings_test_%' or nspname like 'mvp_test_%'")->total]);''')
assert state.pop('tables') == baseline['state']['tables']
for key, expected in baseline['state'].items():
    if key != 'tables':
        assert state[key] == expected
assert state['mailer'] == 'log' and state['registration_open'] is False and state['debug'] is False
assert state['confirmation_route'] == '{slug}/confirmado' and state['preview_route'] == 'admin/landings/{landing}/preview/confirmado'
assert state['reserved_confirmation'] and state['isolated_test_schemas_remaining'] == 0
browser = json.loads((EVIDENCE/'live-browser.json').read_text())
editor = json.loads((EVIDENCE/'editor-visual.json').read_text())
for evidence in [browser,editor]:
    assert evidence['runtime_errors'] == [] and evidence['failed_assets'] == []
assert browser['viewports'] == [390,430,1440] and browser['real_registration_open'] is False
assert browser['no_live_accounts'] and browser['all_browser_mutations_intercepted'] and not browser['smtp_delivery_tested']
assert {x['width'] for x in editor['checks'] if x['name']=='real-confirmation-preview'} == {390,430,1440}
assert editor['preview_metrics'] == 0 and editor['live_test_users'] == 0
report = {
    'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'git_sha':sha,'live_sha':sha,'baseline_sha':baseline['git_sha'],'branch':'main',
    'working_tree_clean':True,'origin_main_synced':True,'mounted_git_blobs_match':True,
    'mounted_files':len(files),'served_assets_match':True,'previous_assets_preserved':True,'assets':assets,
    'health':health,'routes':routes,'administrative_guest_access':protected,'public_registration_http':403,
    'state':state,'users_landings_analytics_migrations_unchanged':True,
    'foreign_containers_unchanged':sum(not name.startswith('/jakawi-club-') for name in baseline['containers']),
    'traefik_files_unchanged':len(baseline['traefik']),'only_app_restarted':True,
    'browser_groups_passed':len(browser['checks']),'editor_groups_passed':len(editor['checks']),
    'smtp_delivery_tested':False,
}
(EVIDENCE/'LIVE.json').write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({key:report[key] for key in ['git_sha','live_sha','working_tree_clean','origin_main_synced','mounted_git_blobs_match','served_assets_match','health','public_registration_http','users_landings_analytics_migrations_unchanged','browser_groups_passed','editor_groups_passed','state']},indent=2))
