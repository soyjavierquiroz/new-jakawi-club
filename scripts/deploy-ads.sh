#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json, subprocess
from pathlib import Path
assert not subprocess.check_output(['git','status','--porcelain'])
assert subprocess.check_output(['git','rev-parse','HEAD'])==subprocess.check_output(['git','rev-parse','origin/main'])
assert json.loads(Path('artifacts/ads-phase09/tests.json').read_text())['live_fixtures']==0
assert json.loads(Path('artifacts/ads-phase09/browser-preview.json').read_text())['provider_requests_stubbed']
assert Path('/srv/sites/jakawi.club/rollback-ads-phase09/baseline.json').is_file()
PY
docker compose config --quiet
# Only the additive advertising migration; keep existing business data.
docker compose exec -T --user www-data app php -d memory_limit=128M artisan migrate --force --path=database/migrations/2026_10_31_230000_create_advertising_tables.php
python3 - <<'PY'
from pathlib import Path
import os,shutil
root=Path('app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json':continue
    target=root/'build'/source.relative_to(root/'build-next');target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert source.read_bytes()==target.read_bytes()
    else:shutil.copy2(source,target)
shutil.copy2(root/'build-next/manifest.json',root/'build/manifest.ads.json')
os.replace(root/'build/manifest.ads.json',root/'build/manifest.json')
PY
for cache in config:cache route:cache view:cache; do
    docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
