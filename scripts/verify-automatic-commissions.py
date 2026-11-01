#!/usr/bin/env python3
"""Seal the CLUB release and targeted existing entry, without printing private data."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path('/opt/sites/jakawi.club')
BACKUP = Path('/srv/sites/jakawi.club/rollback-automatic-commissions')
OUT = ROOT / 'artifacts/automatic-commissions'

def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)

assert not command(['git', 'status', '--porcelain'])
sha = command(['git', 'rev-parse', 'HEAD']).decode().strip()
assert sha == command(['git', 'rev-parse', 'origin/main']).decode().strip()
assert sha == command(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
baseline = json.loads((BACKUP / 'baseline.json').read_text())
assert baseline['env'] == {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').glob('*.env')}
for container in json.loads(command(['docker', 'inspect', 'jakawi-club-app', 'jakawi-club-web', 'jakawi-club-db'])):
    name = container['Name'].lstrip('/')
    before = baseline['containers'][name]
    assert container['Id'] == before['id']
    assert container['State']['Health']['Status'] == 'healthy'
    if name != 'jakawi-club-app':
        assert container['State']['StartedAt'] == before['started_at']
        assert container['RestartCount'] == before['restarts']
source = r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['ledger'=>DB::table('commission_ledger')->orderBy('id')->get(),
'entry1_audits'=>DB::table('commission_audit')->where('ledger_id',1)->where('action','automatically_approved')->count(),
'debug'=>config('app.debug'),'routes_cached'=>app()->routesAreCached()],JSON_THROW_ON_ERROR);
'''
state = json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=source.encode()))
assert state['debug'] is False and state['routes_cached']
after = {entry['id']: entry for entry in state['ledger']}
mutable = {'status', 'review_required', 'approved_at', 'approved_by', 'updated_at', 'version'}
for before in json.loads((BACKUP / 'ledger-before.json').read_text()):
    current = after[before['id']]
    for key, value in before.items():
        if before['id'] == 1 and key in mutable:
            continue
        assert current[key] == value, 'Existing economic or attribution snapshot changed'
entry = after[1]
assert entry['amount'] == '25.0000' and entry['currency'] == 'BOB'
assert entry['status'] in ('candidate', 'approved')
if entry['status'] == 'approved':
    assert not entry['review_required'] and entry['approval_mode'] == 'automatic'
    assert entry['approved_by'] is None and entry['approved_at'] is not None
    assert state['entry1_audits'] == 1
else:
    assert entry['review_required'] and state['entry1_audits'] == 0
# Verify mounted source bytes for the changed application files.
files = command(['git', 'diff', '--name-only', baseline['sha'], sha, '--', 'app']).decode().splitlines()
expected = {p.removeprefix('app/'): hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in files if (ROOT / p).is_file()}
import base64
encoded = base64.b64encode(json.dumps(list(expected)).encode()).decode()
actual = json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true) as $p)$r[$p]=hash_file('sha256',$p);echo json_encode($r);").encode()))
assert actual == expected
assert json.loads((OUT / 'browser-live.json').read_text())['no_live_mutations']
assert json.loads((OUT / 'validation.json').read_text())['local_http'] == 200
report = {'sha': sha, 'manifest_sha256': hashlib.sha256((ROOT / 'app/public/build/manifest.json').read_bytes()).hexdigest(),
          'entry1': {key: entry[key] for key in ('id', 'status', 'amount', 'currency', 'approval_mode', 'review_required', 'approved_at')},
          'entry1_automatic_audits': state['entry1_audits'], 'economic_snapshots_preserved': True,
          'club_only': True, 'payments_executed': 0, 'live_fixtures': 0}
(OUT / 'LIVE.json').write_text(json.dumps(report, indent=2)+'\n')
print(json.dumps(report, ensure_ascii=False))
