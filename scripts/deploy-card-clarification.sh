#!/usr/bin/env bash
# User-authorized CLUB-only rollout: no migrations, bucket writes or editorial publication.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$(pwd)" <<'PY'
import hashlib,json,os,shutil,subprocess,sys
from pathlib import Path
source=Path(sys.argv[1]).resolve();live=Path('/opt/sites/jakawi.club')
assert source.is_relative_to('/srv/workspaces/jakawi/club')
run=lambda args,**kw:subprocess.run(args,cwd=live,check=True,**kw)
read=lambda args:subprocess.check_output(args,cwd=live,text=True).strip()
assert not subprocess.check_output(['git','status','--porcelain'],cwd=source),'Commit the reviewed source first'
assert not read(['git','status','--porcelain']),'LIVE has pending changes'
previous=read(['git','rev-parse','HEAD'])
expected=subprocess.check_output(['git','rev-parse','0b0a5c1'],cwd=source,text=True).strip()
assert previous==expected,'LIVE baseline changed: review before deployment'
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=source,text=True).strip()
changed=subprocess.check_output(['git','diff','--name-only',previous,sha],cwd=source,text=True).splitlines()
allowed={'.gitignore','app/app/Support/LandingContent.php','app/resources/css/app.css','app/resources/js/components/marketing/OpportunityCard.tsx','app/resources/js/pages/admin/LandingEditor.tsx','scripts/editorial-metrics-tests.php','scripts/check-editorial-metrics-visual.mjs','scripts/deploy-card-clarification.sh'}
assert all(p in allowed or p.startswith('artifacts/card-clarification/') for p in changed),'Unexpected release scope'
evidence=source/'artifacts/card-clarification';build=source/'app/public/build-next'
tests=json.loads((evidence/'tests.json').read_text());browser=json.loads((evidence/'browser.json').read_text())
assert len(tests['passed'])>=48 and tests['live_mutations'] is False
assert len(browser['checks'])>=16 and browser['no_live_mutations']
assert browser['manifest_sha256']==hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest()
assert json.loads((evidence/'build.json').read_text())['exit_code']==0
rollback=Path('/srv/sites/jakawi.club/rollback-card-clarification');rollback.mkdir(exist_ok=False,mode=0o700)
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json')
(rollback/'release.json').write_text(json.dumps({'previous_sha':previous,'target_sha':sha},indent=2)+'\n')
snapshot=lambda:subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=live,input=(source/'scripts/opportunity-live-snapshot.php').read_bytes())
before=json.loads(snapshot());(rollback/'before.json').write_text(json.dumps(before,indent=2)+'\n')
container_names=['jakawi-club-web','jakawi-club-db']
baseline={c['Name']:(c['Id'],c['State']['StartedAt'],c['RestartCount']) for c in json.loads(read(['docker','inspect',*container_names]))}
run(['docker','compose','config','--quiet'])
run(['git','fetch',str(source),sha]);run(['git','merge-base','--is-ancestor',previous,sha])
# Preserve every prior chunk; publish only the reviewed manifest after code is switched.
for file in build.rglob('*'):
 if not file.is_file() or file.name=='manifest.json':continue
 target=live/'app/public/build'/file.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists():assert target.read_bytes()==file.read_bytes()
 else:shutil.copy2(file,target)
try:
 run(['git','switch','--detach',sha])
 shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.editorial-next.json')
 os.replace(live/'app/public/build/manifest.editorial-next.json',live/'app/public/build/manifest.json')
 run(['docker','compose','restart','app'])
 after=json.loads(snapshot());assert before==after,'Editorial data changed during deployment'
 current={c['Name']:(c['Id'],c['State']['StartedAt'],c['RestartCount']) for c in json.loads(read(['docker','inspect',*container_names]))}
 assert current==baseline,'A container outside CLUB app changed'
except Exception:
 run(['git','switch','--detach',previous]);shutil.copy2(rollback/'manifest.json',live/'app/public/build/manifest.json')
 run(['docker','compose','restart','app'])
 raise
(evidence/'LIVE.json').write_text(json.dumps({'sha':sha,'previous_sha':previous,'manifest_sha256':browser['manifest_sha256'],'scope':'CLUB code/assets only','editorial_unchanged':before==after,'other_containers_unchanged':current==baseline,'migration':False,'media_writes':0,'rollback':str(rollback)},indent=2)+'\n')
print('CLUB LIVE SHA '+sha)
PY
