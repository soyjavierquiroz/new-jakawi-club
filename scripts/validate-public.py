#!/usr/bin/env python3
"""Validate this publication without migrations or exposing production secrets."""
from datetime import datetime
from hashlib import sha256
from html.parser import HTMLParser
import json
import http.cookiejar
from pathlib import Path
import re
import socket
import ssl
import subprocess
import urllib.error
import urllib.request
from urllib.parse import unquote
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')


def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, timeout=25, **kwargs)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, url):
        return None


opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())


def get(url, headers=None, binary=False):
    try:
        response = opener.open(urllib.request.Request(url, headers=headers or {}), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read()
        return response.status, response.headers, body if binary else body.decode()


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
assert page['props']['launch']['registration_open'] is False
assert page['props']['launch']['event'] is None

# Focused MVP checks: no test account is submitted to the public application.
for path in ['/login', '/recuperar-password', '/privacidad']:
    code, _, content = get('https://jakawi.club' + path)
    assert code == 200 and 'id="app"' in content
    checks['https://jakawi.club' + path] = {'status': code}
code, headers, _ = get('https://jakawi.club/dashboard')
assert code == 302 and headers.get('Location') == 'https://jakawi.club/login'
checks['https://jakawi.club/dashboard'] = {'status': code, 'guest_redirect': True}
jar = http.cookiejar.CookieJar()
browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
with browser.open('https://jakawi.club/', timeout=15) as response:
    response.read()
xsrf = next(cookie.value for cookie in jar if cookie.name == 'XSRF-TOKEN')
registration_request = urllib.request.Request('https://jakawi.club/registro', data=b'{}', headers={
    'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': unquote(xsrf),
})
try:
    response = browser.open(registration_request, timeout=15)
except urllib.error.HTTPError as error:
    response = error
with response:
    assert response.status == 403
checks['https://jakawi.club/registro'] = {'status': 403, 'valid_csrf': True, 'registration_closed': True}
for _ in range(2):
    measurement = urllib.request.Request('https://jakawi.club/medicion', data=b'{"event":"landing_visit","placement":"landing"}', headers={
        'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': unquote(xsrf),
    })
    with browser.open(measurement, timeout=15) as response:
        assert response.status == 204
checks['https://jakawi.club/medicion'] = {'status': 204, 'valid_csrf': True, 'same_session_repeated_request': True}
for country in ['BO', 'ES']:
    path = '/marketing/flags/' + country + '.svg'
    code, headers, content = get('https://jakawi.club' + path, binary=True)
    assert code == 200 and 'image/svg+xml' in headers.get('Content-Type', '')
    assert content == (ROOT / 'app/public' / path.lstrip('/')).read_bytes()
    checks['https://jakawi.club' + path] = {'status': 200, 'local_bytes_match': True}

manifest = json.loads((ROOT / 'app/public/build/manifest.json').read_text())
assets = {}
for entry in manifest.values():
    for file in [entry['file'], *entry.get('css', [])]:
        url = 'https://jakawi.club/build/' + file
        code, headers, content = get(url, binary=True)
        assert code == 200 and content, (url, code)
        expected_type = 'font/woff2' if file.endswith('.woff2') else 'text/css' if file.endswith('.css') else 'javascript'
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
 'registration_open' => App\Support\LaunchSettings::registrationOpen(),
 'transactional_mail_ready' => App\Support\LaunchSettings::mailReady(),
]);
'''
config = json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=php))
assert config == {'environment': 'production', 'debug': False, 'url': 'https://jakawi.club',
                  'secure_session_cookie': True, 'mail': 'log', 'registration_open': False,
                  'transactional_mail_ready': False}, config

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
if (ROOT / 'artifacts/mvp/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/mvp/baseline.json'
if (ROOT / 'artifacts/identity/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/identity/baseline.json'
if (ROOT / 'artifacts/conversion/baseline.json').exists():
    baseline_path = ROOT / 'artifacts/conversion/baseline.json'
if baseline_path.exists():
    baseline = json.loads(baseline_path.read_text())
    old = json.loads(command(['docker', 'inspect', *baseline['containers']]))
    automatic_restarts = []
    for container in old:
        name = container['Name'].lstrip('/')
        if name == 'jakawi-club-web':
            continue
        before = baseline['containers'][name]
        assert container['Id'] == before['id'], f'{name}: recreated'
        automatic_restart = not name.startswith('jakawi-club-') and container['RestartCount'] > before['restart_count']
        if automatic_restart:
            automatic_restarts.append({'name': name, 'before': before['restart_count'], 'after': container['RestartCount'], 'started_at': container['State']['StartedAt']})
        elif name != 'jakawi-club-app':
            assert container['State']['StartedAt'] == before['started_at'], f'{name}: restarted'
        assert automatic_restart or container['RestartCount'] == before['restart_count'], f'{name}: restart count changed'
        assert sorted(container['NetworkSettings']['Networks']) == before['networks'], f'{name}: networks changed'
        assert container['State']['Running'], f'{name}: stopped'
        if before['health'] == 'healthy':
            assert container['State'].get('Health', {}).get('Status') == 'healthy', f'{name}: unhealthy'
    for file, digest in baseline['traefik_files'].items():
        assert sha256(Path(file).read_bytes()).hexdigest() == digest, f'{file}: modified'
    for file, digest in baseline['credential_hashes'].items():
        assert sha256(Path(file).read_bytes()).hexdigest() == digest, 'Project credentials changed'
    protected = {
        'foreign_container_ids_and_networks_unchanged': sum(not name.startswith('jakawi-club-') for name in baseline['containers']),
        'automatic_foreign_restarts_observed': automatic_restarts,
        'app_and_db_ids_and_networks_unchanged': True,
        'db_lifecycle_unchanged': True,
        'app_restart_allowed_for_opcache_deployment': True,
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
