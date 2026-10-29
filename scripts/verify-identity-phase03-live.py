#!/usr/bin/env python3
"""Focused CLUB-only deployment seal. Never emit credentials, tokens or account values."""
from datetime import datetime
from hashlib import sha256
import base64
import json
from pathlib import Path
import shlex
import subprocess
from urllib.request import Request,urlopen
from zoneinfo import ZoneInfo

ROOT=Path('/opt/sites/jakawi.club')
OUT=ROOT/'artifacts/identity-phase03'
PRIVATE=Path('/srv/sites/jakawi.club/rollback-identity-phase03')
base=json.loads((PRIVATE/'baseline.json').read_text())

def command(args,**kwargs):return subprocess.check_output(args,cwd=ROOT,**kwargs)
def php(source):return json.loads(command(['docker','compose','exec','-T','--user','www-data','app','php'],input=source.encode()))

head=command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain'])
assert head==command(['git','rev-parse','origin/main']).decode().strip()==command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
assert sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest()==base['env_sha256']
protected=[p for p in base['files'] if p.startswith(('app/resources/js/pages/Welcome','app/resources/js/pages/Registered','app/resources/js/components/marketing/','app/resources/js/components/media/','app/app/Services/Crm/','app/app/Jobs/','app/config/mail','app/config/crm','app/app/Http/Controllers/Registration','app/app/Support/Registration','app/app/Support/Landing','app/app/Support/Launch'))]
assert all(sha256((ROOT/p).read_bytes()).hexdigest()==base['files'][p] for p in protected)
files=command(['git','ls-files','app']).decode().splitlines()
# Runtime storage/cache are persistent mounts, not the tracked source directories.
expected={p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in files if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded=base64.b64encode(json.dumps(list(expected)).encode()).decode()
actual=php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);")
assert actual==expected
source=r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before=json_decode(file_get_contents(storage_path('app/identity-phase03-before.json')),true);$protected=[];
foreach($before as $table=>$b){$rows=Illuminate\Support\Facades\DB::table($table)->select($b['columns'])->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$protected[$table]=count($rows)===$b['count']&&hash('sha256',json_encode($rows))===$b['digest'];}
$columns=collect(Illuminate\Support\Facades\Schema::getColumns('users'))->keyBy('name');$indexes=Illuminate\Support\Facades\Schema::getIndexes('users');
$pilot=App\Models\User::where('email','javierquiroztv@gmail.com')->firstOrFail();$api=app(App\Services\Identity\ComIdentityClient::class)->link($pilot->uuid);
$state=app(App\Services\Identity\IdentityLink::class)->state($pilot);
echo json_encode(['protected_tables'=>$protected,'global_subject_uuid_nullable'=>$columns['global_subject_id']['type_name']==='uuid'&&$columns['global_subject_id']['nullable'],
'global_subject_unique'=>collect($indexes)->contains(fn($i)=>$i['unique']&&$i['columns']===['global_subject_id']),
'local_id_bigint'=>$columns['id']['type_name']==='int8','public_uuid_preserved'=>$columns['uuid']['type_name']==='uuid',
'both_migrations_applied'=>Illuminate\Support\Facades\DB::table('migrations')->whereIn('migration',['2026_10_29_000003_add_global_subject_id_to_users','2026_10_29_000004_create_club_identity_operations'])->count()===2,
'identity_enabled'=>config('identity.enabled')===true,'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'smtp_ready'=>App\Support\LaunchSettings::mailReady(),
'native_recovery_table_preserved'=>config('auth.passwords.users.table')==='password_reset_tokens','identity_broker_separate'=>config('auth.passwords.identity_activation.table')==='club_identity_activation_tokens',
'pilot_activated_verified_admin'=>$pilot->activated_at!==null&&$pilot->hasVerifiedEmail()&&$pilot->is_marketing_admin,
'pilot_linked'=>$pilot->global_subject_id!==null,'pilot_needs_email_confirmation'=>$state['needs_email_confirmation'],'live_api_authenticated'=>true,'live_api_lookup_http'=>$api===null?404:200,
'live_handoffs_created'=>App\Models\IdentityHandoff::count(),'credentials_digests'=>array_map(fn($key)=>hash('sha256',(string)config('identity.'.$key)),['key_id','secret','proof_secret'])]);'''
state=php(source)
values={}
private_env=Path('/srv/sites/jakawi.club/secrets/com-identity.env')
assert private_env.stat().st_uid==0 and private_env.stat().st_mode&0o777==0o600
for line in private_env.read_text().splitlines():
 k,sep,v=line.partition('=')
 if sep:values[k]=shlex.split(v)[0]
assert state.pop('credentials_digests')==[sha256(values[k].encode()).hexdigest() for k in ['COM_IDENTITY_KEY_ID','COM_IDENTITY_SECRET','COM_IDENTITY_EMAIL_PROOF_SECRET']]
assert values['COM_IDENTITY_SECRET']!=values['COM_IDENTITY_EMAIL_PROOF_SECRET']
crm=Path('/srv/sites/jakawi.club/secrets/crm-hmac').read_text().strip()
assert crm not in [values['COM_IDENTITY_SECRET'],values['COM_IDENTITY_EMAIL_PROOF_SECRET']]
assert all(state['protected_tables'].values())
for key in ['global_subject_uuid_nullable','global_subject_unique','local_id_bigint','public_uuid_preserved','both_migrations_applied','identity_enabled','registration_open','smtp_ready','native_recovery_table_preserved','identity_broker_separate','pilot_activated_verified_admin','live_api_authenticated']:assert state[key],key
assert state['live_handoffs_created']==0
containers=json.loads(command(['docker','inspect',*base['containers']]))
for c in containers:
 name=c['Name'].lstrip('/')
 if name=='jakawi-club-app':continue
 old=base['containers'][name]
 assert c['Id']==old['id'] and c['Image']==old['image'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts'],name
assert command(['docker','inspect','jakawi-app-1','--format','{{index .Config.Labels "org.opencontainers.image.revision"}}']).decode().strip()=='11e04f3c91fd4096257a01f01c48eba3396f59e2'
assert command(['systemctl','is-active','jakawi-club-crm.timer']).strip()==b'active'
health={}
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up','https://jakawi.com/up']:
 with urlopen(url,timeout=20)as r:assert r.status==200;health[url]=200
manifest=json.loads((ROOT/'app/public/build/manifest.json').read_text())
assets={}
for entry in manifest.values():
 for filename in [entry['file'],*entry.get('css',[])]:
  if filename in assets:continue
  with urlopen('https://jakawi.club/build/'+filename,timeout=20)as r:assert r.status==200;content=r.read()
  assert content==(ROOT/'app/public/build'/filename).read_bytes();assets[filename]=sha256(content).hexdigest()
for f in (PRIVATE/'build').rglob('*'):
 if f.is_file()and f.name!='manifest.json':assert f.read_bytes()==(ROOT/'app/public/build'/f.relative_to(PRIVATE/'build')).read_bytes()
browser=json.loads((OUT/'browser.json').read_text());assert all(c['passed']for c in browser['checks'])and not browser['runtime_errors']and not browser['failed_assets']and browser['all_mutations_intercepted']and not browser['pilot_impersonated']
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,'deployment_scope':'CLUB only','com_sha':'11e04f3c91fd4096257a01f01c48eba3396f59e2','com_crm_and_other_containers_unchanged':True,'mounted_git_files_match':len(expected),'protected_funnel_crm_sources_unchanged':len(protected),'smtp_environment_bytes_preserved':True,'credentials_private_and_distinct':True,'previous_chunks_preserved':True,'health':health,'https_assets_verified':len(assets),'state':state,'browser_groups':len(browser['checks']),'live_test_accounts_created':0,'pilot_authorization':'awaiting real holder email confirmation and COM login/consent'}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
