#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export JAKAWI_ACTIVATION_URL_TESTS=1
export JAKAWI_COM_SHA=fabbd76daed53c660d0e2744eaac089ec8ca18f0
export JAKAWI_EVIDENCE_DIR="$(pwd)/artifacts/activation-url"
export JAKAWI_ROLLBACK_DIR=/srv/sites/jakawi.club/rollback-activation-url
python3 scripts/test-membership-sso.py
