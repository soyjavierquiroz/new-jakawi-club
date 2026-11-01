#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json,subprocess
from pathlib import Path
assert subprocess.check_output(['git','branch','--show-current']).strip()==b'main'
assert not subprocess.check_output(['git','status','--porcelain'])
assert subprocess.check_output(['git','rev-parse','HEAD'])==subprocess.check_output(['git','rev-parse','origin/main'])
p=Path('artifacts/automatic-commissions')
assert json.loads((p/'tests.json').read_text())['live_fixtures']==0
assert json.loads((p/'browser-preview.json').read_text())['no_live_mutations']
assert Path('/srv/sites/jakawi.club/rollback-automatic-commissions/baseline.json').is_file()
assert Path('/srv/sites/jakawi.club/rollback-automatic-commissions/manifest.json').is_file()
assert Path('app/public/build-next/manifest.json').is_file()
PY
docker compose config --quiet
# Apply only the reviewed additive approval metadata/check. No economic backfill.
docker compose exec -T --user www-data app php -d memory_limit=128M artisan migrate --force --path=database/migrations/2026_11_03_000000_automatic_commission_approval.php
python3 - <<'PY'
from pathlib import Path
import os,shutil
root=Path('app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json':continue
    target=root/'build'/source.relative_to(root/'build-next');target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert source.read_bytes()==target.read_bytes(),'Asset hash collision'
    else:shutil.copy2(source,target)
shutil.copy2(root/'build-next/manifest.json',root/'build/manifest.automatic-commissions.json')
os.replace(root/'build/manifest.automatic-commissions.json',root/'build/manifest.json')
PY
for cache in route:cache view:cache; do
    docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
# Owner explicitly requested this existing entry; use the same verifier, no repricing.
docker compose exec -T --user www-data app php -d memory_limit=128M artisan commissions:automatic --entry=1
