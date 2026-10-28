#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
# Reuse the installed browser, read-only host runtime libraries and the existing bounded Node image.
# No browser/library installation, server, published port or changes to other containers.
docker run --rm --name jakawi-club-identity-visual --network jakawi-club-egress \
    --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
    --env NODE_OPTIONS=--max-old-space-size=160 \
    --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
    --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
    --volume /usr/share/fonts:/usr/share/fonts:ro \
    --volume /etc/fonts:/etc/fonts:ro \
    --volume /root/jakawi-build-v1/repository/app/resources/css/theme.css:/reference/theme.css:ro \
    --volume /opt/sites/jakawi.club/scripts:/checks:ro \
    --volume /opt/sites/jakawi.club/artifacts/identity:/evidence \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d \
    node /checks/check-identity-visual.mjs
