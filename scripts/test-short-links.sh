#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
evidence_dir="${JAKAWI_EVIDENCE_DIR:-${source_dir}/artifacts/short-links-07a}"
mkdir -p "$evidence_dir"
build_dir=/opt/sites/jakawi.club/app/public/build
if [ -f "$source_dir/app/public/build-next/manifest.json" ]; then build_dir="$source_dir/app/public/build-next"; fi
docker run --rm --name jakawi-club-short-links-tests --network jakawi-club-private \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env-file /srv/sites/jakawi.club/secrets/app.env \
    --env APP_ENV=testing --env APP_DEBUG=false --env LOG_CHANNEL=null \
    --env APP_CONFIG_CACHE=/tmp/commercial-config.php --env APP_ROUTES_CACHE=/tmp/commercial-routes.php \
    --env SESSION_DRIVER=database --env CACHE_STORE=array --env MAIL_MAILER=array --env BCRYPT_ROUNDS=4 \
    --volume "$source_dir/app:/var/www/html:ro" \
    --volume /opt/sites/jakawi.club/app/vendor:/var/www/html/vendor:ro \
    --volume "$build_dir:/var/www/html/public/build:ro" \
    --volume "$source_dir/scripts:/tests:ro" \
    --volume "$evidence_dir:/evidence" \
    --tmpfs /var/www/html/storage:rw,mode=1777,size=32m \
    --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
    jakawi-club-php:8.4-base-v1 php /tests/short-links-tests.php
