#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd "$(dirname "$0")/.." && pwd)"
live_dir=/opt/sites/jakawi.club
rollback_dir=/srv/sites/jakawi.club/rollback-partners-10c
python3 - "$source_dir" "$live_dir" "$rollback_dir" <<'PY'
import sys,subprocess,json,hashlib,os,shutil
from pathlib import Path
source,live,backup=map(Path,sys.argv[1:]);os.umask(0o077)
assert not subprocess.check_output(['git','status','--porcelain'],cwd=source)
assert subprocess.check_output(['git','rev-parse','HEAD'],cwd=source)==subprocess.check_output(['git','rev-parse','origin/main'],cwd=source)
assert not subprocess.check_output(['git','status','--porcelain'],cwd=live)
assert len(json.loads((source/'artifacts/partners-10c/tests.json').read_text())['passed'])>=35
assert json.loads((source/'artifacts/partners-10c/browser-preview.json').read_text())['no_live_mutations']
backup.mkdir(exist_ok=True)
if not (backup/'baseline.json').exists():
 baseline={'sha':subprocess.check_output(['git','rev-parse','HEAD'],cwd=live,text=True).strip(),
  'env':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').glob('*.env')},
  'other_containers':{name:json.loads(subprocess.check_output(['docker','inspect',name]))[0]['State']['StartedAt'] for name in ['jakawi-app-1','jakawi-worker-1','jakawi-scheduler-1','crm-jakawi-private-app-1']}}
 (backup/'source.tar').write_bytes(subprocess.check_output(['git','archive',baseline['sha']],cwd=live))
 shutil.copy2(live/'app/public/build/manifest.json',backup/'manifest.json')
 (backup/'baseline.json').write_text(json.dumps(baseline,indent=2))
items=json.loads(subprocess.check_output(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
validation={'containers':{i['Name'].lstrip('/'):{'id':i['Id'],'started_at':i['State']['StartedAt'],'restarts':i['RestartCount']} for i in items}}
(backup/'validation-baseline.json').write_text(json.dumps(validation,indent=2))
print('Rollback privado preparado; fuente probada coincide con origin/main.')
PY
# Migrate using the tested checkout before switching the live source.
docker run --rm --name jakawi-club-partner-migrate --network jakawi-club-private \
 --memory 256m --memory-swap 320m --cpus 0.65 --pids-limit 96 \
 --env-file /srv/sites/jakawi.club/secrets/app.env \
 --env APP_CONFIG_CACHE=/tmp/partner-config.php --env APP_ROUTES_CACHE=/tmp/partner-routes.php --env LOG_CHANNEL=null \
 --volume "$source_dir/app:/var/www/html:ro" --volume /opt/sites/jakawi.club/app/vendor:/var/www/html/vendor:ro \
 --tmpfs /var/www/html/storage:rw,mode=1777,size=32m --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777,size=8m \
 jakawi-club-php:8.4-base-v1 php artisan migrate --force --path=database/migrations/2026_11_01_000000_partner_acquisition.php
cd "$live_dir"
git merge --ff-only origin/main
python3 - "$source_dir" <<'PY'
import sys,shutil,os
from pathlib import Path
source=Path(sys.argv[1])/'app/public/build-next';target=Path('app/public/build')
for file in source.rglob('*'):
 if not file.is_file() or file.name=='manifest.json':continue
 dest=target/file.relative_to(source);dest.parent.mkdir(exist_ok=True,parents=True)
 if dest.exists():assert dest.read_bytes()==file.read_bytes()
 else:shutil.copy2(file,dest)
shutil.copy2(source/'manifest.json',target/'manifest.partners.json');os.replace(target/'manifest.partners.json',target/'manifest.json')
PY
for cache in config:cache route:cache view:cache; do
 docker compose exec -T --user www-data app php -d memory_limit=128M artisan "$cache"
done
docker compose restart app
curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-all-errors --max-time 20 https://jakawi.club/up -o /dev/null
# Genuine minimal business reference; never create a user, purchase, rule or permission.
docker compose exec -T --user www-data app php <<'PHP'
<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$offer=DB::table('commercial_offers')->where('key','dossis-43')->first();
$partners=$offer ? json_decode($offer->partners,true,32,JSON_THROW_ON_ERROR) : [];
$verified=collect($partners)->first(fn($p)=>(string)$p['id']==='1' && str_contains($p['name'],'Dossis'));
if (!$verified) throw new RuntimeException('Dossis original COM reference not verified.');
DB::table('affiliate_partners')->insertOrIgnore(['source'=>'jakawi.com','partner_id'=>'1','name'=>$verified['name'],'status'=>'enabled','com_url'=>'https://jakawi.com/dossis43','code'=>Illuminate\Support\Str::random(24),'created_at'=>now(),'updated_at'=>now()]);
app(App\Services\PartnerAcquisition::class)->replay();
echo "Referencia comercial Dossis COM 1 habilitada; sin representantes ni reglas nuevas.\n";
PHP
JAKAWI_BASELINE_PATH="$rollback_dir/validation-baseline.json" JAKAWI_EVIDENCE_DIR="$source_dir/artifacts/partners-10c" python3 scripts/validate-local.py
