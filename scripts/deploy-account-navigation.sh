#!/usr/bin/env bash
# Explicit CLUB-only rollout. No migrations, economic commands, env changes or COM/CRM writes.
set -euo pipefail
python3 - "$@" <<'PY'
import hashlib,json,os,shutil,subprocess,sys,tarfile
from pathlib import Path
assert len(sys.argv)==2, 'Pass the reviewed source package directory'
package=Path(sys.argv[1]).resolve()
assert package.is_relative_to('/srv/workspaces/jakawi/club')
live=Path('/opt/sites/jakawi.club'); source=Path('/srv/repos/jakawi.club')
evidence=Path('/srv/sites/jakawi.club/account-navigation-evidence')
release=json.loads((package/'release.json').read_text()); sha=release['sha']
assert len(json.loads((evidence/'tests.json').read_text())['passed'])>=49
assert len(json.loads((evidence/'admin/tests.json').read_text())['passed'])>=42
browser=json.loads((evidence/'browser-preview.json').read_text())
assert browser['manifest_sha256']==release['manifest_sha256']
assert browser['no_live_mutations'] and len(browser['checks']) >= 14
for name,digest in release['archives_sha256'].items():
 assert hashlib.sha256((package/name).read_bytes()).hexdigest()==digest
run=lambda args,**kwargs:subprocess.run(args,cwd=live,check=True,**kwargs)
read=lambda args:subprocess.check_output(args,cwd=live,text=True).strip()
assert not read(['git','status','--porcelain'])
previous=read(['git','rev-parse','HEAD'])
assert previous=='ef711db573f665d6f89676b6841df3af69519159', 'Review a changed LIVE baseline first'
rollback=Path('/srv/sites/jakawi.club/rollback-account-navigation')
rollback.mkdir(exist_ok=False)
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json')
(rollback/'release.json').write_text(json.dumps({'previous_sha':previous,'target_sha':sha},indent=2)+'\n')
with open(evidence/'before.json','w') as out:
 run(['docker','compose','exec','-T','--user','www-data','app','php'],input=(source/'scripts/admin-live-snapshot.php').read_bytes(),stdout=out)
containers=json.loads(read(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
(evidence/'baseline.json').write_text(json.dumps({'containers':{c['Name'].lstrip('/'):{'id':c['Id'],'started_at':c['State']['StartedAt'],'restarts':c['RestartCount']} for c in containers}},indent=2)+'\n')
run(['docker','compose','config','--quiet'])
run(['git','fetch',str(source),sha])
run(['git','merge-base','--is-ancestor',previous,sha])
assets=package/'extracted-frontend';assets.mkdir(exist_ok=False)
with tarfile.open(package/'frontend.tar') as archive:archive.extractall(assets,filter='data')
build=assets/'app/public/build-next'
assert hashlib.sha256((build/'manifest.json').read_bytes()).hexdigest()==release['manifest_sha256']
# New chunks are immutable; publish manifest last and keep every prior chunk.
for file in build.rglob('*'):
 if not file.is_file() or file.name=='manifest.json':continue
 target=live/'app/public/build'/file.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists(): assert target.read_bytes()==file.read_bytes()
 else:shutil.copy2(file,target)
run(['git','switch','--detach',sha])
shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.admin.json')
os.replace(live/'app/public/build/manifest.admin.json',live/'app/public/build/manifest.json')
for cache in ['route:cache','view:cache']:
 run(['docker','compose','exec','-T','--user','www-data','app','php','-d','memory_limit=128M','artisan',cache])
run(['docker','compose','restart','app'])
print('CLUB deployed at '+sha)
PY
