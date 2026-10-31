#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd "$(dirname "$0")/.." && pwd)"
live_dir=/opt/sites/jakawi.club
rollback_dir=/srv/sites/jakawi.club/rollback-acquisition-10e
python3 - "$source_dir" "$live_dir" "$rollback_dir" <<'PY'
import sys,subprocess,json,os,shutil,hashlib
from pathlib import Path
source,live,backup=map(Path,sys.argv[1:]);os.umask(0o077)
assert not subprocess.check_output(['git','status','--porcelain'],cwd=source)
assert subprocess.check_output(['git','rev-parse','HEAD'],cwd=source)==subprocess.check_output(['git','rev-parse','origin/main'],cwd=source)
assert not subprocess.check_output(['git','status','--porcelain'],cwd=live)
assert len(json.loads((source/'artifacts/acquisition-10e/tests.json').read_text())['passed'])>=80
backup.mkdir(exist_ok=True)
assert not (backup/'baseline.json').exists(), 'Release baseline already exists; inspect before retrying'
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=live,text=True).strip()
(backup/'source.tar').write_bytes(subprocess.check_output(['git','archive',sha],cwd=live))
shutil.copy2(live/'app/public/build/manifest.json',backup/'manifest.json')
state=subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],input=(source/'scripts/acquisition-live-state.php').read_bytes(),cwd=live)
(backup/'ledger-before.json').write_bytes(state)
items=json.loads(subprocess.check_output(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
baseline={'sha':sha,'env':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').glob('*.env')},'containers':{i['Name'].lstrip('/'):{'id':i['Id'],'started_at':i['State']['StartedAt'],'restarts':i['RestartCount']} for i in items}}
(backup/'baseline.json').write_text(json.dumps(baseline,indent=2))
print('Rollback privado preparado; ledger LIVE capturado sin modificarlo.')
PY
docker run --rm --name jakawi-club-acquisition-migrate --network jakawi-club-private \
 --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
 --env-file /srv/sites/jakawi.club/secrets/app.env --env APP_CONFIG_CACHE=/tmp/acquisition-config.php --env APP_ROUTES_CACHE=/tmp/acquisition-routes.php --env LOG_CHANNEL=null \
 --volume "$source_dir/app:/var/www/html:ro" --volume /opt/sites/jakawi.club/app/vendor:/var/www/html/vendor:ro \
 --tmpfs /var/www/html/storage:rw,mode=1777,size=32m --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
 jakawi-club-php:8.4-base-v1 php artisan migrate --force --path=database/migrations/2026_11_01_200000_membership_acquisition_rates.php
cd "$live_dir"
git merge --ff-only origin/main
python3 - "$source_dir" <<'PY'
import sys,shutil,os
from pathlib import Path
source=Path(sys.argv[1])/'app/public/build-next'; target=Path('app/public/build')
for file in source.rglob('*'):
 if not file.is_file() or file.name=='manifest.json':continue
 dest=target/file.relative_to(source);dest.parent.mkdir(exist_ok=True,parents=True)
 if dest.exists(): assert dest.read_bytes()==file.read_bytes()
 else: shutil.copy2(file,dest)
shutil.copy2(source/'manifest.json',target/'manifest.acquisition.json');os.replace(target/'manifest.acquisition.json',target/'manifest.json')
PY
for cache in config:cache route:cache view:cache; do
 docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose exec -T --user www-data app php < scripts/publish-acquisition-rates.php
# No historical replay: only publish configuration; preserve every ledger row.
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
for attempt in $(seq 1 20); do
 if [ "$(docker inspect jakawi-club-app --format '{{.State.Health.Status}}')" = healthy ]; then break; fi
 sleep 2
done
JAKAWI_BASELINE_PATH="$rollback_dir/baseline.json" JAKAWI_EVIDENCE_DIR="$source_dir/artifacts/acquisition-10e" python3 scripts/validate-local.py
python3 - "$source_dir" "$rollback_dir" <<'PY'
import sys,subprocess,json,hashlib
from pathlib import Path
source,backup=map(Path,sys.argv[1:]); live=Path('/opt/sites/jakawi.club')
state=json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],input=(source/'scripts/acquisition-live-state.php').read_bytes(),cwd=live))
assert state==json.loads((backup/'ledger-before.json').read_text()), 'Ledger changed; inspect real concurrent activity'
baseline=json.loads((backup/'baseline.json').read_text())
assert baseline['env']=={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').glob('*.env')}
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=live,text=True).strip()
assert sha==subprocess.check_output(['git','rev-parse','origin/main'],cwd=live,text=True).strip()
(source/'artifacts/acquisition-10e/LIVE.json').write_text(json.dumps({'sha':sha,'up':200,'ledger_preserved':True,'contract_ready':False,'live_fixtures':0},indent=2))
print('SHA LIVE:',sha,'; /up 200; ledger histórico intacto; emisión 10E bloqueada por contrato COM faltante.')
PY
