#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
evidence_dir="${JAKAWI_EVIDENCE_DIR:-${source_dir}/artifacts/admin-dashboard}"
# Reuse installed Chromium in a bounded container. Browser fixtures never create LIVE accounts.
widths=(390 1440)
for arg in "$@"; do
    case "$arg" in --width=390) widths=(390);; --width=1440) widths=(1440);; esac
done
for width in "${widths[@]}"; do
docker run --rm --name jakawi-club-member-access-browser --network jakawi-club-egress \
    --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
    --env NODE_OPTIONS=--max-old-space-size=160 \
    --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
    --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
    --volume /usr/share/fonts:/usr/share/fonts:ro --volume /etc/fonts:/etc/fonts:ro \
    --volume ${source_dir}/app:/workspace:ro \
    --volume /opt/sites/jakawi.club/app/public/build:/workspace/public/build:ro \
    --volume ${source_dir}/scripts:/checks:ro \
    --volume "$evidence_dir:/evidence" \
    node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node /checks/check-admin-dashboard.mjs --width="$width" "$@"

done
python3 - "$evidence_dir" "$@" <<'PYCODE'
import json,sys
from pathlib import Path
mode='live' if '--live' in sys.argv else 'preview'
p=Path(sys.argv[1])
if not all((p/f'browser-{mode}-{width}.json').exists() for width in [390,1440]): sys.exit(0)
results=[json.loads((p/f'browser-{mode}-{width}.json').read_text()) for width in [390,1440]]
if results[0]['manifest_sha256']!=results[1]['manifest_sha256']:
    (p/f'browser-{mode}.json').unlink(missing_ok=True)
    print('Viewport passed; matching other viewport pending.')
    sys.exit(0)
assert all(r['no_live_mutations'] and not r['runtimeErrors'] and not r['failedAssets'] for r in results)
(p/f'browser-{mode}.json').write_text(json.dumps({'manifest_sha256':results[0]['manifest_sha256'],'checks':[c for r in results for c in r['checks']], 'no_live_mutations':True, 'protected_pages':results[0]['protected_pages']},indent=2)+'\n')
PYCODE
