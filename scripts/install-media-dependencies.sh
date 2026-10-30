#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm --name jakawi-club-media-composer --network jakawi-club-egress \
  --memory 384m --memory-swap 512m --cpus 0.75 --pids-limit 128 \
  --env COMPOSER_ALLOW_SUPERUSER=1 --env COMPOSER_MEMORY_LIMIT=320M \
  --env COMPOSER_MAX_PARALLEL_HTTP=1 --env COMPOSER_MAX_PARALLEL_PROCESSES=1 \
  --volume "$PWD/app:/var/www/html" --workdir /var/www/html jakawi-club-php:8.4-media-v1 \
  composer require 'league/flysystem-aws-s3-v3:^3.0' 'ext-gd:*' 'ext-exif:*' --update-no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
