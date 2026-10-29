#!/usr/bin/env python3
"""Seal the focused CLUB funnel delivery: Git, mounted changes, HTTPS assets and health."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import urlopen
from zoneinfo import ZoneInfo
import json
import subprocess
import re

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-funnel-editor')
OUT = ROOT / 'artifacts/funnel-editor'


def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)


sha = cmd(['git', 'rev-parse', 'HEAD']).decode().strip()
assert cmd(['git', 'status', '--porcelain']) == b''
assert sha == cmd(['git', 'rev-parse', 'origin/main']).decode().strip()
assert sha == cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
base = json.loads((PRIVATE / 'baseline.json').read_text())
assert cmd(['git', 'rev-parse', 'stash@{0}']).decode().strip() == base['stash']
assert sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest() == base['environment_sha256']
containers = {}
for c in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
    old = base['containers'][c['Name']]
    assert c['State']['Health']['Status'] == 'healthy'
    assert c['Id'] == old['id']
    assert sorted(c['NetworkSettings']['Networks']) == old['networks']
    if c['Name'] != '/jakawi-club-app':
        assert c['State']['StartedAt'] == old['started_at']
    containers[c['Name']] = {'healthy': True, 'same_id': True}

changed = [f.removeprefix('app/') for f in cmd(['git', 'diff', '--name-only', base['sha'], 'HEAD', '--', 'app']).decode().splitlines()]
hashes = {f: sha256((ROOT / 'app' / f).read_bytes()).hexdigest() for f in changed}
php = "<?php $s=[];foreach(json_decode('" + json.dumps(changed) + "',true) as $f){$s[$f]=hash_file('sha256',$f);}echo json_encode($s);"
assert hashes == json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=php.encode()))

health = {}
for url in ['https://jakawi.club/up', 'https://jakawi.com/up', 'http://127.0.0.1:18082/up']:
    with urlopen(url, timeout=20) as response:
        assert response.status == 200
        health[url] = response.status
source = r"""<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$l=App\Models\MarketingLanding::where('slug','principal')->firstOrFail();echo json_encode(['published_video'=>$l->published_configuration['video']['youtube_url'],'show_video'=>$l->published_configuration['presentation']['show_video'],'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'mail_ready'=>App\Support\LaunchSettings::mailReady(),'debug'=>config('app.debug')]);"""
state = json.loads(cmd(['docker','compose','exec','-T','--user','www-data','app','php'], input=source.encode()))
assert state['registration_open'] and state['mail_ready'] and not state['debug']
pages = {}
for path in ['/', '/principal']:
    with urlopen('https://jakawi.club' + path, timeout=20) as response:
        html = response.read().decode()
        assert response.status == 200
    page = json.loads(re.search(r'<script[^>]+data-page="app"[^>]*>(.*?)</script>',html,re.S)[1])
    assert page['props']['launch']['video_url'] == state['published_video']
    assert page['props']['launch']['whatsapp_url'] is None
    assert not any(k in page['props']['landing']['content'] for k in ['whatsapp_group','confirmation_whatsapp_group'])
    assert page['props']['launch']['registration_open']
    pages[path] = {'video_url':page['props']['launch']['video_url'],'matches_published_snapshot':True,'whatsapp_destinations_absent':True}
for suffix in ['gracias','confirmado']:
    with urlopen('https://jakawi.club/principal/' + suffix, timeout=20) as response:
        assert response.status == 200 and response.url == 'https://jakawi.club/principal'
manifest = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
assets = {}
for entry in manifest.values():
    if entry.get('isEntry'):
        assert '/build/' + entry['file'] in html
    for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]:
        if name in assets:
            continue
        with urlopen('https://jakawi.club/build/' + name, timeout=20) as response:
            assert response.status == 200
            actual = response.read()
        assert actual == (ROOT / 'app/public/build' / name).read_bytes()
        assets[name] = sha256(actual).hexdigest()
for old in (PRIVATE / 'build').rglob('*'):
    if old.is_file() and old.name != 'manifest.json':
        assert old.read_bytes() == (ROOT / 'app/public/build' / old.relative_to(PRIVATE / 'build')).read_bytes()

browser = json.loads((OUT / 'live-browser.json').read_text())
assert browser['checks'] and all(c['passed'] for c in browser['checks'])
assert not browser['runtime_errors'] and not browser['failed_assets']
assert browser['no_live_accounts'] and browser['all_browser_mutations_intercepted']
result = {'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(), 'baseline_sha': base['sha'],
          'git_sha': sha, 'live_sha': sha, 'origin_main_synced': True, 'tree_clean': True,
          'mounted_changed_sources': hashes, 'https_assets': assets, 'previous_chunks_preserved': True,
          'health': health, 'containers': containers, 'environment_bytes_preserved': True, 'prior_stash_preserved': True,
          'browser_passed_groups': len(browser['checks']), 'viewports': browser['viewports'],
          'provider_test': browser['provider'], 'physical_playback_tested': False, 'pages': pages, 'registration_and_smtp_active': True, 'crm_sync': 'not activated; existing endpoint incompatible with CLUB UUID; independent credentials absent'}
(OUT / 'LIVE.json').write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({k: result[k] for k in ['git_sha', 'live_sha', 'origin_main_synced', 'tree_clean', 'health', 'browser_passed_groups']}, indent=2))
