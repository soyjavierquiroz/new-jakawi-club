#!/usr/bin/env bash
# CLUB canonical package -> immutable chunks -> manifest last -> app restart.
# Requires reviewed PASS evidence. Never writes launch/mail settings or business data.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$PWD" "$@" <<'PY'
import hashlib,json,os,shutil,subprocess,sys,tarfile
from pathlib import Path
assert len(sys.argv)==3,'Usage: bash scripts/deploy-appearance.sh <reviewed-package>'
source=Path(sys.argv[1]).resolve();package=Path(sys.argv[2]).resolve();live=Path('/opt/sites/jakawi.club')
assert source.is_relative_to('/srv/workspaces/jakawi/club') and package.is_relative_to('/srv/workspaces/jakawi/club')
read=lambda args,cwd=live:subprocess.check_output(args,cwd=cwd,text=True).strip()
run=lambda args,**kw:subprocess.run(args,cwd=live,check=True,**kw)
assert not read(['git','status','--porcelain'],source) and not read(['git','status','--porcelain'])
release=json.loads((package/'release.json').read_text());sha=release['sha']
assert read(['git','rev-parse','HEAD'],source)==sha
previous=read(['git','rev-parse','HEAD'])
assert previous==read(['git','rev-parse','35bf6d9'],source),'Changed LIVE baseline: review first'
for name,digest in release['archives_sha256'].items():assert hashlib.sha256((package/name).read_bytes()).hexdigest()==digest
checks=source/'artifacts/global-appearance-v1'
tests=json.loads((checks/'tests.json').read_text());browser=json.loads((checks/'browser.json').read_text());video=json.loads((checks/'video.json').read_text())
assert len(tests['passed'])>=50 and tests['live_mutations'] is False
assert browser['no_live_mutations'] and len(browser['checks'])>=60
assert browser['manifest_sha256']==release['manifest_sha256']
assert browser['video_verified'],'Real video QA has no PASS'
assert len(video['videoReport'])==2 and all(v['verified'] for v in video['videoReport'])
assert json.loads((checks/'build.json').read_text())['typecheck_and_build_pass']
rollback=Path('/srv/sites/jakawi.club/rollback-global-appearance-v1')
assert json.loads((rollback/'backup-validation.json').read_text())['additive_migration_on_live_backup_pass']
assert not (rollback/'release.json').exists(),'Release already attempted; review result before retry'
allowed={'.gitignore','app/app/Http/Middleware/HandleInertiaRequests.php','app/app/Http/Controllers/AppearanceController.php','app/bootstrap/app.php','app/routes/web.php','app/database/migrations/2026_11_21_000000_add_theme_preference_to_users.php','docs/design/appearance-contract-v1.md','docs/operations/global-appearance-v1.md','scripts/deploy-appearance.sh','scripts/appearance-live-snapshot.php','scripts/appearance-runner.php','scripts/appearance-tests.php','scripts/test-appearance.sh','scripts/check-appearance.mjs','scripts/check-appearance-matrix.mjs','scripts/check-appearance.sh','scripts/aggregate-appearance.py'}
changed=read(['git','diff','--name-only',previous,sha],source).splitlines()
assert not any(p.startswith('app/resources/js/components/media/jakawi-vsl-player/') for p in changed),'VSL implementation is outside this release'
assert all(p in allowed or p.startswith(('app/resources/','artifacts/global-appearance-v1/')) for p in changed),'Unexpected release scope'
run(['git','fetch',str(source),sha]);run(['git','merge-base','--is-ancestor',previous,sha])
run(['docker','compose','config','--quiet'])
snapshot=lambda:json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=live,input=(source/'scripts/appearance-live-snapshot.php').read_bytes()))
before=snapshot();assert before['flags']=={'registration_open':True,'mail':'smtp','transactional_mail_ready':True,'debug':False},'LIVE flags changed: review the authorized baseline'
# Refresh the private backup immediately before migration; no destructive database rollback.
with (rollback/'database-before-release.dump').open('wb') as f:run(['docker','exec','jakawi-club-db','pg_dump','-U','jakawi_club_admin','-d','jakawi_club','-Fc','--no-owner','--no-privileges'],stdout=f)
os.chmod(rollback/'database-before-release.dump',0o600)
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json')
(rollback/'before.json').write_text(json.dumps(before,indent=2)+'\n')
(rollback/'release.json').write_text(json.dumps({'previous_sha':previous,'target_sha':sha,'rollback_keeps_additive_column':True},indent=2)+'\n')
build=source/'app/public/build-next';assert hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest()==release['manifest_sha256']
# Keep all prior chunks, so existing documents and rollback continue to resolve assets.
for f in build.rglob('*'):
 if not f.is_file() or f.name=='manifest.json':continue
 target=live/'app/public/build'/f.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists():assert target.read_bytes()==f.read_bytes()
 else:shutil.copy2(f,target)
def caches():
 for command in ['route:cache','view:cache']:run(['docker','compose','exec','-T','--user','www-data','app','php','-d','memory_limit=128M','artisan',command])
try:
 # Run exactly one additive migration. The previous code tolerates this extra column.
 run(['docker','run','--rm','--name','jakawi-club-appearance-migration','--network','jakawi-club-private','--memory','256m','--memory-swap','320m','--cpus','0.65','--pids-limit','96','--env-file','/srv/sites/jakawi.club/secrets/app.env','--env','LOG_CHANNEL=null','--env','APP_CONFIG_CACHE=/tmp/config.php','--env','APP_ROUTES_CACHE=/tmp/routes.php','--volume',str(source/'app')+':/var/www/html:ro','--volume','/opt/sites/jakawi.club/app/vendor:/var/www/html/vendor:ro','--tmpfs','/var/www/html/storage:rw,mode=1777,size=32m','--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777,size=8m','jakawi-club-php:8.4-base-v1','php','artisan','migrate','--force','--path=database/migrations/2026_11_21_000000_add_theme_preference_to_users.php'])
 run(['git','switch','--detach',sha]);shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.appearance-next.json');os.replace(live/'app/public/build/manifest.appearance-next.json',live/'app/public/build/manifest.json')
 caches();run(['docker','compose','restart','app'])
 after=snapshot();assert before==after,'Business data or launch/mail settings changed'
 for url in ['https://jakawi.club/up','https://jakawi.club/','https://jakawi.club/login']:
  run(['curl','--fail','--silent','--show-error','--output','/dev/null',url])
except Exception:
 run(['git','switch','--detach',previous]);shutil.copy2(rollback/'manifest.json',live/'app/public/build/manifest.json');caches();run(['docker','compose','restart','app']);raise
(checks/'LIVE.json').write_text(json.dumps({'sha':sha,'previous_sha':previous,'manifest_sha256':release['manifest_sha256'],'business_and_flags_unchanged':before==after,'rollback':str(rollback),'migration':'users.theme_preference only'},indent=2)+'\n')
print('CLUB runtime SHA '+sha)
PY
