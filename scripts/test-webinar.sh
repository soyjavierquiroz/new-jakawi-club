#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
evidence_dir="${JAKAWI_EVIDENCE_DIR:-$source_dir/artifacts/webinar}"
mkdir -p "$evidence_dir"
# Run the existing focused suites against an isolated source tree, never LIVE files.
case "${1:-landings}" in
    landings) runner=landing-tests.php ;;
    postconversion) runner=postconversion-tests.php ;;
    mvp) runner=mvp-tests.php ;;
    commissions) runner=commission-tests.php ;;
    affiliates) runner=affiliate-tests.php ;;
    commercial) runner=commercial-tests.php ;;
    short-links) runner=short-links-tests.php ;;
    identity) runner=identity-link-tests.php ;;
    *) echo 'Usage: test-webinar.sh [landings|postconversion|mvp|commissions|affiliates|commercial|short-links|identity]' >&2; exit 2 ;;
esac
docker run --rm --name jakawi-club-webinar-tests --network jakawi-club-private \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env-file /srv/sites/jakawi.club/secrets/app.env \
    --env APP_ENV=testing --env APP_DEBUG=false --env LOG_CHANNEL=null \
    --env APP_CONFIG_CACHE=/tmp/jakawi-config.php --env APP_ROUTES_CACHE=/tmp/jakawi-routes.php \
    --env SESSION_DRIVER=database --env CACHE_STORE=array --env MAIL_MAILER=array --env BCRYPT_ROUNDS=4 \
    --volume "$source_dir/app:/var/www/html:ro" \
    --volume /opt/sites/jakawi.club/app/vendor:/var/www/html/vendor:ro \
    --volume /opt/sites/jakawi.club/app/public/build:/var/www/html/public/build:ro \
    --volume "$source_dir/scripts:/tests:ro" \
    --volume "$source_dir/docs:/reference:ro" \
    --volume "$source_dir/scripts/fixtures:/var/www/html/public/marketing:ro" \
    --volume "$evidence_dir:/evidence" \
    --tmpfs /var/www/html/storage:rw,mode=1777,size=32m \
    --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
    jakawi-club-php:8.4-base-v1 php "/tests/$runner"
