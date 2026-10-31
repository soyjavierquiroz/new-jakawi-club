#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
evidence_dir="${JAKAWI_EVIDENCE_DIR:-${source_dir}/artifacts/minio-media}"
mkdir -p "$evidence_dir"
case "${1:-catalog}" in
  catalog) runner=catalog-tests.php ;;
  landings) runner=landing-tests.php ;;
  *) echo 'Usage: test-media.sh [catalog|landings]' >&2; exit 2 ;;
esac
build_dir=/opt/sites/jakawi.club/app/public/build
if [ -f "$source_dir/app/public/build-next/manifest.json" ]; then build_dir="$source_dir/app/public/build-next"; fi
docker run --rm --name jakawi-club-media-tests --network jakawi-club-private \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env-file /srv/sites/jakawi.club/secrets/app.env \
    --env APP_ENV=testing --env APP_DEBUG=false --env LOG_CHANNEL=null \
    --env APP_CONFIG_CACHE=/tmp/commercial-config.php --env APP_ROUTES_CACHE=/tmp/commercial-routes.php \
    --env JAKAWI_MEDIA_TESTS=1 \
    --env SESSION_DRIVER=database --env CACHE_STORE=array --env MAIL_MAILER=array --env BCRYPT_ROUNDS=4 \
    --volume "$source_dir/app:/var/www/html:ro" \
    --volume "$build_dir:/var/www/html/public/build:ro" \
    --volume "$source_dir/scripts/fixtures:/var/www/html/public/marketing:ro" \
    --volume "$source_dir/docs:/reference:ro" \
    --volume "$source_dir/docs/landings:/var/www/docs/landings:ro" \
    --volume "$source_dir/scripts:/tests:ro" \
    --volume "$evidence_dir:/evidence" \
    --tmpfs /var/www/html/storage:rw,mode=1777,size=32m \
    --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
    jakawi-club-php:8.4-media-v1 sh -c 'php /tests/media-normalization-tests.php && php "$1"' media-tests "/tests/$runner"
