#!/usr/bin/env python3
"""Verify this checkout, mounted application, served build and isolated deployment."""
from datetime import datetime
from hashlib import sha1, sha256
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import unquote
from urllib.request import Request, build_opener, HTTPCookieProcessor, urlopen
from zoneinfo import ZoneInfo
import json
import re
import subprocess

ROOT = Path('/opt/sites/jakawi.club')

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
    # Persistent Docker volumes cover these checkout placeholders at runtime.
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
assert page['component'] == 'Welcome'
assert page['props']['landing']['slug'] == 'principal' and not page['props']['landing']['preview']
assert page['props']['launch']['registration_open'] is False
with urlopen('https://jakawi.club/l/principal', timeout=20) as response:
    public_page = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>', response.read().decode())[1])
    assert public_page['props']['landing']['content'] == page['props']['landing']['content']
xsrf = next(unquote(cookie.value) for cookie in cookies if cookie.name == 'XSRF-TOKEN')
try:
    client.open(Request('https://jakawi.club/registro', data=b'{}', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrf,
    }), timeout=20)
    raise AssertionError('Registration must stay closed')
except HTTPError as error:
    assert error.code == 403
protected = {}
for path in ['/admin/landings', '/admin/landings/1/preview', '/admin/landings/1/export']:
    try:
        client.open(Request('https://jakawi.club'+path, headers={'Accept':'application/json'}), timeout=20)
        raise AssertionError('Administrative authentication required')
    except HTTPError as error:
        assert error.code == 401
        protected[path] = error.code

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
health = {}
for domain in ['jakawi.club', 'jakawi.com']:
    with urlopen('https://' + domain + '/up', timeout=20) as response:
        assert response.status == 200
        health[domain] = response.status
baseline = json.loads((ROOT / 'artifacts/landings/baseline.json').read_text())
containers = {}
for item in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
    name = item['Name']
    assert item['Id'] == baseline['containers'][name]['id']
    assert item['State']['Health']['Status'] == 'healthy'
    if name != '/jakawi-club-app':
        assert item['State']['StartedAt'] == baseline['containers'][name]['started']
    if name == '/jakawi-club-db':
        assert not item['HostConfig']['PortBindings']
    containers[name] = {'id': item['Id'], 'health': 'healthy'}
state = php(r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$before=json_decode(file_get_contents('storage/app/landings-users-baseline.json'),true);
$users=DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by id), '')) as digest from users u");
$home=App\Models\MarketingLanding::where('slug',config('jakawi.home_landing_slug'))->firstOrFail();
echo json_encode([
 'database'=>DB::selectOne('select current_database() as name')->name,
 'debug'=>config('app.debug'), 'registration_open'=>App\Support\LaunchSettings::registrationOpen(),
 'users_unchanged'=>$before['count']==$users->count && $before['digest']===$users->digest,
 'user_count'=>$users->count, 'home_public'=>$home->isPublic(),
 'home_matches_draft'=>$home->configuration===$home->published_configuration,
 'configuration_type'=>DB::selectOne("select data_type from information_schema.columns where table_schema='public' and table_name='marketing_landings' and column_name='configuration'")->data_type,
 'migrations'=>DB::table('migrations')->count(),
]);''')
assert state['database'] == 'jakawi_club' and state['debug'] is False and state['registration_open'] is False
assert state['users_unchanged'] and state['home_public'] and state['home_matches_draft']
assert state['configuration_type'] == 'jsonb' and state['migrations'] == 6
report = {
    'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'git_sha': sha, 'live_sha': sha, 'branch': 'main', 'working_tree_clean': True, 'origin_main_synced': True,
    'mounted_git_blobs_match': True, 'mounted_files': len(files), 'served_assets_match': True,
    'assets': assets, 'health': health, 'containers': containers, 'state': state,
    'administrative_guest_access': protected, 'public_registration_http': 403,
    'home_http': 200, 'public_landing_http': 200,
}
(ROOT / 'artifacts/landings/LIVE.json').write_text(json.dumps(report, indent=2, ensure_ascii=False)+'\n')
print(json.dumps({key:report[key] for key in ['git_sha','working_tree_clean','origin_main_synced','mounted_git_blobs_match','served_assets_match','health','state','public_registration_http']}, indent=2))
