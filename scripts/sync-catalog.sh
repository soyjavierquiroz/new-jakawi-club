#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
# Bounded, ephemeral CLUB-only process. No per-visitor COM requests.
docker run --rm --name jakawi-club-catalog-sync \
  --network jakawi-club-private --network jakawi-club-egress \
  --memory 192m --memory-swap 256m --cpus 0.35 --pids-limit 64 \
  --user 82:82 --env-file /srv/sites/jakawi.club/secrets/app.env \
  --volume /opt/sites/jakawi.club/app:/var/www/html:ro \
  --volume /srv/sites/jakawi.club/storage:/var/www/html/storage \
  --volume /srv/sites/jakawi.club/bootstrap-cache:/var/www/html/bootstrap/cache:ro \
  --workdir /var/www/html jakawi-club-php:8.4-base-v1 \
  php -d memory_limit=128M artisan catalog:sync
