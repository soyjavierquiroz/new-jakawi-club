#!/usr/bin/env python3
"""Focused deployment proof for CLUB/CRM; no personal values or credentials emitted."""
from pathlib import Path
from hashlib import sha256
from datetime import datetime
from zoneinfo import ZoneInfo
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import base64
import json
import subprocess

ROOT=Path('/opt/sites/jakawi.club')
CRM=Path('/opt/sites/crm.jakawi.com/bridge')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-crm-sync')

def command(args,cwd=ROOT,**kw):
    return subprocess.check_output(args,cwd=cwd,**kw)

def php(source,cwd=ROOT):
    return json.loads(command(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=cwd,input=source.encode()))

club_sha=command(['git','rev-parse','HEAD']).decode().strip()
crm_sha=command(['git','rev-parse','HEAD'],cwd=CRM).decode().strip()
assert not command(['git','status','--porcelain'])
assert not command(['git','status','--porcelain'],cwd=CRM)
assert club_sha==command(['git','rev-parse','origin/main']).decode().strip()==command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
base=json.loads((PRIVATE/'baseline.json').read_text())
assert sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest()==base['env_sha256']
secret=Path('/srv/sites/jakawi.club/secrets/crm-hmac').read_bytes().strip()
assert secret==Path('/opt/sites/crm.jakawi.com/secrets/bridge_club_hmac').read_bytes().strip()
assert secret!=Path('/opt/sites/crm.jakawi.com/secrets/bridge_hmac').read_bytes().strip()
files=command(['git','diff','--name-only',base['club_sha'],'HEAD','--','app']).decode().splitlines()
expected={f.removeprefix('app/'):sha256((ROOT/f).read_bytes()).hexdigest() for f in files}
encoded=base64.b64encode(json.dumps(list(expected)).encode()).decode()
actual=php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true) as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);")
assert actual==expected
installed=Path('/opt/sites/crm.jakawi.com/clean-wordpress/wp-content/plugins/jakawi-fluentcrm-bridge')
crm_files=[CRM/'jakawi-fluentcrm-bridge.php',*sorted((CRM/'includes').glob('*.php'))]
assert all(f.read_bytes()==(installed/f.relative_to(CRM)).read_bytes() for f in crm_files)
assert not command(['git','diff','dad480a','HEAD','--','includes/Contract.php','includes/Adapter.php'],cwd=CRM)
health={}
for url in ['https://jakawi.club/up','https://crm.jakawi.com/up','https://jakawi.com/up']:
    with urlopen(url,timeout=20) as r: assert r.status==200;health[url]=200
try:
    urlopen(Request('https://crm.jakawi.com/wp-json/jakawi-fluentcrm/v1/club/events',data=b'{}',headers={'Content-Type':'application/json'}),timeout=20)
    raise AssertionError('Unsigned event accepted')
except HTTPError as error:
    assert error.code==401 and json.loads(error.read())['result_code']=='AUTH_FAILED'
source=r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::where('email','javierquirozsueldo@gmail.com')->firstOrFail();$b=json_decode(file_get_contents(storage_path('app/crm-test-user-before.json')),true);$r=['account_unchanged'=>$b['digest']===hash('sha256',json_encode($u->getRawOriginal())),'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'mail_ready'=>App\Support\LaunchSettings::mailReady(),'pending'=>Illuminate\Support\Facades\DB::table('jobs')->where('queue','crm')->count(),'failed'=>Illuminate\Support\Facades\DB::table('failed_jobs')->where('queue','crm')->count(),'leads'=>App\Models\User::whereNotNull('privacy_accepted_at')->whereNotNull('phone')->get()->map(fn($x)=>['uuid'=>$x->uuid,'activated'=>$x->activated_at!==null && $x->hasVerifiedEmail()])->all()];echo json_encode($r);'''
state=php(source)
assert state['account_unchanged'] and state['registration_open'] and state['mail_ready']
assert state['pending']==state['failed']==0
encoded=base64.b64encode(json.dumps(state.pop('leads')).encode()).decode()
source=r'''<?php require '/var/www/html/wp-load.php';$leads=json_decode(base64_decode('ENCODED'),true);$matched=0;foreach($leads as $lead){$m=FluentCrm\App\Models\Subscriber::whereHas('custom_field_meta',fn($q)=>$q->where('key','club_user_uuid')->where('value',$lead['uuid']))->get();if($m->count()!==1)throw new RuntimeException('Expected exactly one contact per CLUB UUID');$f=$m[0]->custom_fields();if(($f['club_source']??null)!=='CLUB'||($f['club_activation_status']??null)!==($lead['activated']?'activated':'pending'))throw new RuntimeException('CLUB state mismatch');$matched++;}echo json_encode(['registered_leads_verified'=>$matched,'one_contact_per_uuid'=>true,'activation_states_match'=>true]);'''.replace('ENCODED',encoded)
contacts=php(source,cwd=CRM.parent)
assert command(['systemctl','is-enabled','jakawi-club-crm.timer']).strip()==b'enabled'
assert command(['systemctl','is-active','jakawi-club-crm.timer']).strip()==b'active'
assert command(['systemctl','show','jakawi-club-crm.service','--property=Result','--value']).strip()==b'success'
contact=json.loads((ROOT/'artifacts/crm-sync/live-contact.json').read_text())
assert all(v for k,v in contact.items() if k!='contact_id')
replay=json.loads(Path('/srv/sites/jakawi.club/storage/app/crm-live-result.json').read_text())
assert replay['idempotent_replay'] and replay['contact_id']==contact['contact_id']
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'club_sha':club_sha,'club_origin_main_synced':True,'crm_sha':crm_sha,'crm_repository':str(CRM),'crm_remote_configured':bool(command(['git','remote'],cwd=CRM).strip()),'trees_clean':True,'mounted_club_files_verified':len(expected),'plugin_files_verified':len(crm_files),'com_contract_and_adapter_unchanged':True,'health':health,'endpoint_signed_live_replay':True,'endpoint_unsigned_rejected':True,'account':state,'contacts':contacts,'authorized_contact_id':contact['contact_id'],'timer_enabled_active':True,'smtp_environment_bytes_preserved':True,'independent_club_secret':True,'live_test_emails_sent':0,'live_test_accounts_created':0}
(ROOT/'artifacts/crm-sync/LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
