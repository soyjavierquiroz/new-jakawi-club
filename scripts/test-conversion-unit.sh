#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
docker run --rm --name jakawi-club-conversion-unit --network none \
    --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
    --env NODE_OPTIONS=--max-old-space-size=160 \
    --volume /opt/sites/jakawi.club/app:/workspace:ro \
    --volume /opt/sites/jakawi.club/scripts:/checks:ro \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node /checks/conversion-unit-tests.mjs
