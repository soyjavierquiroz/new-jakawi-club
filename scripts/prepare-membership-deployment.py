#!/usr/bin/env python3
"""Private rollback and protected container evidence for the CLUB membership release."""
import json, os, shutil, subprocess
from pathlib import Path
ROOT=Path('/opt/sites/jakawi.club')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-membership-funnel')
os.umask(0o077)
PRIVATE.mkdir(exist_ok=True)
assert not (PRIVATE/'baseline.json').exists(), 'Do not overwrite the release baseline'
def out(args, **kwargs): return subprocess.check_output(args,cwd=ROOT,**kwargs)
head=out(['git','rev-parse','HEAD']).decode().strip()
containers={}
for name in ['jakawi-club-app','jakawi-club-web','jakawi-club-db','jakawi-app-1','jakawi-web-1','jakawi-worker-1','jakawi-scheduler-1','crm-jakawi-private-app-1','crm-jakawi-private-web-1','crm-jakawi-private-db-1']:
    item=json.loads(out(['docker','inspect',name]))[0]
    containers[name]={'id':item['Id'],'image':item['Image'],'started_at':item['State']['StartedAt'],'restarts':item['RestartCount']}
shutil.copytree(ROOT/'app/public/build',PRIVATE/'build')
(PRIVATE/'source.tar').write_bytes(out(['git','archive',head]))
(PRIVATE/'baseline.json').write_text(json.dumps({'sha':head,'containers':containers},indent=2)+'\n')
print(json.dumps({'previous_sha':head,'private_rollback_ready':True}))
