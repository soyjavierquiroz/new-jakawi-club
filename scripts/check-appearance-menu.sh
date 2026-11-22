#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$PWD"
evidence_dir="${JAKAWI_EVIDENCE_DIR:?Set reviewed evidence path}"
network=none
check=check-appearance-menu.cjs
if [ "${1:-}" = --public ]; then network=jakawi-club-egress; check=check-appearance-menu-public.cjs; shift; fi
docker run --rm --name jakawi-club-appearance-menu-qa --network "$network" \
 --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
 --env NODE_OPTIONS=--max-old-space-size=160 \
 --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
 --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
 --volume /usr/share/fonts:/usr/share/fonts:ro --volume /etc/fonts:/etc/fonts:ro \
 --volume /srv/workspaces/jakawi/com/global-appearance-v1-evidence/tools:/tools:ro \
 --volume "$source_dir/app:/workspace:ro" --volume "$source_dir/scripts:/checks:ro" \
 --volume /opt/sites/jakawi.club/app/public/build:/live-build:ro \
 --volume "$evidence_dir:/evidence" \
 node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node "/checks/$check" "$@"
