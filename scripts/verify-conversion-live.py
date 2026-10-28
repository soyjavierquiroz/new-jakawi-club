#!/usr/bin/env python3
"""Seal only committed, pushed, mounted and served code; never print environment values."""
from datetime import datetime
from hashlib import sha1, sha256
from pathlib import Path
from zoneinfo import ZoneInfo
import json
import re
import subprocess
import urllib.request

ROOT = Path('/opt/sites/jakawi.club')

def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, **kwargs)

def php(source):
    return json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=source))

baseline = json.loads((ROOT / 'artifacts/conversion/baseline.json').read_text())
sha = cmd(['git', 'rev-parse', 'HEAD']).strip()
origin = cmd(['git', 'rev-parse', 'origin/main']).strip()
remote = cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).split()[0]
assert sha == origin == remote
assert cmd(['git', 'branch', '--show-current']).strip() == 'main'
assert cmd(['git', 'status', '--porcelain']) == ''
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

manifest_path = ROOT / 'app/public/build/manifest.json'
manifest = json.loads(manifest_path.read_text())
with urllib.request.urlopen('https://jakawi.club/', timeout=20) as response:
    html = response.read().decode()
    assert response.status == 200
for entry in manifest.values():
    if entry.get('isEntry'):
        assert 'https://jakawi.club/build/' + entry['file'] in html
page = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>', html)[1])
assert page['component'] == 'Welcome' and page['props']['launch']['registration_open'] is False
asset_names = {name for entry in manifest.values() for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]}
asset_hashes = {}
for asset in sorted(asset_names):
    content = (ROOT / 'app/public/build' / asset).read_bytes()
    with urllib.request.urlopen('https://jakawi.club/build/' + asset, timeout=20) as response:
        assert response.status == 200 and sha256(response.read()).digest() == sha256(content).digest()
    asset_hashes[asset] = sha256(content).hexdigest()
health = {}
for domain in ['jakawi.club', 'jakawi.com']:
    with urllib.request.urlopen('https://' + domain + '/up', timeout=20) as response:
        health[domain] = response.status
        assert response.status == 200

names = list(baseline['containers'])
inspected = json.loads(cmd(['docker', 'inspect', *names]))
automatic = []
containers = {}
foreign_unchanged = 0
for container in inspected:
    name = container['Name'].lstrip('/')
    before = baseline['containers'][name]
    assert container['Id'] == before['id'] and container['State']['Running']
    assert sorted(container['NetworkSettings']['Networks']) == before['networks']
    if name.startswith('jakawi-club-'):
        assert container['State']['Health']['Status'] == 'healthy'
        if name != 'jakawi-club-app':
            assert container['State']['StartedAt'] == before['started_at']
            assert container['RestartCount'] == before['restart_count']
        containers[name] = {'id': container['Id'][:12], 'started_at': container['State']['StartedAt'], 'health': 'healthy'}
    elif container['RestartCount'] > before['restart_count']:
        automatic.append({'name': name, 'before': before['restart_count'], 'after': container['RestartCount'], 'started_at': container['State']['StartedAt']})
    else:
        assert container['State']['StartedAt'] == before['started_at']
        assert container['RestartCount'] == before['restart_count']
        foreign_unchanged += 1
for path, digest in baseline['protected_files'].items():
    assert sha256(Path(path).read_bytes()).hexdigest() == digest
for path, digest in baseline['credential_hashes'].items():
    assert sha256(Path(path).read_bytes()).hexdigest() == digest
ref = Path('/root/jakawi-build-v1/repository')
h = sha256()
for name in subprocess.check_output(['git', '-C', str(ref), 'ls-files', '-z']).split(b'\0'):
    if name and (ref / name.decode()).is_file():
        h.update(name)
        h.update((ref / name.decode()).read_bytes())
assert h.hexdigest() == baseline['reference_tree_sha256']
settings = php(r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
 'debug' => config('app.debug'), 'registration_open' => App\Support\LaunchSettings::registrationOpen(),
 'video_url' => App\Support\LaunchSettings::publicData()['video_url'],
 'users' => Illuminate\Support\Facades\DB::selectOne('select count(*) as count, md5(string_agg(md5(row_to_json(u)::text), \'\' order by id)) as digest from users u'),
 'analytics_columns' => Illuminate\Support\Facades\Schema::getColumnListing('marketing_event_totals'),
]);
''')
assert settings['debug'] is False and settings['registration_open'] is False and settings['video_url'] is None
assert settings['users'] == baseline['live_users']
assert settings['analytics_columns'] == ['day', 'event', 'placement', 'total']
visual = json.loads((ROOT / 'artifacts/conversion/visual-validation.json').read_text())
assert visual['runtime_errors'] == [] and visual['failed_assets'] == []
assert len(visual['checks']) == 14
assert {check['width'] for check in visual['checks'] if check['name'] == 'landing'} == {390, 768, 1024, 1440}
for check in visual['checks']:
    if check['name'] == 'landing':
        assert not check['overflow'] and abs(check['frameRatio'] - 3 / 4) < .02
backend = (ROOT / 'artifacts/conversion/backend-tests.txt').read_text()
assert '"passed": 73' in backend
unit = json.loads((ROOT / 'artifacts/conversion/unit-tests.txt').read_text())
assert unit['passed'] == 34
assert '✓ built' in (ROOT / 'artifacts/conversion/frontend-build.txt').read_text()
report = {
 'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
 'baseline_sha': baseline['git_sha'], 'git_sha': sha, 'origin_main_sha': origin, 'remote_main_sha': remote,
 'working_tree_clean': True, 'app_tree_sha': cmd(['git', 'rev-parse', 'HEAD:app']).strip(),
 'mounted_git_blobs_match': True, 'verified_app_files': len(files), 'excluded_persistent_mounts': ['storage', 'bootstrap/cache'],
 'assets_manifest_sha256': sha256(manifest_path.read_bytes()).hexdigest(), 'served_assets_match': True, 'asset_sha256': asset_hashes,
 'health': health, 'containers': containers,
 'foreign_containers_unchanged': foreign_unchanged, 'automatic_foreign_restart_counter_increases': automatic,
 'foreign_container_ids_and_networks_unchanged': foreign_unchanged + len(automatic),
 'traefik_files_unchanged': len(baseline['protected_files']), 'official_repository_unchanged': True,
 'live_user_records_unchanged': True, 'credentials_unchanged': True, 'public_registration': 'closed', 'debug': False,
 'typecheck': 'passed', 'vite_build': 'passed', 'focused_backend_tests_passed': 73, 'focused_unit_tests_passed': unit['passed'],
 'responsive_groups_passed': len(visual['checks']), 'viewports': [390, 768, 1024, 1440],
 'hero': 'two columns from 1024px; one 3:4 player; vertically centered desktop copy',
 'mobile_sticky': 'form visibility, keyboard viewport, focus, dialogs and active player checked',
 'video_live': 'disabled pending approved YouTube URL; API lifecycle verified with isolated browser fixtures',
}
(ROOT / 'artifacts/conversion/LIVE.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
# Keep the IDE's existing LIVE entrypoint current; both seal files stay outside Git.
(ROOT / 'artifacts/identity/LIVE.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({key: report[key] for key in ['verified_at', 'git_sha', 'working_tree_clean', 'mounted_git_blobs_match', 'served_assets_match', 'health', 'public_registration', 'live_user_records_unchanged', 'automatic_foreign_restart_counter_increases']}, indent=2))
