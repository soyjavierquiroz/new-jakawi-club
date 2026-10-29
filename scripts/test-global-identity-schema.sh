#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
# Additive migration checks in a temporary schema; never provision LIVE identities.
docker run --rm --name jakawi-club-global-identity-schema-tests --network jakawi-club-private \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env-file /srv/sites/jakawi.club/secrets/app.env \
    --env APP_ENV=testing --env APP_DEBUG=false --env LOG_CHANNEL=null \
    --env APP_CONFIG_CACHE=/tmp/jakawi-config.php --env APP_ROUTES_CACHE=/tmp/jakawi-routes.php \
    --env SESSION_DRIVER=database --env CACHE_STORE=array --env MAIL_MAILER=array --env BCRYPT_ROUNDS=4 \
    --volume /opt/sites/jakawi.club/app:/var/www/html:ro \
    --volume /opt/sites/jakawi.club/scripts:/tests:ro \
    --volume /opt/sites/jakawi.club/artifacts/global-identity:/evidence \
    --tmpfs /var/www/html/storage:rw,mode=1777,size=32m \
    --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
    jakawi-club-php:8.4-base-v1 php /tests/global-identity-schema-tests.php
