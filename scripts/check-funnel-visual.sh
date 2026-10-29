#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
evidence_dir="${JAKAWI_EVIDENCE_DIR:-/opt/sites/jakawi.club/artifacts/funnel-editor}"
# Reuse installed Chromium in a bounded container. Browser fixtures never create LIVE accounts.
docker run --rm --name jakawi-club-funnel-browser --network jakawi-club-egress \
    --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
    --env NODE_OPTIONS=--max-old-space-size=160 \
    --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
    --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
    --volume /usr/share/fonts:/usr/share/fonts:ro --volume /etc/fonts:/etc/fonts:ro \
    --volume /opt/sites/jakawi.club/app:/workspace:ro \
    --volume /opt/sites/jakawi.club/scripts:/checks:ro \
    --volume "$evidence_dir:/evidence" \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node /checks/check-funnel-visual.mjs "$@"
