#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club

php_image=jakawi-club-php:8.4-base-v1
node_image=node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d
python3 scripts/prepare-host.py

docker run --rm --name jakawi-club-composer --network jakawi-club-egress \
    --memory 384m --memory-swap 512m --cpus 0.75 --pids-limit 128 \
    --env COMPOSER_ALLOW_SUPERUSER=1 --env COMPOSER_MEMORY_LIMIT=320M \
    --env COMPOSER_MAX_PARALLEL_HTTP=1 --env COMPOSER_MAX_PARALLEL_PROCESSES=1 \
    --volume /opt/sites/jakawi.club/app:/var/www/html \
    "$php_image" composer install --no-dev --prefer-dist --no-interaction \
    --no-progress --no-scripts --no-plugins --optimize-autoloader

# Separate containers guarantee installation, type checking and build run sequentially.
for step in install typecheck build; do
    case "$step" in
        install) command=(npm ci --no-audit --no-fund) ;;
        typecheck) command=(npm run typecheck) ;;
        build) command=(npm run build) ;;
    esac
    docker run --rm --name "jakawi-club-node-$step" --network jakawi-club-egress \
        --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
        --env NODE_OPTIONS=--max-old-space-size=320 \
        --env npm_config_jobs=1 --env RAYON_NUM_THREADS=1 --env UV_THREADPOOL_SIZE=1 \
        --volume /opt/sites/jakawi.club/app:/workspace --workdir /workspace \
        "$node_image" "${command[@]}"
done
