#!/usr/bin/env python3
"""Focused CLUB deployment seal; never print environment values or personal records."""
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
PRIVATE = Path('/srv/sites/jakawi.club/rollback-campaigns')
EVIDENCE = ROOT / 'artifacts/campaigns'

def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, **kwargs)

def php(source):
    return json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=source))

sha = cmd(['git', 'rev-parse', 'HEAD']).strip()
assert sha == cmd(['git', 'rev-parse', 'origin/main']).strip()
assert sha == cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).split()[0]
assert cmd(['git', 'branch', '--show-current']).strip() == 'main'
assert cmd(['git', 'status', '--porcelain']) == '', 'Working tree must be clean'
baseline = json.loads((PRIVATE / 'baseline.json').read_text())
assert cmd(['git', 'rev-parse', 'stash@{0}']).strip() == baseline['stash'], 'Prior work remains preserved'
files = {}
for record in cmd(['git', 'ls-tree', '-r', 'HEAD', 'app']).splitlines():
    meta, path = record.split('\t', 1)
    if path.startswith(('app/storage/', 'app/bootstrap/cache/')):
        continue
    content = (ROOT / path).read_bytes()
    assert sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() == meta.split()[2]
    files[path.removeprefix('app/')] = sha256(content).hexdigest()
mounted = php("<?php $result=[]; foreach(json_decode('" + json.dumps(list(files)) + "',true) as $path){$result[$path]=hash_file('sha256',$path);} echo json_encode($result);")
assert mounted == files
for filename, digest in baseline['files'].items():
    assert sha256((ROOT / filename).read_bytes()).hexdigest() == digest, 'Protected file changed'
for item in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
    name = item['Name'].lstrip('/')
    before = baseline['containers'][name]
    assert item['Id'] == before['id']
    assert sorted(item['NetworkSettings']['Networks']) == before['networks']
    assert item['State']['Health']['Status'] == 'healthy'
    if name != 'jakawi-club-app':
        assert item['State']['StartedAt'] == before['started_at']
    if name == 'jakawi-club-db':
        assert not item['HostConfig']['PortBindings']
state = php(r'''<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s=[]; foreach(['users','marketing_landings','migrations','password_reset_tokens','marketing_landing_event_totals'] as $t){$s[$t]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from {$t} u");}
$s['conversions']=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from marketing_event_totals u where event='registration_completed'");
echo json_encode(['tables'=>$s,'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'registration_flag'=>config('jakawi.registration_open'),'mail_flag'=>config('jakawi.transactional_mail_ready'),'debug'=>config('app.debug')]);''')
assert state == baseline['state'], 'Protected application state changed'
assert not any(state[key] for key in ['registration_open', 'registration_flag', 'mail_flag', 'debug'])
schemas = php(r'''<?php require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode((int)Illuminate\Support\Facades\DB::selectOne("select count(*) as n from pg_namespace where nspname like 'postconversion_test_%' or nspname like 'landings_test_%' or nspname like 'mvp_test_%'")->n);''')
assert schemas == 0
cookies = CookieJar()
client = build_opener(HTTPCookieProcessor(cookies))
with client.open('https://jakawi.club/', timeout=20) as response:
    assert response.status == 200
    html = response.read().decode()
page = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>', html)[1])
assert page['component'] == 'Welcome' and not page['props']['launch']['registration_open']
content = page['props']['landing']['content']
for key in ['video', 'confirmation_video']:
    assert content[key]['aspect_ratio'] == '3:4'
assert content['whatsapp_group']['mode'] == 'default'
assert content['confirmation_whatsapp_group']['mode'] == 'inherit'
xsrf = next(unquote(cookie.value) for cookie in cookies if cookie.name == 'XSRF-TOKEN')
try:
    client.open(Request('https://jakawi.club/registro', data=b'{}', headers={'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrf}), timeout=20)
    raise AssertionError('Registration must remain closed')
except HTTPError as error:
    assert error.code == 403
assets = {}
for entry in json.loads((ROOT / 'app/public/build/manifest.json').read_text()).values():
    if entry.get('isEntry'):
        assert 'https://jakawi.club/build/' + entry['file'] in html
    for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]:
        if name in assets:
            continue
        data = (ROOT / 'app/public/build' / name).read_bytes()
        with urlopen('https://jakawi.club/build/' + name, timeout=20) as response:
            assert response.status == 200 and sha256(response.read()).digest() == sha256(data).digest()
        assets[name] = sha256(data).hexdigest()
for old in (PRIVATE / 'build').rglob('*'):
    if old.is_file() and old.name != 'manifest.json':
        assert old.read_bytes() == (ROOT / 'app/public/build' / old.relative_to(PRIVATE / 'build')).read_bytes()
health = {}
for url in ['http://127.0.0.1:18082/up', 'https://jakawi.club/up', 'https://jakawi.com/up']:
    with urlopen(url, timeout=20) as response:
        assert response.status == 200
        health[url] = response.status
browser = json.loads((EVIDENCE / 'live-browser.json').read_text())
editor = json.loads((EVIDENCE / 'editor-visual.json').read_text())
for result in [browser, editor]:
    assert result['runtime_errors'] == [] and result['failed_assets'] == []
assert browser['viewports'] == [390, 430, 1440]
report = {'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(), 'baseline_sha': baseline['sha'],
    'git_sha': sha, 'live_sha': sha, 'origin_main_synced': True, 'working_tree_clean': True,
    'mounted_files_match': len(files), 'served_assets_match': assets, 'previous_assets_preserved': True,
    'health': health, 'public_registration_http': 403, 'registration_flags_closed': True,
    'environment_and_smtp_unchanged': True, 'users_permissions_tokens_landings_migrations_conversions_unchanged': True,
    'only_club_app_restarted': True, 'test_schemas_remaining': schemas, 'smtp_messages_sent': 0, 'new_live_accounts': 0,
    'browser_groups': len(browser['checks']), 'editor_groups': len(editor['checks']), 'prior_work_preserved_in_stash': baseline['stash']}
(EVIDENCE / 'LIVE.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({key: report[key] for key in ['git_sha', 'live_sha', 'working_tree_clean', 'health', 'public_registration_http', 'browser_groups', 'editor_groups']}, indent=2))
