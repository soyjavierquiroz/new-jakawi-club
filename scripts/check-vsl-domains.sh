#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
mkdir -p artifacts/vsl-recovery
run_probe() {
docker run --rm --name jakawi-club-vsl-domains --network jakawi-club-egress \
  --memory 512m --memory-swap 640m --cpus 0.75 --pids-limit 128 \
  --env NODE_OPTIONS=--max-old-space-size=160 \
  --volume /root/.cache/ms-playwright/chromium-1248/chrome-linux64:/browser:ro \
  --volume /usr/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:ro \
  --volume /usr/share/fonts:/usr/share/fonts:ro --volume /etc/fonts:/etc/fonts:ro \
  --volume /opt/sites/jakawi.club/scripts:/checks:ro \
  --volume /opt/sites/jakawi.club/artifacts/vsl-recovery:/evidence \
  node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d \
  node /checks/check-vsl-domains.mjs "$@"
}
if [ "$#" -gt 0 ]; then
  run_probe "$@"
  exit
fi
# A fresh bounded browser per page avoids accumulating YouTube renderer resources.
for width in 390 430 1440; do
  run_probe --target=com --width="$width"
  run_probe --target=club --width="$width"
done
run_probe --target=home --width=390
python3 - <<'PY'
from pathlib import Path
import json
p = Path('/opt/sites/jakawi.club/artifacts/vsl-recovery')
files = [p / f'live-{target}-{width}.json' for width in [390, 430, 1440] for target in ['com', 'club']] + [p / 'live-home-390.json']
reports = [json.loads(f.read_text()) for f in files]
result = reports[0]
assert all(r['browser'] == result['browser'] for r in reports)
result['pages'] = [page for r in reports for page in r['pages']]
result['isolated_browser_process_per_page'] = True
(p / 'live-domains.json').write_text(json.dumps(result, indent=2) + '\n')
PY
