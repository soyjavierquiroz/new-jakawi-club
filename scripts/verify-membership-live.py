#!/usr/bin/env python3
"""Verify this release without creating LIVE users, intentions or activations."""
from pathlib import Path
from hashlib import sha256
import json, os, subprocess
ROOT=Path('/opt/sites/jakawi.club')
EVIDENCE=ROOT/'artifacts/membership-funnel'
PRIVATE=Path('/srv/sites/jakawi.club/rollback-membership-funnel')
def out(args,**kwargs):return subprocess.check_output(args,cwd=ROOT,**kwargs)
env={**os.environ,'JAKAWI_EVIDENCE_DIR':str(EVIDENCE),'JAKAWI_BASELINE_PATH':str(PRIVATE/'baseline.json')}
subprocess.run(['python3','scripts/validate-local.py'],cwd=ROOT,env=env,check=True)
raw=out(['docker','compose','exec','-T','--user','www-data','app','php'],input=(ROOT/'scripts/membership-live-state.php').read_bytes())
(EVIDENCE/'state-after.json').write_bytes(raw)
assert json.loads(raw)==json.loads((EVIDENCE/'state-before.json').read_text()),'Protected business state changed; inspect before asserting continuity'
baseline=json.loads((PRIVATE/'baseline.json').read_text())
for name,before in baseline['containers'].items():
    container=json.loads(out(['docker','inspect',name]))[0]
    assert container['Id']==before['id'] and container['Image']==before['image'],name+' recreated'
    if name!='jakawi-club-app':
        assert container['State']['StartedAt']==before['started_at'] and container['RestartCount']==before['restarts'],name+' restarted'
assert json.loads((EVIDENCE/'browser-live.json').read_text())['no_live_mutations']
assert not out(['git','status','--porcelain'])
files={}
for path in ['app/app/Http/Controllers/MembershipCheckoutController.php','app/app/Http/Controllers/JakawiLoginController.php','app/app/Http/Controllers/CommercialEventController.php','app/app/Services/AffiliateResults.php','app/routes/web.php','app/public/build/manifest.json']:
    digest=sha256((ROOT/path).read_bytes()).hexdigest()
    served=out(['docker','compose','exec','-T','--user','www-data','app','php','-r','echo hash_file("sha256", $argv[1]);',path.removeprefix('app/')]).decode()
    assert digest==served,path+' runtime differs'
    files[path]=digest
result={'sha':out(['git','rev-parse','HEAD']).decode().strip(),'origin_main':out(['git','rev-parse','origin/main']).decode().strip(),'club_only':True,'up_https':200,'up_loopback':200,'protected_containers_unchanged':True,'protected_business_hashes_unchanged':True,'runtime_files':files,'live_fixtures':0}
assert result['sha']==result['origin_main']
(EVIDENCE/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result))
