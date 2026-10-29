#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
# One short-lived worker, the existing Laravel database queue and private credentials.
docker run --rm --name jakawi-club-crm-worker \
  --network jakawi-club-private --network jakawi-club-egress \
  --memory 192m --memory-swap 256m --cpus 0.35 --pids-limit 64 \
  --user 82:82 --env-file /srv/sites/jakawi.club/secrets/app.env \
  --volume /opt/sites/jakawi.club/app:/var/www/html:ro \
  --volume /srv/sites/jakawi.club/storage:/var/www/html/storage \
  --volume /srv/sites/jakawi.club/bootstrap-cache:/var/www/html/bootstrap/cache:ro \
  --volume /srv/sites/jakawi.club/secrets/crm-hmac:/run/secrets/crm_club_hmac:ro \
  --workdir /var/www/html jakawi-club-php:8.4-base-v1 \
  php -d memory_limit=96M artisan queue:work database --queue=crm --stop-when-empty --max-jobs=20 --max-time=40 --timeout=12 --tries=12 --sleep=1 --memory=90
