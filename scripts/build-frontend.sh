#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
# Validate and build sequentially using this checkout only.
if [ "$source_dir" = /opt/sites/jakawi.club ]; then
    echo "Build from /srv/repos/jakawi.club or its isolated worktrees." >&2
    exit 1
fi
if [ ! -d "$source_dir/app/node_modules" ]; then
    echo "Run bash scripts/prepare-source-build.sh first." >&2
    exit 1
fi
for step in typecheck build; do
    if [ "$step" = typecheck ]; then
        command=(npm run typecheck)
    else
        command=(npm run build -- --outDir public/build-next)
    fi
    docker run --rm --name "jakawi-club-node-$step" --network none \
        --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
        --env NODE_OPTIONS=--max-old-space-size=320 \
        --env npm_config_jobs=1 --env RAYON_NUM_THREADS=1 --env UV_THREADPOOL_SIZE=1 \
        --volume "$source_dir/app:/workspace" \
        --workdir /workspace \
        node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d "${command[@]}"
done
