#!/usr/bin/env bash
# Run from main only after the isolated tests and real one-object storage check.
set -euo pipefail
cd /opt/sites/jakawi.club
source_dir=/opt/sites/jakawi.club/.worktrees/minio-media
private_dir=/srv/sites/jakawi.club/rollback-minio-media
python3 - <<'PY'
from pathlib import Path
import json,subprocess
root=Path('/opt/sites/jakawi.club')
out=root/'artifacts/minio-media'
assert subprocess.check_output(['git','branch','--show-current'],cwd=root).strip()==b'main'
assert not subprocess.check_output(['git','status','--porcelain'],cwd=root)
assert subprocess.check_output(['git','rev-parse','HEAD'],cwd=root)==subprocess.check_output(['git','rev-parse','origin/main'],cwd=root)
assert json.loads((out/'storage-live.json').read_text())['passed']
assert json.loads((out/'storage-live.json').read_text())['controlled_cleanup']
assert json.loads((out/'browser-preview.json').read_text())['no_live_mutations']
assert json.loads((out/'tests.json').read_text())['live_fixtures']==0
assert Path('/srv/sites/jakawi.club/secrets/media.env').is_file()
PY
test -f "$private_dir/baseline.json"
test ! -e "$private_dir/vendor"
test ! -e "$private_dir/build"
# Preserve only CLUB's runtime, vendor, assets and container state; no shared services.
docker image inspect jakawi-club-php:8.4-base-v1 --format '{{.Id}}' > "$private_dir/php-image-id"
python3 - <<'BASELINE'
from pathlib import Path
import json,subprocess
rows=json.loads(subprocess.check_output(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
result={c['Name']:{'id':c['Id'],'image':c['Image'],'started_at':c['State']['StartedAt'],'networks':sorted(c['NetworkSettings']['Networks'])} for c in rows}
Path('/srv/sites/jakawi.club/rollback-minio-media/containers.json').write_text(json.dumps(result,indent=2)+'\n')
BASELINE
git rev-parse 2c93a415573ce9faf3fb19e171f439acee8fa5dd > "$private_dir/baseline-sha"
git show 2c93a415573ce9faf3fb19e171f439acee8fa5dd:compose.yaml > "$private_dir/compose.yaml"
git show 2c93a415573ce9faf3fb19e171f439acee8fa5dd:docker/nginx/default.conf > "$private_dir/nginx.conf"
cp -a app/vendor "$private_dir/vendor"
cp -a app/public/build "$private_dir/build"
python3 - <<'PY'
from pathlib import Path
import shutil,os
root=Path('/opt/sites/jakawi.club')
source=root/'.worktrees/minio-media'
# New packages first, Composer maps last. Keep old packages for runtime compatibility.
for item in (source/'app/vendor').iterdir():
    if item.name in ['composer','autoload.php']:continue
    target=root/'app/vendor'/item.name
    if item.is_dir():shutil.copytree(item,target,dirs_exist_ok=True)
    else:shutil.copy2(item,target)
shutil.copytree(source/'app/vendor/composer',root/'app/vendor/composer',dirs_exist_ok=True)
shutil.copy2(source/'app/vendor/autoload.php',root/'app/vendor/autoload.php')
for item in (source/'app/public/build-next').rglob('*'):
    if not item.is_file() or item.name=='manifest.json':continue
    target=root/'app/public/build'/item.relative_to(source/'app/public/build-next')
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():assert item.read_bytes()==target.read_bytes()
    else:shutil.copy2(item,target)
shutil.copy2(source/'app/public/build-next/manifest.json',root/'app/public/build/manifest.media-next.json')
os.replace(root/'app/public/build/manifest.media-next.json',root/'app/public/build/manifest.json')
PY
docker image tag jakawi-club-php:8.4-media-v1 jakawi-club-php:8.4-base-v1
docker compose up -d --no-deps --force-recreate app
# The new env_file is read on recreation. This updates only CLUB configuration.
docker compose exec -T --user www-data app php artisan config:cache
docker compose exec -T --user www-data app php artisan migrate --force --path=database/migrations/2026_10_30_000007_create_marketing_landing_snapshots.php
docker compose exec -T --user www-data app php artisan route:cache
docker compose exec -T --user www-data app php artisan view:cache
docker compose restart app
# Reload the single-file bind mount and multipart limit; no Traefik changes.
docker compose up -d --no-deps --force-recreate web
curl --fail --silent --show-error --max-time 20 https://jakawi.club/up -o /dev/null
