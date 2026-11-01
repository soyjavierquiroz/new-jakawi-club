#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd "$(dirname "$0")/.." && pwd -P)"
case "$source_dir" in
    /srv/repos/jakawi.club|/srv/workspaces/jakawi/club/*) ;;
    *) echo "Use the canonical CLUB source or its isolated worktrees." >&2; exit 1 ;;
esac
# Only frontend dependencies; no env files, PHP hooks, services or LIVE mounts.
docker run --rm --name jakawi-club-source-npm --network jakawi-club-egress \
    --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
    --env NODE_OPTIONS=--max-old-space-size=320 \
    --env npm_config_jobs=1 --env RAYON_NUM_THREADS=1 --env UV_THREADPOOL_SIZE=1 \
    --volume "$source_dir/app:/workspace" --workdir /workspace \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d \
    npm ci --no-audit --no-fund
