#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json,subprocess
from pathlib import Path
root=Path('/opt/sites/jakawi.club')
assert subprocess.check_output(['git','branch','--show-current'],cwd=root).strip()==b'main'
assert json.loads((root/'artifacts/seo-social/tests.json').read_text())['live_fixtures']==0
assert (root/'app/public/build-next/manifest.json').is_file()
PY
# Existing bucket only; no migrations, feed synchronization or shared service changes.
docker compose exec -T --user www-data app php artisan media:prepare-social
python3 - <<'PY'
from pathlib import Path
import os,shutil
root=Path('/opt/sites/jakawi.club/app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json':continue
    target=root/'build'/source.relative_to(root/'build-next')
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert source.read_bytes()==target.read_bytes(),'Asset hash collision'
    else:shutil.copy2(source,target)
shutil.copy2(root/'build-next/manifest.json',root/'build/manifest.seo-next.json')
os.replace(root/'build/manifest.seo-next.json',root/'build/manifest.json')
PY
docker compose exec -T --user www-data app php artisan view:cache
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
