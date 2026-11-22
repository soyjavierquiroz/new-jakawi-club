#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$PWD"
evidence_dir="${JAKAWI_EVIDENCE_DIR:?Set reviewed evidence path}"
network=none
check=check-appearance-menu.cjs
if [ "${1:-}" = --public ]; then network=jakawi-club-egress; check=check-appearance-menu-public.cjs; shift; fi
for viewport_width in 390 1440; do
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
 node:22-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d node "/checks/$check" "$@" "--width=$viewport_width"
done
python3 - "$evidence_dir" "$check" "$@" <<'PY'
import json,sys,pathlib
p=pathlib.Path(sys.argv[1]);prefix="public-live" if "public" in sys.argv[2] else "live-browser" if "--live" in sys.argv else "browser"
rows=[json.loads((p/(prefix+"-"+str(w)+".json")).read_text())for w in [390,1440]]
assert all(r["result"]=="PASS" for r in rows)
result={**rows[0],"checks":[c for r in rows for c in r["checks"]],"errors":[e for r in rows for e in r["errors"]]}
if "contrasts" in rows[0]:result["contrasts"]=[c for r in rows for c in r["contrasts"]]
(p/(prefix+".json")).write_text(json.dumps(result,indent=2))
print(prefix,"PASS",len(result["checks"]),"cases")
PY
