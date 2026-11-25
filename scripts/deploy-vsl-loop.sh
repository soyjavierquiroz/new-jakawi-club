#!/usr/bin/env bash
# Focused adaptation of the canonical CLUB package/manifest release procedure.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$PWD" "$@" <<'PY'
import datetime,hashlib,json,os,shutil,subprocess,sys
from pathlib import Path
assert len(sys.argv)==3,'Usage: deploy-vsl-loop.sh <reviewed-package>'
source=Path(sys.argv[1]).resolve();package=Path(sys.argv[2]).resolve();live=Path('/opt/sites/jakawi.club')
evidence=Path(os.environ['JAKAWI_EVIDENCE_DIR']);baseline='ad4543f7fb52d49296d475895635be18b0656691'
assert all(p.is_relative_to('/srv/workspaces/jakawi/club') for p in [source,package,evidence])
read=lambda args,cwd=live:subprocess.check_output(args,cwd=cwd,text=True).strip()
run=lambda args,**kw:subprocess.run(args,cwd=live,check=True,**kw)
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
assert not read(['git','status','--porcelain'],source)
assert read(['git','status','--porcelain']) in ['', 'M compose.yaml'],'Unexpected LIVE edits'
compose=live/'compose.yaml';compose_digest=digest(compose)
release=json.loads((package/'release.json').read_text());sha=release['sha']
assert read(['git','rev-parse','HEAD'],source)==sha and read(['git','rev-parse','HEAD'])==baseline
assert read(['git','ls-remote','origin','refs/heads/main'],source).split()[0]==baseline
for name,value in release['archives_sha256'].items():assert digest(package/name)==value
for name in ['tests','browser','build']:
 assert json.loads((evidence/(name+'.json')).read_text())['result']=='PASS'
browser=json.loads((evidence/'browser.json').read_text());assert browser['no_live_mutations'] and browser['manifest_sha256']==release['manifest_sha256']
allowed={'scripts/conversion-unit-tests.mjs','app/app/Support/LandingContent.php','app/resources/js/components/media/jakawi-vsl-player/types.ts','app/resources/js/components/media/jakawi-vsl-player/landing-video-props.ts','app/resources/js/components/media/jakawi-vsl-player/use-vsl-playback.ts','app/resources/js/components/media/jakawi-vsl-player/youtube-engine.ts','app/resources/js/types.ts','app/resources/js/pages/admin/LandingEditor.tsx','scripts/vsl-loop-tests.mjs','scripts/vsl-loop-config-test.php','scripts/deploy-vsl-loop.sh','scripts/check-vsl-loop.cjs'}
assert set(read(['git','diff','--name-only',baseline,sha],source).splitlines())<=allowed,'VSL-only scope required'
assert not read(['git','diff','--name-only',baseline,sha,'--','compose.yaml'],source)
run(['git','fetch',str(source),sha]);run(['git','merge-base','--is-ancestor',baseline,sha]);run(['docker','compose','config','--quiet'])
rollback=Path('/srv/sites/jakawi.club')/('rollback-vsl-loop-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'));rollback.mkdir(mode=0o700)
env=Path('/srv/sites/jakawi.club/secrets/app.env');env_digest=digest(env)
protected=['jakawi-club-web','jakawi-club-db']
state=lambda:{c['Name']:(c['Id'],c['Image'],c['State']['StartedAt']) for c in json.loads(read(['docker','inspect',*protected]))}
initial=state()
# Configuration is read only. No registrations, editorial publication or commercial actions.
php=b"<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo hash('sha256',Illuminate\\Support\\Facades\\DB::table('marketing_landings')->orderBy('id')->get()->toJson());"
snapshot=lambda:subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=live,input=php).decode().strip()
before=snapshot()
with (rollback/'database.dump').open('wb') as f:run(['docker','exec','jakawi-club-db','pg_dump','-U','jakawi_club_admin','-d','jakawi_club','-Fc','--no-owner','--no-privileges'],stdout=f)
with (rollback/'database.dump').open('rb') as f:run(['docker','exec','-i','jakawi-club-db','pg_restore','--list'],stdin=f,stdout=subprocess.DEVNULL)
shutil.copy2(live/'app/public/build/manifest.json',rollback/'manifest.json');shutil.copy2(compose,rollback/'compose.yaml')
(rollback/'release.json').write_text(json.dumps({'previous_sha':baseline,'target_sha':sha,'compose_sha256':compose_digest,'environment_sha256':env_digest,'landings_sha256':before},indent=2))
run(['git','cat-file','-e',baseline+'^{commit}'])
rollback_script=f'''#!/usr/bin/env bash
set -euo pipefail
cd /opt/sites/jakawi.club
git switch --detach {baseline}
cp {rollback}/manifest.json app/public/build/manifest.vsl-rollback.json
mv app/public/build/manifest.vsl-rollback.json app/public/build/manifest.json
docker compose restart app
'''
(rollback/'rollback.sh').write_text(rollback_script);os.chmod(rollback/'rollback.sh',0o700);run(['bash','-n',str(rollback/'rollback.sh')])
build=source/'app/public/build-next';assert digest(build/'manifest.json')==release['manifest_sha256']
for f in build.rglob('*'):
 if not f.is_file() or f.name=='manifest.json':continue
 target=live/'app/public/build'/f.relative_to(build);target.parent.mkdir(parents=True,exist_ok=True)
 if target.exists():assert target.read_bytes()==f.read_bytes()
 else:shutil.copy2(f,target)
try:
 run(['git','switch','--detach',sha])
 shutil.copy2(build/'manifest.json',live/'app/public/build/manifest.vsl-next.json');os.replace(live/'app/public/build/manifest.vsl-next.json',live/'app/public/build/manifest.json')
 run(['docker','compose','restart','app'])
 assert snapshot()==before,'Landing configuration changed'
 assert state()==initial and digest(env)==env_digest and digest(compose)==compose_digest
 for url in ['https://jakawi.club/up','https://jakawi.club/']:
  run(['curl','--fail','--silent','--show-error','--retry','5','--retry-delay','1','--output','/dev/null',url])
except Exception:
 run(['bash',str(rollback/'rollback.sh')]);raise
(evidence/'deployed.json').write_text(json.dumps({'result':'PASS','sha':sha,'rollback_sha':baseline,'rollback':str(rollback),'manifest_sha256':release['manifest_sha256'],'configuration_unchanged':True,'protected_containers_unchanged':True,'migrations':False},indent=2))
print('CLUB deployed '+sha+'; backup and rollback '+str(rollback))
PY
