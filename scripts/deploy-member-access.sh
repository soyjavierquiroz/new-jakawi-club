#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json,subprocess
from pathlib import Path
assert subprocess.check_output(['git','branch','--show-current']).strip()==b'main'
assert not subprocess.check_output(['git','status','--porcelain'])
p=Path('artifacts/member-access-11h')
assert json.loads((p/'tests.json').read_text())['live_fixtures']==0
assert json.loads((p/'browser-preview.json').read_text())['no_live_mutations']
assert (p/'economics/tests.json').is_file()
assert Path('/srv/sites/jakawi.club/rollback-member-access-11h/manifest.json').is_file()
assert Path('app/public/build-next/manifest.json').is_file()
PY
docker compose config --quiet
# No migrations, env edits, purchases or economic writes.
python3 - <<'PY'
from pathlib import Path
import os,shutil
root=Path('app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json':continue
    target=root/'build'/source.relative_to(root/'build-next');target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert source.read_bytes()==target.read_bytes(),'Asset hash collision'
    else:shutil.copy2(source,target)
shutil.copy2(root/'build-next/manifest.json',root/'build/manifest.member-access-11h.json')
os.replace(root/'build/manifest.member-access-11h.json',root/'build/manifest.json')
PY
for cache in route:cache view:cache; do
    docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
