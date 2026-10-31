#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - <<'PY'
from pathlib import Path
import json
p=Path('/srv/sites/jakawi.club/secrets/media.env')
if not p.is_file(): raise SystemExit('Missing authorized MinIO credentials in private media.env.')
if p.stat().st_mode & 0o077 or p.stat().st_uid != 0: raise SystemExit('Private media.env must be owned by root with mode 0600.')
values={line.split('=',1)[0]:line.split('=',1)[1].strip().strip('"').strip("'") for line in p.read_text().splitlines() if '=' in line and not line.startswith('#')}
com=json.loads(Path('/opt/sites/jakawi.com/compose.yaml').read_text())['services']['app']['environment']
for key in ['MEDIA_AWS_ACCESS_KEY_ID','MEDIA_AWS_SECRET_ACCESS_KEY']:
    if not values.get(key) or values[key]!=com.get(key): raise SystemExit('Credentials must match the owner-authorized temporary COM configuration.')
print('Owner-authorized shared credentials configured privately; CLUB object prefixes remain isolated.')
PY
docker run --rm --name jakawi-club-media-storage-check --network jakawi-club-egress \
  --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
  --env-file /srv/sites/jakawi.club/secrets/app.env \
  --env-file /srv/sites/jakawi.club/secrets/media.env \
  --env-file /srv/sites/jakawi.club/secrets/imgproxy.env \
  --env APP_CONFIG_CACHE=/tmp/media-config.php --env LOG_CHANNEL=null \
  --volume "$PWD/app:/var/www/html:ro" --volume "$PWD/scripts:/tests:ro" \
  --volume "$PWD/artifacts/minio-media:/evidence" \
  --tmpfs /var/www/html/storage:rw,mode=1777,size=32m \
  --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
  jakawi-club-php:8.4-media-v1 php /tests/media-live-storage.php
