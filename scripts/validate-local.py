#!/usr/bin/env python3
"""Small, read-focused acceptance check; never serialize container environment."""
from datetime import datetime
from hashlib import sha256
from html.parser import HTMLParser
import json
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
assert page['props']['launch']['registration_open'] is False
assert page['props']['launch']['event'] is None
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
assert app['connection'] == 'pgsql' and app['mail'] == 'log'
assert all(app[key] == 'database' for key in ['cache','queue','session'])
assert app['database_cache_roundtrip'] and app['sessions_written'] and app['independent_key_valid']
assert len(app['migrations']) == 5
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

protected = None
baseline_path = ROOT / 'artifacts/base-tecnica/baseline.json'
if (ROOT / 'artifacts/mvp/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/mvp/baseline.json'
if (ROOT / 'artifacts/identity/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/identity/baseline.json'
if (ROOT / 'artifacts/conversion/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/conversion/baseline.json'
if baseline_path.exists():
    baseline = json.loads(baseline_path.read_text())
    old = json.loads(command(['docker','inspect',*baseline['containers']]))
    automatic_restarts = []
    for container in old:
        name = container['Name'].lstrip('/')
        before = baseline['containers'][name]
        assert container['Id'] == before['id'], f'{name}: ID changed'
        automatic_restart = not name.startswith('jakawi-club-') and container['RestartCount'] > before['restart_count']
        if automatic_restart:
            automatic_restarts.append({'name': name, 'before': before['restart_count'], 'after': container['RestartCount'], 'started_at': container['State']['StartedAt']})
        elif name != 'jakawi-club-app':
            assert container['State']['StartedAt'] == before['started_at'], f'{name}: start time changed'
        assert automatic_restart or container['RestartCount'] == before['restart_count'], f'{name}: restarted'
        assert container['State']['Running'], f'{name}: stopped'
        if before['health'] == 'healthy':
            assert container['State'].get('Health',{}).get('Status') == 'healthy', f'{name}: health changed'
    for path, digest in baseline['protected_files'].items():
        assert sha256(Path(path).read_bytes()).hexdigest() == digest, f'{path}: modified'
    protected = {'container_ids_unchanged':len(old), 'infrastructure_files_unchanged':len(baseline['protected_files']), 'automatic_foreign_restarts_observed': automatic_restarts}

production_code, _ = get('https://jakawi.com/up')
assert production_code == 200
report = {
    'validated_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'compose_valid':True, 'local_http':200, 'inertia_http':200, 'assets':asset_checks,
    'app':app, 'containers':containers, 'private_network_internal':True,
    'jakawi_com_up':production_code, 'protected_baseline':protected,
}
output = ROOT / 'artifacts/base-tecnica/validation.json'
output.write_text(json.dumps(report, indent=2, ensure_ascii=False)+'\n')
print(json.dumps(report, indent=2, ensure_ascii=False))
