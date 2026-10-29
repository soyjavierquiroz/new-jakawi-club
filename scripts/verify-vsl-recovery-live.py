#!/usr/bin/env python3
"""Seal this CLUB change; emit no environment values, cookies or account records."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import urlopen
from zoneinfo import ZoneInfo
import json
import re
import subprocess

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-vsl-recovery')
OUT = ROOT / 'artifacts/vsl-recovery'

def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)

sha = cmd(['git', 'rev-parse', 'HEAD']).decode().strip()
assert cmd(['git', 'status', '--porcelain']) == b''
assert sha == cmd(['git', 'rev-parse', 'origin/main']).decode().strip()
assert sha == cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
base = json.loads((PRIVATE / 'baseline.json').read_text())
assert cmd(['git', 'rev-parse', 'stash@{0}']).decode().strip() == base['stash']
env_before = (PRIVATE / 'app.env').read_bytes()
env_after = Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()
assert env_after == env_before.replace(b'JAKAWI_TRANSACTIONAL_MAIL_READY=false', b'JAKAWI_TRANSACTIONAL_MAIL_READY=true').replace(b'JAKAWI_REGISTRATION_OPEN=false', b'JAKAWI_REGISTRATION_OPEN=true')
containers = {}
for c in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
    old = base['containers'][c['Name']]
    assert c['State']['Health']['Status'] == 'healthy'
    assert sorted(c['NetworkSettings']['Networks']) == old['networks']
    if c['Name'] != '/jakawi-club-app':
        assert c['Id'] == old['id'] and c['State']['StartedAt'] == old['started_at']
    if c['Name'] == '/jakawi-club-db':
        assert not c['HostConfig']['PortBindings']
    containers[c['Name']] = {'healthy': True, 'same_id': c['Id'] == old['id'], 'networks_preserved': True}
source = r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$s=[];foreach(['users','marketing_landings','password_reset_tokens','migrations'] as $t){$s[$t]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from {$t} u");}$s['conversions']=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from marketing_event_totals u where event='registration_completed'");echo json_encode(['tables'=>$s,'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'mail_ready'=>App\Support\LaunchSettings::mailReady(),'debug'=>config('app.debug')]);'''
state = json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=source.encode()))
assert state['registration_open'] and state['mail_ready'] and not state['debug']
assert state['tables'] == json.loads((PRIVATE / 'database-before.json').read_text())
files = cmd(['git', 'ls-files', 'app']).decode().splitlines()
hashes = {f.removeprefix('app/'): sha256((ROOT / f).read_bytes()).hexdigest() for f in files if not f.startswith(('app/storage/', 'app/bootstrap/cache/'))}
php = "<?php $s=[];foreach(json_decode('" + json.dumps(list(hashes)) + "',true) as $f){$s[$f]=hash_file('sha256',$f);}echo json_encode($s);"
assert hashes == json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=php.encode()))
health = {}
for url in ['https://jakawi.club/up', 'https://jakawi.com/up', 'http://127.0.0.1:18082/up']:
    with urlopen(url, timeout=20) as r:
        assert r.status == 200
        health[url] = r.status
pages = {}
for path in ['/', '/principal']:
    with urlopen('https://jakawi.club' + path, timeout=20) as r:
        html = r.read().decode()
    p = json.loads(re.search(r'<script[^>]+data-page="app"[^>]*>(.*?)</script>', html, re.S)[1])
    assert p['props']['launch']['registration_open'] and p['props']['launch']['video_url'].endswith('P42gDOTL6Ys')
    pages[path] = {'registration_open': True, 'show_video': p['props']['landing']['content']['presentation']['show_video'], 'video_url': p['props']['launch']['video_url']}
assets = {}
for entry in json.loads((ROOT / 'app/public/build/manifest.json').read_text()).values():
    if entry.get('isEntry'):
        assert '/build/' + entry['file'] in html
    for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]:
        if name in assets:
            continue
        with urlopen('https://jakawi.club/build/' + name, timeout=20) as r:
            assert r.status == 200
            actual = r.read()
        assert actual == (ROOT / 'app/public/build' / name).read_bytes()
        assets[name] = sha256(actual).hexdigest()
for old in (PRIVATE / 'build').rglob('*'):
    if old.is_file() and old.name != 'manifest.json':
        assert old.read_bytes() == (ROOT / 'app/public/build' / old.relative_to(PRIVATE / 'build')).read_bytes()
real = json.loads((OUT / 'live-domains.json').read_text())
assert len(real['pages']) == 7
assert all(p['verdict']['mounted'] and p['verdict']['onReady'] and not p['runtime_errors'] for p in real['pages'])
result = {'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(), 'baseline_sha': base['sha'], 'git_sha': sha, 'live_sha': sha, 'origin_main_synced': True, 'tree_clean': True, 'mounted_code_files_verified': len(hashes), 'https_assets': assets, 'previous_chunks_preserved': True, 'health': health, 'pages': pages, 'containers': containers, 'environment_changes_only_two_authorized_flags': True, 'smtp_credentials_and_other_environment_bytes_preserved': True, 'accounts_permissions_tokens_landings_migrations_conversions_preserved': True, 'prior_stash_preserved': True, 'new_test_accounts': 0, 'new_test_emails': 0, 'real_provider_results': [{'url': p['url'], 'width': p['width'], **p['verdict']} for p in real['pages']], 'vsl_real_playback_resolved': all(p['verdict']['playing'] and p['verdict']['time_advances'] for p in real['pages'])}
(OUT / 'LIVE.json').write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({k: result[k] for k in ['git_sha', 'live_sha', 'origin_main_synced', 'tree_clean', 'health', 'vsl_real_playback_resolved']}, indent=2))
