#!/usr/bin/env python3
"""Prepare CLUB-only rollback and privately install the dedicated purchase credential."""
import json, os, re, shutil, subprocess
from pathlib import Path
root=Path('/opt/sites/jakawi.club')
private=Path('/srv/sites/jakawi.club/rollback-public-checkout')
os.umask(0o077)
private.mkdir(exist_ok=True)
if not (private/'baseline.json').exists():
    baseline={'sha':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()}
    (private/'baseline.json').write_text(json.dumps(baseline))
    shutil.copy2(root/'app/public/build/manifest.json',private/'manifest.json')
baseline=json.loads((private/'baseline.json').read_text())
if not (private/'source.tar').exists():
    (private/'source.tar').write_bytes(subprocess.check_output(['git','archive',baseline['sha']],cwd=root))
if 'containers' not in baseline:
    baseline['containers']={}
    for name in ['jakawi-club-web','jakawi-club-db','jakawi-app-1','jakawi-web-1','jakawi-worker-1','jakawi-scheduler-1']:
        item=json.loads(subprocess.check_output(['docker','inspect',name]))[0]
        baseline['containers'][name]={'id':item['Id'],'image':item['Image'],'started_at':item['State']['StartedAt']}
    (private/'baseline.json').write_text(json.dumps(baseline,indent=2))
# Read the new, explicitly documented COM credential without printing or sourcing it.
source=Path('/opt/sites/jakawi.com/club-purchases.env').read_text()
match=re.search(r'^CLUB_PURCHASE_HMAC_SECRET=([a-f0-9]{64})$',source,re.M)
assert match, 'Dedicated purchase credential unavailable'
recipient=subprocess.check_output(['docker','exec','jakawi-app-1','php','artisan','tinker','--execute=echo app(\\App\\Services\\MembershipBankQr::class)->recipient();'],text=True).strip()
assert recipient and len(recipient)<=255 and '\n' not in recipient
content='CLUB_PURCHASE_HMAC_SECRET='+match[1]+'\nCLUB_PURCHASE_RECIPIENT='+json.dumps(recipient,ensure_ascii=False)+'\n'
target=Path('/srv/sites/jakawi.club/secrets/com-purchases.env')
if target.exists(): assert target.read_text()==content, 'Existing private configuration differs; preserve it'
else: target.write_text(content);target.chmod(0o600)
print(json.dumps({'private_rollback_ready':True,'dedicated_backend_credential_installed':True,'recipient_matches_com_configuration':True}))
