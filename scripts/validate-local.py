#!/usr/bin/env python3
"""Small, read-focused acceptance check; never serialize container environment."""
from datetime import datetime
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import subprocess
import urllib.error
import urllib.request
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')
subprocess.run(['docker', 'compose', 'config', '--quiet'], cwd=ROOT, check=True)

def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, **kwargs)

def get(url, binary=False, **headers):
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=20) as response:
        body = response.read()
        return response.status, body if binary else body.decode()

status, html = get('http://127.0.0.1:18082/')
assert status == 200 and 'JAKAWI Launch Engine' in html
assert 'id="app"' in html and '/build/assets/' in html

class PageParser(HTMLParser):
    active = False
    payload = ''

    def handle_starttag(self, tag, attrs):
        self.active = tag == 'script' and dict(attrs).get('data-page') == 'app'

    def handle_data(self, data):
        if self.active:
            self.payload += data

    def handle_endtag(self, tag):
        if tag == 'script':
            self.active = False

parser = PageParser()
parser.feed(html)
initial_page = json.loads(parser.payload)
status, payload = get('http://127.0.0.1:18082/', **{
    'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest',
    'X-Inertia-Version': initial_page['version'],
})
page = json.loads(payload)
assert status == 200 and page['component'] == 'Welcome'
assert isinstance(page['props']['launch']['registration_open'], bool)
assets = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
asset_checks = {}
for entry in assets.values():
    for file in [entry['file'], *entry.get('css', [])]:
        code, _ = get('http://127.0.0.1:18082/build/' + file, binary=True)
        assert code == 200
        asset_checks[file] = code

php = r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = (array) Illuminate\Support\Facades\DB::selectOne('select current_database() as database, current_user as username');
$role = Illuminate\Support\Facades\DB::selectOne('select rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
$key = 'acceptance-'.bin2hex(random_bytes(8));
Illuminate\Support\Facades\Cache::put($key, 'ok', 30);
$cacheOk = Illuminate\Support\Facades\Cache::pull($key) === 'ok';
echo json_encode([
 'php' => PHP_VERSION, 'laravel' => app()->version(),
 'inertia' => Composer\InstalledVersions::getPrettyVersion('inertiajs/inertia-laravel'),
 'environment' => app()->environment(), 'debug' => config('app.debug'),
 'url' => config('app.url'), 'name' => config('app.name'),
 'db' => $identity, 'db_superuser' => (bool) $role->rolsuper,
 'db_create_database' => (bool) $role->rolcreatedb, 'db_create_role' => (bool) $role->rolcreaterole,
 'connection' => config('database.default'), 'cache' => config('cache.default'),
 'queue' => config('queue.default'), 'session' => config('session.driver'),
 'registration_open' => App\Support\LaunchSettings::publicData()['registration_open'],
 'mail' => config('mail.default'), 'independent_key_valid' => strlen(base64_decode(substr(config('app.key'), 7))) === 32,
 'database_cache_roundtrip' => $cacheOk,
 'migrations' => Illuminate\Support\Facades\DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
 'sessions_written' => Illuminate\Support\Facades\DB::table('sessions')->count() > 0,
 'postgres' => Illuminate\Support\Facades\DB::selectOne('show server_version')->server_version,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
'''
app = json.loads(command(['docker','compose','exec','-T','--user','www-data','app','php'], input=php))
assert app['environment'] == 'production' and app['debug'] is False
assert app['db'] == {'database': 'jakawi_club', 'username': 'jakawi_club'}
assert not any(app[key] for key in ['db_superuser','db_create_database','db_create_role'])
assert app['connection'] == 'pgsql' and app['mail'] in ['smtp', 'log']
assert page['props']['launch']['registration_open'] is app['registration_open']
assert all(app[key] == 'database' for key in ['cache','queue','session'])
assert app['database_cache_roundtrip'] and app['sessions_written'] and app['independent_key_valid']
assert len(app['migrations']) >= 6
assert '2026_10_29_000001_create_marketing_landings' in app['migrations']
assert '2026_10_28_000002_create_marketing_event_totals' in app['migrations']
assert '2026_10_28_000001_extend_users_for_launch' in app['migrations']

names = ['jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db']
inspected = json.loads(command(['docker','inspect',*names]))
containers = {}
for container in inspected:
    name = container['Name'].lstrip('/')
    assert container['State']['Running'] and container['State']['Health']['Status'] == 'healthy'
    assert container['Config']['Labels']['traefik.enable'] == 'false'
    assert container['HostConfig']['RestartPolicy']['Name'] == 'unless-stopped'
    assert container['HostConfig']['Memory'] > 0
    networks = sorted(container['NetworkSettings']['Networks'])
    allowed_networks = ['jakawi-club-private', 'jakawi-club-egress']
    if name == 'jakawi-club-web':
        allowed_networks.append('web')
    assert all(network in allowed_networks for network in networks)
    ports = container['NetworkSettings']['Ports']
    bindings = [binding for values in ports.values() if values for binding in values]
    if name == 'jakawi-club-web':
        assert bindings == [{'HostIp':'127.0.0.1','HostPort':'18082'}]
    else:
        assert bindings == []
    containers[name] = {
        'status':container['State']['Status'], 'health':container['State']['Health']['Status'],
        'memory_limit_mib':container['HostConfig']['Memory'] // 1024**2,
        'restart_count':container['RestartCount'], 'networks':networks, 'ports':ports,
    }
private = json.loads(command(['docker','network','inspect','jakawi-club-private']))[0]
assert private['Internal'] is True
assert len(private['Containers']) == 3

# Check only this CLUB release; old cross-project snapshots are outside scope.
protected = None
baseline_path = Path('/srv/sites/jakawi.club/rollback-catalog-07b2/baseline.json')
if baseline_path.exists():
    baseline = json.loads(baseline_path.read_text())
    for container in inspected:
        name = container['Name'].lstrip('/')
        before = baseline['containers'][name]
        assert container['Id'] == before['id'], f'{name}: ID changed'
        if name != 'jakawi-club-app':
            assert container['State']['StartedAt'] == before['started_at'], f'{name}: restarted'
            assert container['RestartCount'] == before['restarts'], f'{name}: restarted'
    protected = {'scope': 'CLUB only', 'container_ids_unchanged': len(inspected), 'web_db_not_restarted': True}

production_code, _ = get('https://jakawi.com/up')
assert production_code == 200
report = {
    'validated_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'compose_valid':True, 'local_http':200, 'inertia_http':200, 'assets':asset_checks,
    'app':app, 'containers':containers, 'private_network_internal':True,
    'jakawi_com_up':production_code, 'protected_baseline':protected,
}
output = Path(os.environ.get('JAKAWI_EVIDENCE_DIR', str(ROOT / 'artifacts/base-tecnica'))) / 'validation.json'
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(report, indent=2, ensure_ascii=False)+'\n')
print(json.dumps(report, indent=2, ensure_ascii=False))
