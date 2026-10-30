#!/usr/bin/env bash
# Explicit CLUB-only 07B2 release. Run after focused tests and frontend build.
set -euo pipefail
cd /opt/sites/jakawi.club
python3 - <<'PY'
import json
from pathlib import Path
root=Path('/opt/sites/jakawi.club')
assert json.loads((root/'artifacts/catalog-07b2/tests.json').read_text())['live_fixtures']==0
assert json.loads((root/'artifacts/catalog-07b2/browser-preview.json').read_text())['no_live_mutations']
assert (root/'app/public/build-next/manifest.json').is_file()
assert Path('/srv/sites/jakawi.club/rollback-catalog-07b2/baseline.json').is_file()
PY
docker compose exec -T --user www-data app php -d memory_limit=128M artisan migrate --force --path=database/migrations/2026_10_30_000005_extend_commercial_catalog.php
bash scripts/sync-catalog.sh
python3 - <<'PY'
from pathlib import Path
import os, shutil
root=Path('/opt/sites/jakawi.club/app/public')
for source in (root/'build-next').rglob('*'):
    if not source.is_file() or source.name=='manifest.json': continue
    target=root/'build'/source.relative_to(root/'build-next')
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists(): assert source.read_bytes()==target.read_bytes(), 'Asset hash collision'
    else: shutil.copy2(source, target)
shutil.copy2(root/'build-next/manifest.json', root/'build/manifest.catalog-next.json')
os.replace(root/'build/manifest.catalog-next.json', root/'build/manifest.json')
PY
for cache in config:cache route:cache view:cache; do
    docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
install -m 0644 scripts/systemd/jakawi-club-catalog.service /etc/systemd/system/jakawi-club-catalog.service
install -m 0644 scripts/systemd/jakawi-club-catalog.timer /etc/systemd/system/jakawi-club-catalog.timer
systemctl daemon-reload
systemctl enable --now jakawi-club-catalog.timer
curl --fail --silent --show-error --max-time 20 https://jakawi.club/up -o /dev/null
