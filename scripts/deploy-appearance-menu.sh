#!/usr/bin/env bash
# CLUB canonical package release: code + immutable assets, manifest last, app only.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$PWD" "$@" <<'PY'
import hashlib,json,os,shutil,subprocess,sys,tarfile,datetime
from pathlib import Path
assert len(sys.argv)==3,'Usage: deploy-appearance-menu.sh <reviewed-package>'
source=Path(sys.argv[1]).resolve();package=Path(sys.argv[2]).resolve();live=Path('/opt/sites/jakawi.club')
evidence=Path(os.environ['JAKAWI_EVIDENCE_DIR']);baseline='beab670d299c86856c492df03529f5ccbc37b43d'
assert all(p.is_relative_to('/srv/workspaces/jakawi/club') for p in [source,package,evidence])
read=lambda args,cwd=live:subprocess.check_output(args,cwd=cwd,text=True).strip()
run=lambda args,**kw:subprocess.run(args,cwd=live,check=True,**kw)
assert not read(['git','status','--porcelain'],source) and not read(['git','status','--porcelain'])
release=json.loads((package/'release.json').read_text());sha=release['sha']
assert read(['git','rev-parse','HEAD'],source)==sha and read(['git','rev-parse','HEAD'])==baseline
assert read(['git','ls-remote','origin','refs/heads/main'],source).split()[0]==baseline
for name,digest in release['archives_sha256'].items():assert hashlib.sha256((package/name).read_bytes()).hexdigest()==digest
browser=json.loads((evidence/'browser.json').read_text());tests=json.loads((evidence/'tests.json').read_text())
assert browser['result']=='PASS' and browser['no_live_mutations'] and browser['manifest_sha256']==release['manifest_sha256']
assert len(tests['passed'])>=50 and tests['live_mutations'] is False
assert json.loads((evidence/'build.json').read_text())['result']=='PASS'
allowed={'app/resources/js/components/AccountTheme.tsx','app/resources/css/app.css','scripts/check-appearance-menu.cjs','scripts/check-appearance-menu.sh','scripts/check-appearance-menu-public.cjs','scripts/deploy-appearance-menu.sh','scripts/appearance-menu-snapshot.php','docs/operations/appearance-menu-v2.md'}
assert set(read(['git','diff','--name-only',baseline,sha],source).splitlines())<=allowed,'UX-only release scope'
run(['git','fetch',str(source),sha]);run(['git','merge-base','--is-ancestor',baseline,sha]);run(['docker','compose','config','--quiet'])
rollback=Path('/srv/sites/jakawi.club')/('rollback-appearance-menu-v2-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'));rollback.mkdir(mode=0o700)
snapshot=lambda:json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=live,input=(source/'scripts/appearance-menu-snapshot.php').read_bytes()))
before=snapshot();assert before['flags']=={'registration_open':True,'mail':'smtp','transactional_mail_ready':True,'debug':False}
env_file=Path('/srv/sites/jakawi.club/secrets/app.env');env_digest=hashlib.sha256(env_file.read_bytes()).hexdigest()
protected=['jakawi-club-web','jakawi-club-db','jakawi-app-1','jakawi-web-1','jakawi-worker-1','jakawi-scheduler-1','crm-jakawi-private-app-1','crm-jakawi-private-web-1','crm-jakawi-private-db-1']
state=lambda:{c['Name']:(c['Id'],c['Image'],c['State']['StartedAt']) for c in json.loads(read(['docker','inspect',*protected]))}
initial=state()
with (rollback/'database.dump').open('wb')as f:run(['docker','exec','jakawi-club-db','pg_dump','-U','jakawi_club_admin','-d','jakawi_club','-Fc','--no-owner','--no-privileges'],stdout=f)
with (rollback/'database.dump').open('rb')as f:run(['docker','exec','-i','jakawi-club-db','pg_restore','--list'],stdin=f,stdout=subprocess.DEVNULL)
schema=lambda:read(['docker','exec','jakawi-club-db','pg_dump','-U','jakawi_club_admin','-d','jakawi_club','--schema-only','--no-owner','--no-privileges'])
stable=lambda text:'\n'.join(l for l in text.splitlines()if not l.startswith(('\\restrict ','\\unrestrict ')))
schema_before=stable(schema())
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json')
(rollback/'release.json').write_text(json.dumps({'previous_sha':baseline,'target_sha':sha,'migrations':False},indent=2))
(rollback/'before.json').write_text(json.dumps(before,indent=2))
# Validate rollback code is still available; retain every immutable old asset.
run(['git','cat-file','-e',baseline+'^{commit}'])
build=source/'app/public/build-next';assert hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest()==release['manifest_sha256']
for f in build.rglob('*'):
 if not f.is_file()or f.name=='manifest.json':continue
 target=live/'app/public/build'/f.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists():assert target.read_bytes()==f.read_bytes()
 else:shutil.copy2(f,target)
def caches():
 for command in ['route:cache','view:cache']:run(['docker','compose','exec','-T','--user','www-data','app','php','-d','memory_limit=128M','artisan',command])
try:
 run(['git','switch','--detach',sha]);shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.menu-next.json');os.replace(live/'app/public/build/manifest.menu-next.json',live/'app/public/build/manifest.json')
 caches();run(['docker','compose','restart','app'])
 after=snapshot();assert before==after,'Business data or flags changed'
 assert state()==initial and hashlib.sha256(env_file.read_bytes()).hexdigest()==env_digest
 assert stable(schema())==schema_before,'Schema changed'
 for url in ['https://jakawi.club/up','https://jakawi.club/','https://jakawi.club/login']:
  run(['curl','--fail','--silent','--show-error','--output','/dev/null',url])
except Exception:
 run(['git','switch','--detach',baseline]);shutil.copy2(rollback/'manifest.json',live/'app/public/build/manifest.json');caches();run(['docker','compose','restart','app']);raise
(evidence/'LIVE.json').write_text(json.dumps({'result':'PASS','sha':sha,'previous_sha':baseline,'manifest_sha256':release['manifest_sha256'],'business_users_flags_mail_unchanged':True,'schema_unchanged':True,'credentials_unchanged':True,'protected_containers_unchanged':True,'migrations':False,'rollback':str(rollback)},indent=2))
print('CLUB deployed '+sha+'; rollback '+str(rollback))
PY
