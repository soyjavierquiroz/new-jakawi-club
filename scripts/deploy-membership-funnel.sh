#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json, os, subprocess
from pathlib import Path
root=Path('/opt/sites/jakawi.club')
evidence=Path(os.environ.get('JAKAWI_EVIDENCE_DIR',str(root/'artifacts/membership-funnel')))
private=Path(os.environ.get('JAKAWI_ROLLBACK_DIR','/srv/sites/jakawi.club/rollback-membership-funnel'))
assert subprocess.check_output(['git','branch','--show-current']).strip()==b'main'
assert not subprocess.check_output(['git','status','--porcelain'])
assert json.loads((evidence/'tests.json').read_text())['live_fixtures']==0
assert json.loads((evidence/'sso-tests.json').read_text())['live_users_created']==0
assert json.loads((evidence/'browser-preview.json').read_text())['no_live_mutations']
assert (private/'baseline.json').is_file()
assert (root/'app/public/build-next/manifest.json').is_file()
PY
# Preserve existing chunks and switch the manifest last. No migrations or env changes.
python3 - <<'PY'
from pathlib import Path
import os, shutil
root=Path('/opt/sites/jakawi.club/app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json':continue
    target=root/'build'/source.relative_to(root/'build-next')
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert source.read_bytes()==target.read_bytes(),'Asset hash collision'
    else:shutil.copy2(source,target)
shutil.copy2(root/'build-next/manifest.json',root/'build/manifest.membership-next.json')
os.replace(root/'build/manifest.membership-next.json',root/'build/manifest.json')
PY
for cache in route:cache view:cache; do
    docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
