#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club

builder=jakawi-club-builder
python3 scripts/prepare-host.py
if ! docker buildx inspect "$builder" >/dev/null 2>&1; then
    docker buildx create --name "$builder" --driver docker-container \
        --driver-opt network=jakawi-club-egress \
        --driver-opt memory=640m --driver-opt memory-swap=896m \
        --driver-opt cpu-quota=75000 --buildkitd-config docker/buildkitd.toml
fi
trap 'docker buildx rm "$builder" >/dev/null' EXIT
docker buildx build --builder "$builder" --progress plain --load \
    --tag jakawi-club-php:8.4-base-v1 --file docker/php/Dockerfile .
