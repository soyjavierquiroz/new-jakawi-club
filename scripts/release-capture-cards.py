#!/usr/bin/env python3
"""Focused CLUB-only baseline, asset publication and LIVE SHA verification."""
from datetime import datetime
from hashlib import sha256
from pathlib import Path
from urllib.request import urlopen
from zoneinfo import ZoneInfo
import json
import os
import shutil
import subprocess
import sys

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-capture-cards')
OUT = ROOT / 'artifacts/capture-cards'
SOURCES = [
    'app/app/Http/Controllers/LandingAdminController.php', 'app/app/Http/Controllers/WelcomeController.php',
    'app/app/Services/CommercialCatalog.php', 'app/app/Support/LandingContent.php', 'app/app/Support/LandingCommercial.php',
    'app/resources/js/components/CatalogOffer.tsx', 'app/resources/js/components/ClubCardPresentation.tsx',
    'app/resources/js/pages/Welcome.tsx', 'app/resources/js/pages/admin/LandingEditor.tsx',
    'app/resources/js/types.ts', 'app/resources/views/app.blade.php', 'app/public/marketing/club-card.png',
]
UNCHANGED = ['app/routes/web.php', 'app/app/Services/AffiliateShortLinks.php', 'app/app/Services/ReferralAttribution.php']


def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)


def php(code):
    return json.loads(cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=('<?php '+code).encode()))


def state():
    return php("require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
               "$out=[];foreach(['marketing_landings','affiliate_short_links'] as $t){$out[$t]=hash('sha256',Illuminate\\Support\\Facades\\DB::table($t)->orderBy($t==='affiliate_short_links'?'code':'id')->get()->toJson());}"
               "$rows=Illuminate\\Support\\Facades\\DB::table('commercial_offers')->where('source','com')->orderBy('key')->get()->map(function($row){unset($row->last_synced_at,$row->updated_at);return $row;});"
               "$out['com_offers']=hash('sha256',$rows->toJson());echo json_encode($out);")


def digest(path):
    return sha256(path.read_bytes()).hexdigest()


mode = sys.argv[1]
if mode == 'baseline':
    PRIVATE.mkdir(mode=0o700, exist_ok=False)
    base = {'sha': cmd(['git', 'rev-parse', 'HEAD']).decode().strip(), 'state': state(),
            'environment_sha256': digest(Path('/srv/sites/jakawi.club/secrets/app.env')),
            'unchanged': {f: digest(ROOT / f) for f in UNCHANGED}, 'containers': {}}
    for c in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
        base['containers'][c['Name']] = {'id': c['Id'], 'started_at': c['State']['StartedAt']}
    for name in SOURCES:
        source = ROOT / name
        if source.exists():
            target = PRIVATE / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
    shutil.copytree(ROOT / 'app/public/build', PRIVATE / 'build')
    (PRIVATE / 'baseline.json').write_text(json.dumps(base, indent=2)+'\n')
    print('CLUB baseline and rollback files ready.')
elif mode == 'deploy':
    assert (PRIVATE / 'baseline.json').is_file()
    checks = json.loads((OUT / 'browser-preview.json').read_text())
    assert checks['no_live_mutations'] and len(checks['checks']) == 2
    assert json.loads((OUT / 'tests.json').read_text())['live_fixtures'] == 0
    public = ROOT / 'app/public'
    for source in (public / 'build-next').rglob('*'):
        if not source.is_file() or source.name == 'manifest.json':
            continue
        target = public / 'build' / source.relative_to(public / 'build-next')
        target.parent.mkdir(parents=True, exist_ok=True)
        if target.exists():
            assert source.read_bytes() == target.read_bytes(), 'Asset hash collision'
        else:
            shutil.copy2(source, target)
    shutil.copy2(public / 'build-next/manifest.json', public / 'build/manifest.capture-next.json')
    os.replace(public / 'build/manifest.capture-next.json', public / 'build/manifest.json')
    php("require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();app(App\\Services\\CommercialCatalog::class)->syncClub();echo 'true';")
    cmd(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php', 'artisan', 'view:cache'])
    cmd(['docker', 'compose', 'restart', 'app'])
    print('CLUB assets, published catalog presentation and app deployed.')
elif mode == 'verify':
    base = json.loads((PRIVATE / 'baseline.json').read_text())
    sha = cmd(['git', 'rev-parse', 'HEAD']).decode().strip()
    assert not cmd(['git', 'status', '--porcelain'])
    assert sha == cmd(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
    assert state() == base['state'], 'Landings, existing short links and COM offers must remain intact'
    assert digest(Path('/srv/sites/jakawi.club/secrets/app.env')) == base['environment_sha256']
    assert {f: digest(ROOT / f) for f in UNCHANGED} == base['unchanged']
    for c in json.loads(cmd(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
        assert c['Id'] == base['containers'][c['Name']]['id']
        assert c['State']['Health']['Status'] == 'healthy'
        if c['Name'] != '/jakawi-club-app':
            assert c['State']['StartedAt'] == base['containers'][c['Name']]['started_at']
    source_hashes = {f.removeprefix('app/'): digest(ROOT / f) for f in SOURCES}
    mounted = php('$out=[];foreach('+json.dumps(list(source_hashes))+" as $f){$out[$f]=hash_file('sha256',$f);}echo json_encode($out);")
    assert mounted == source_hashes
    for old in (PRIVATE / 'build').rglob('*'):
        if old.is_file() and old.name != 'manifest.json':
            assert old.read_bytes() == (ROOT / 'app/public/build' / old.relative_to(PRIVATE / 'build')).read_bytes()
    health = {}
    for url in ['https://jakawi.club/up', 'http://127.0.0.1:18082/up']:
        with urlopen(url, timeout=20) as response:
            assert response.status == 200
            health[url] = response.status
    with urlopen('https://jakawi.club/', timeout=20) as response:
        html = response.read().decode()
    assert 'property="og:title"' in html and 'property="og:image"' in html
    manifest = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
    assets = set()
    for entry in manifest.values():
        if entry.get('isEntry'):
            assert '/build/'+entry['file'] in html
        assets.update([entry['file'], *entry.get('css', []), *entry.get('assets', [])])
    for asset in assets:
        with urlopen('https://jakawi.club/build/'+asset, timeout=20) as response:
            assert response.read() == (ROOT / 'app/public/build' / asset).read_bytes()
    with urlopen('https://jakawi.club/marketing/club-card.png', timeout=20) as response:
        assert response.read() == (ROOT / 'app/public/marketing/club-card.png').read_bytes()
    browser = json.loads((OUT / 'browser-live.json').read_text())
    assert browser['live_assets'] and browser['no_live_mutations'] and not browser['runtime_errors'] and not browser['failed_assets']
    result = {'live_sha': sha, 'origin_main_synced': True, 'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
              'health': health, 'https_assets_verified': len(assets), 'mounted_sources_verified': len(source_hashes),
              'published_snapshots_unchanged': True, 'short_links_unchanged': True, 'com_offers_unchanged': True,
              'environment_unchanged': True, 'club_only_app_restart': True, 'viewports': [390,1440], 'live_fixtures': 0}
    (OUT / 'LIVE.json').write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps(result, indent=2))
else:
    raise SystemExit('Usage: release-capture-cards.py baseline|deploy|verify')
