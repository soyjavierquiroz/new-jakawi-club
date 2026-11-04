#!/usr/bin/env bash
# Explicitly authorized CLUB-only release; one additive migration, no queues or external writes.
set -euo pipefail
cd "$(dirname "$0")/.."
source_dir="$(pwd)"
python3 - "$source_dir" <<'PY'
import hashlib,json,shutil,subprocess
from pathlib import Path
import os
import sys
source=Path(sys.argv[1]); live=Path('/opt/sites/jakawi.club')
assert source.is_relative_to('/srv/workspaces/jakawi/club')
run=lambda args,**kw:subprocess.run(args,cwd=live,check=True,**kw)
read=lambda args:subprocess.check_output(args,cwd=live,text=True).strip()
assert not subprocess.check_output(['git','status','--porcelain'],cwd=source)
assert not read(['git','status','--porcelain'])
previous=read(['git','rev-parse','HEAD'])
assert previous=='734abb057e1ac51581fd4c6779ee3a5d5be9550c','Changed LIVE baseline requires review'
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=source,text=True).strip()
run(['git','fetch',str(source),sha])
run(['git','merge-base','--is-ancestor',previous,sha])
changed=read(['git','diff','--name-only',previous,sha]).splitlines()
allowed={'.gitignore','app/app/Http/Controllers/AuthController.php','app/app/Notifications/ActivateAccount.php','app/app/Services/AccountMail.php','app/app/Services/ActivationTokens.php','app/resources/js/pages/Auth.tsx','app/resources/views/mail/activate-account.blade.php','app/database/migrations/2026_11_04_120000_create_account_activation_tokens_table.php','scripts/webinar-meta-tests.php','artifacts/webinar-meta-route/tests.json'}
assert all(p in allowed or p.startswith(('scripts/activation-durable','scripts/test-activation-durable','scripts/check-activation-durable','scripts/deploy-activation-durable','artifacts/activation-durable/')) for p in changed), 'Unexpected release scope'
evidence=source/'artifacts/activation-durable'
assert json.loads((evidence/'tests.json').read_text())['passed']>=30
assert len(json.loads((evidence/'browser.json').read_text())['checks'])==6
assert json.loads((source/'artifacts/webinar-meta-route/tests.json').read_text())['passed']>=37
build=source/'app/public/build-next'
assert json.loads((evidence/'browser.json').read_text())['manifest_sha256']==hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest()
rollback=Path('/srv/sites/jakawi.club/rollback-activation-durable');rollback.mkdir(exist_ok=False)
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json')
(rollback/'release.json').write_text(json.dumps({'previous_sha':previous,'target_sha':sha},indent=2)+'\n')
# Execute only this migration from the reviewed source with LIVE DB credentials.
# No source change, dependency install, queue worker, external API or persistent tool service.
subprocess.run(['docker','run','--rm','--name','jakawi-club-activation-migration','--network','jakawi-club-private','--memory','256m','--memory-swap','320m','--cpus','0.65','--pids-limit','96','--env-file','/srv/sites/jakawi.club/secrets/app.env','--env','LOG_CHANNEL=null','--env','APP_CONFIG_CACHE=/tmp/config.php','--env','APP_ROUTES_CACHE=/tmp/routes.php','--volume',str(source/'app')+':/var/www/html:ro','--tmpfs','/var/www/html/storage:rw,mode=1777,size=32m','--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777,size=8m','jakawi-club-php:8.4-base-v1','php','artisan','migrate','--force','--path=database/migrations/2026_11_04_120000_create_account_activation_tokens_table.php'],check=True)
for file in build.rglob('*'):
 if not file.is_file() or file.name=='manifest.json':continue
 target=live/'app/public/build'/file.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists():assert target.read_bytes()==file.read_bytes()
 else:shutil.copy2(file,target)
run(['git','switch','--detach',sha])
shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.activation-next.json')
os.replace(live/'app/public/build/manifest.activation-next.json',live/'app/public/build/manifest.json')
for cache in ['route:cache','view:cache']:
 run(['docker','compose','exec','-T','--user','www-data','app','php','-d','memory_limit=128M','artisan',cache])
run(['docker','compose','restart','app'])
(evidence/'LIVE.json').write_text(json.dumps({'sha':sha,'previous_sha':previous,'manifest_sha256':hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest(),'scope':'CLUB only','migration':'account_activation_tokens only'},indent=2)+'\n')
print('CLUB LIVE SHA '+sha)
PY
