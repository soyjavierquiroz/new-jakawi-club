#!/usr/bin/env python3
"""Focused CLUB-only release seal; outputs no credentials or user records."""
import importlib.util
import json
from datetime import datetime
from hashlib import sha256
from pathlib import Path
import re
from urllib.request import urlopen
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')
OUT = ROOT/'artifacts/confirmation-scroll'
PRIVATE = Path('/srv/sites/jakawi.club/rollback-confirmation-scroll')
spec = importlib.util.spec_from_file_location('release_helpers', ROOT/'scripts/verify-registration-copy-live.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
before = json.loads((PRIVATE/'baseline.json').read_text())
head = helper.git('rev-parse', 'HEAD')
assert head == helper.git('rev-parse','origin/main') == helper.git('ls-remote','origin','refs/heads/main').split()[0]
assert not helper.git('status','--porcelain'), 'Working tree must be clean'
current = helper.files()
assert set(current) == set(before['files'])
changed = [p for p in current if current[p] != before['files'][p]]
assert changed == ['app/resources/js/components/forms/RegistrationForm.tsx']
assert helper.secrets() == before['secrets']
state = helper.php(helper.BOOT+'echo json_encode($r);')
assert state == before['state'], 'Registration, editorial records and protected tables must remain unchanged'
containers = helper.containers()
assert containers == before['containers'], 'Frontend-only fix must not restart services'
import base64
encoded=base64.b64encode(json.dumps(list(current)).encode()).decode()
mounted=helper.php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true) as $p){$r[$p]=hash_file('sha256',substr($p,4));}echo json_encode($r);")
assert mounted == current
assets = {}
manifest = json.loads((ROOT/'app/public/build/manifest.json').read_text())
for entry in manifest.values():
    for filename in [entry['file'],*entry.get('css',[])]:
        if filename in assets: continue
        data = (ROOT/'app/public/build'/filename).read_bytes()
        with urlopen('https://jakawi.club/build/'+filename,timeout=20) as response:
            assert response.status == 200 and response.read() == data
        assets[filename] = sha256(data).hexdigest()
for old in (PRIVATE/'build').rglob('*'):
    if old.is_file() and old.name != 'manifest.json':
        assert old.read_bytes() == (ROOT/'app/public/build'/old.relative_to(PRIVATE/'build')).read_bytes()
health = {}
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']:
    with urlopen(url,timeout=20) as response:
        assert response.status == 200
        health[url] = 200
for path in ['/principal/gracias','/principal/confirmado','/bienvenida']:
    with urlopen('https://jakawi.club'+path,timeout=20) as response:
        expected_url = 'https://jakawi.club/principal' if path != '/bienvenida' else 'https://jakawi.club/'
        assert response.url == expected_url
        assert 'Registered' not in re.search(r'<script\b[^>]*data-page="app"[^>]*>(.*?)</script>',response.read().decode(),re.S)[1]
browser = json.loads((OUT/'browser-live.json').read_text())
assert len(browser['checks']) == 6 and all(c['y'] == 0 for c in browser['checks'])
assert browser['runtime_errors'] == browser['failed_assets'] == []
assert browser['no_live_registration'] and browser['server_generated_receipts']
assert (OUT/'validation.json').exists()
result = {'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,
          'origin_main_synced':True,'working_tree_clean':True,'changed_application_files':changed,
          'mounted_files_match':len(current),'https_assets_verified':len(assets),'previous_assets_preserved':True,
          'registration_and_protected_tables_unchanged':True,'receipt_protection_verified':True,
          'containers_and_secrets_unchanged':True,'app_debug':state['debug'],'registration_open':state['registration_open'],
          'browser_cases':6,'confirmation_scroll_y':0,'health':health,'live_test_registrations':0,'emails_sent':0}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
