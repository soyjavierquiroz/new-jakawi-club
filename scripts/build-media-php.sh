#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
builder=jakawi-club-media-builder
docker buildx create --name "$builder" --driver docker-container --driver-opt network=jakawi-club-egress --driver-opt memory=640m --driver-opt memory-swap=896m --driver-opt cpu-quota=75000 --buildkitd-config docker/buildkitd.toml >/dev/null
trap 'docker buildx rm "$builder" >/dev/null' EXIT
docker buildx build --builder "$builder" --progress plain --load --tag jakawi-club-php:8.4-media-v1 --file docker/php/Dockerfile .
