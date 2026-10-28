#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
# Fetch only a pinned static asset archive; no global or runtime package installation.
docker run --rm --name jakawi-club-country-flags --network jakawi-club-egress \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env NODE_OPTIONS=--max-old-space-size=160 --env npm_config_jobs=1 \
    --volume /opt/sites/jakawi.club/app:/workspace \
    --volume /opt/sites/jakawi.club/scripts:/checks:ro \
    --workdir /tmp \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d \
    bash -c 'archive=$(npm pack country-flag-icons@1.6.20 --silent); tar -xzf "$archive"; node /checks/prepare-country-flags.mjs'
