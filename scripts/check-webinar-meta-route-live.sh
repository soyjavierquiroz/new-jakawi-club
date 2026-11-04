#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
evidence_dir="${JAKAWI_EVIDENCE_DIR:-${source_dir}/artifacts/webinar-meta-route}"
# Reuse installed Chromium in a bounded container. Browser fixtures never create LIVE accounts.
docker run --rm --name jakawi-club-public-checkout-browser --network jakawi-club-egress \
    --memory 768m --memory-swap 896m --cpus 0.75 --pids-limit 256 \
    --env DISPLAY=:98 --volume /tmp/.X11-unix:/tmp/.X11-unix:ro \
    --env NODE_OPTIONS=--max-old-space-size=160 \
    --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
    --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
    --volume /usr/share/fonts:/usr/share/fonts:ro --volume /etc/fonts:/etc/fonts:ro \
    --volume ${source_dir}/app:/workspace:ro \
    --volume /opt/sites/jakawi.club/app/public/build:/workspace/public/build:ro \
    --volume "/srv/workspaces/jakawi/club/webinar-pixel-live/artifacts/pixel-helper-extension:/extension:ro" \
    --volume ${source_dir}/scripts:/checks:ro \
    --volume "$evidence_dir:/evidence" \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node /checks/check-webinar-meta-route-live.mjs "$@"
