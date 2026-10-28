#!/usr/bin/env python3
"""Validate this publication without migrations or exposing production secrets."""
from datetime import datetime
from hashlib import sha256
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import socket
import ssl
import subprocess
import urllib.error
import urllib.request
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')


def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, timeout=25, **kwargs)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, url):
        return None


opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())


def get(url, headers=None):
    try:
        response = opener.open(urllib.request.Request(url, headers=headers or {}), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().decode()


command(['docker', 'compose', 'config', '--quiet'])
dns = {}
for resolver in ['1.1.1.1', '8.8.8.8']:
    for hostname in ['jakawi.club', 'www.jakawi.club']:
        answer = command(['dig', '@' + resolver, hostname, 'A', '+short', '+time=3', '+tries=1'])
        addresses = re.findall(r'^\d+\.\d+\.\d+\.\d+$', answer, re.MULTILINE)
        assert addresses == ['167.71.107.80'], (resolver, hostname, answer)
        dns[f'{resolver}/{hostname}'] = addresses

certificates = {}
context = ssl.create_default_context()
for hostname in ['jakawi.club', 'www.jakawi.club']:
    with socket.create_connection((hostname, 443), timeout=10) as raw:
        with context.wrap_socket(raw, server_hostname=hostname) as connection:
            cert = connection.getpeercert()
            sans = [value for kind, value in cert.get('subjectAltName', []) if kind == 'DNS']
            assert {'jakawi.club', 'www.jakawi.club'} <= set(sans), sans
            certificates[hostname] = {
                'valid_chain_and_hostname': True,
                'issuer': dict(field for item in cert['issuer'] for field in item),
                'not_before': cert['notBefore'], 'not_after': cert['notAfter'],
                'dns_names': sans,
                'sha256_fingerprint': sha256(connection.getpeercert(binary_form=True)).hexdigest(),
            }

status, headers, html = get('https://jakawi.club/')
assert status == 200 and 'JAKAWI Launch Engine' in html
urls = re.findall(r'(?:href|src)="([^"]+/build/assets/[^"]+)"', html)
assert urls and all(url.startswith('https://jakawi.club/') for url in urls), urls
assert headers.get('Set-Cookie') and 'secure' in headers['Set-Cookie'].lower()
assert 'APP_KEY' not in html and 'DB_PASSWORD' not in html
checks = {'https://jakawi.club/': {'status': status, 'assets_use_https': True}}
for url in ['https://jakawi.club/up', 'http://127.0.0.1:18082/']:
    code, _, _ = get(url)
    assert code == 200, (url, code)
    checks[url] = {'status': code}

redirects = [
    ('https://www.jakawi.club/', 'https://jakawi.club/'),
    ('http://jakawi.club/', 'https://jakawi.club/'),
    ('http://www.jakawi.club/', 'https://jakawi.club/'),
    ('https://www.jakawi.club/up?check=publication', 'https://jakawi.club/up?check=publication'),
]
for url, expected in redirects:
    code, headers, _ = get(url)
    assert code in [301, 308] and headers.get('Location') == expected, (url, code, headers.get('Location'))
    checks[url] = {'status': code, 'location': expected}


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
code, _, payload = get('https://jakawi.club/', {
    'X-Inertia': 'true', 'X-Inertia-Version': initial_page['version'],
    'X-Requested-With': 'XMLHttpRequest',
})
page = json.loads(payload)
assert code == 200 and page['component'] == 'Welcome'
assert page['props']['heading'] == 'JAKAWI Launch Engine'
assert page['props']['message'] == 'Próximamente en jakawi.club'

manifest = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
assets = {}
for entry in manifest.values():
    for file in [entry['file'], *entry.get('css', [])]:
        url = 'https://jakawi.club/build/' + file
        code, headers, content = get(url)
        assert code == 200 and content, (url, code)
        expected_type = 'text/css' if file.endswith('.css') else 'javascript'
        assert expected_type in headers.get('Content-Type', ''), (url, headers.get('Content-Type'))
        assets[file] = {'status': code, 'content_type': headers.get('Content-Type')}

php = r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
 'environment' => app()->environment(), 'debug' => config('app.debug'),
 'url' => config('app.url'), 'secure_session_cookie' => config('session.secure'),
 'mail' => config('mail.default'),
]);
'''
config = json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=php))
assert config == {'environment': 'production', 'debug': False, 'url': 'https://jakawi.club',
                  'secure_session_cookie': True, 'mail': 'log'}, config

containers = {}
inspected = json.loads(command(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db']))
for container in inspected:
    name = container['Name'].lstrip('/')
    assert container['State']['Running'] and container['State']['Health']['Status'] == 'healthy', name
    assert container['Config']['Labels']['traefik.enable'] == 'false'
    networks = sorted(container['NetworkSettings']['Networks'])
    ports = container['NetworkSettings']['Ports']
    bindings = [binding for values in ports.values() if values for binding in values]
    if name == 'jakawi-club-web':
        assert networks == ['jakawi-club-egress', 'jakawi-club-private', 'web']
        assert bindings == [{'HostIp': '127.0.0.1', 'HostPort': '18082'}]
    elif name == 'jakawi-club-db':
        assert networks == ['jakawi-club-private'] and bindings == []
    else:
        assert networks == ['jakawi-club-egress', 'jakawi-club-private'] and bindings == []
    containers[name] = {'running': True, 'health': 'healthy', 'networks': networks, 'ports': ports}

backend = command(['docker', 'exec', 'edge-traefik-1', 'wget', '-q', '-O', '-',
                   '--header', 'Host: jakawi.club', 'http://jakawi-club-web/up'])
assert 'Application up' in backend
source = ROOT / 'docker/traefik/jakawi-club.yaml'
installed = Path('/opt/infra/traefik/dynamic/jakawi-club.yaml')
assert source.read_bytes() == installed.read_bytes()

protected = None
baseline_path = ROOT / 'artifacts/https-publication/baseline.json'
if baseline_path.exists():
    baseline = json.loads(baseline_path.read_text())
    old = json.loads(command(['docker', 'inspect', *baseline['containers']]))
    for container in old:
        name = container['Name'].lstrip('/')
        if name == 'jakawi-club-web':
            continue
        before = baseline['containers'][name]
        assert container['Id'] == before['id'], f'{name}: recreated'
        assert container['State']['StartedAt'] == before['started_at'], f'{name}: restarted'
        assert container['RestartCount'] == before['restart_count'], f'{name}: restart count changed'
        assert sorted(container['NetworkSettings']['Networks']) == before['networks'], f'{name}: networks changed'
        assert container['State']['Running'], f'{name}: stopped'
        if before['health'] == 'healthy':
            assert container['State'].get('Health', {}).get('Status') == 'healthy', f'{name}: unhealthy'
    for file, digest in baseline['traefik_files'].items():
        assert sha256(Path(file).read_bytes()).hexdigest() == digest, f'{file}: modified'
    for file, digest in baseline['credential_hashes'].items():
        assert sha256(Path(file).read_bytes()).hexdigest() == digest, 'Project credentials changed'
    protected = {
        'foreign_containers_unchanged': sum(not name.startswith('jakawi-club-') for name in baseline['containers']),
        'app_and_db_lifecycle_and_networks_unchanged': True,
        'existing_traefik_files_unchanged': len(baseline['traefik_files']),
        'project_credentials_unchanged': True,
    }

code, _, _ = get('https://jakawi.com/up')
assert code == 200
report = {
    'validated_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'dns': dns, 'http': checks, 'tls': certificates,
    'inertia_http': 200, 'assets': assets, 'laravel': config, 'containers': containers,
    'traefik_backend_reachable': True, 'dedicated_dynamic_file_matches_repository': True,
    'protected_baseline': protected, 'jakawi_com_up': code,
}
output = ROOT / 'artifacts/https-publication/validation.json'
output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(report, indent=2, ensure_ascii=False))
