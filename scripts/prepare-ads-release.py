#!/usr/bin/env python3
"""Private rollback snapshot for CLUB; never reads another project's data."""
import hashlib,json,os,shutil,subprocess
from pathlib import Path
root=Path('/opt/sites/jakawi.club');private=Path('/srv/sites/jakawi.club/rollback-ads-phase09')
os.umask(0o077);private.mkdir(exist_ok=True)
if not (private/'baseline.json').exists():
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
    baseline={'sha':sha,'env_sha256':hashlib.sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest(),'containers':{}}
    for name in ['jakawi-club-app','jakawi-club-web','jakawi-club-db']:
        item=json.loads(subprocess.check_output(['docker','inspect',name]))[0]
        baseline['containers'][name]={'id':item['Id'],'image':item['Image'],'started_at':item['State']['StartedAt']}
    (private/'source.tar').write_bytes(subprocess.check_output(['git','archive',sha],cwd=root))
    shutil.copy2(root/'app/public/build/manifest.json',private/'manifest.json')
    (private/'baseline.json').write_text(json.dumps(baseline,indent=2))
print('Rollback privado CLUB preparado; sin cambios de configuración.')
