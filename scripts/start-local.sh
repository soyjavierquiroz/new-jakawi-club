#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club

python3 scripts/prepare-host.py
docker compose config --quiet
COMPOSE_PARALLEL_LIMIT=1 docker compose up --detach --wait --wait-timeout 90 db app
docker compose exec -T --user www-data app php artisan package:discover --no-interaction
docker compose exec -T --user www-data app php artisan migrate --force --no-interaction
docker compose exec -T --user www-data app php artisan config:cache --no-interaction
docker compose exec -T --user www-data app php artisan route:cache --no-interaction
docker compose exec -T --user www-data app php artisan view:cache --no-interaction
COMPOSE_PARALLEL_LIMIT=1 docker compose up --detach --wait --wait-timeout 90 web
printf '%s\n' 'Local validation: http://127.0.0.1:18082'
