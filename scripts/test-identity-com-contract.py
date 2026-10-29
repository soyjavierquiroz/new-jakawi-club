#!/usr/bin/env python3
"""Exercise CLUB against the exact COM release in disposable, bounded containers."""
from pathlib import Path
import base64
import json
import os
import subprocess
import time

ROOT=Path('/opt/sites/jakawi.club')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-identity-phase03')
EVIDENCE=ROOT/'artifacts/identity-phase03'
DB='jakawi-club-identity-test-db'
COM='jakawi-club-identity-test-com'
created=[]

def run(args,**kwargs):
    return subprocess.run(args,check=True,cwd=ROOT,**kwargs)

def output(args):
    return subprocess.check_output(args,cwd=ROOT,text=True).strip()

for name in [DB,COM]:
    if subprocess.run(['docker','inspect',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:
        raise SystemExit('Existing test container retained: '+name)
image=output(['docker','inspect','jakawi-app-1','--format','{{.Image}}'])
assert output(['docker','inspect','jakawi-app-1','--format','{{index .Config.Labels "org.opencontainers.image.revision"}}'])=='11e04f3c91fd4096257a01f01c48eba3396f59e2'
fixture_env=PRIVATE/'contract-test.env'
values={'APP_ENV':'identity_contract_testing','APP_DEBUG':'false','APP_URL':'https://jakawi.com',
 'APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'APP_CONFIG_CACHE':'/tmp/contract-config.php','APP_ROUTES_CACHE':'/tmp/contract-routes.php',
 'DB_CONNECTION':'pgsql','DB_HOST':DB,'DB_DATABASE':'jakawi_club_identity_contract_test','DB_USERNAME':'identity_test','DB_PASSWORD':'disposable-contract-fixture-password',
 'POSTGRES_DB':'jakawi_club_identity_contract_test','POSTGRES_USER':'identity_test','POSTGRES_PASSWORD':'disposable-contract-fixture-password',
 'IDENTITY_CLUB_KEY_ID':'club-test-v1','IDENTITY_CLUB_SECRET':'s'*64,'IDENTITY_CLUB_EMAIL_PROOF_SECRET':'p'*64,
 'CACHE_STORE':'array','SESSION_DRIVER':'database','SESSION_SECURE_COOKIE':'false','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','LOG_CHANNEL':'null','BCRYPT_ROUNDS':'4','CRM_BRIDGE_ENABLED':'false'}
fd=os.open(fixture_env,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
with os.fdopen(fd,'w') as f:f.write(''.join(k+'='+v+'\n' for k,v in values.items()))
try:
    run(['docker','run','-d','--name',DB,'--label','jakawi.club.identity-contract-test=true','--network','jakawi-club-private',
      '--memory','128m','--memory-swap','160m','--cpus','0.35','--pids-limit','64','--env-file',str(fixture_env),
      '--tmpfs','/var/lib/postgresql/data:rw,size=256m',
      'postgres:16-alpine@sha256:f4610795587492cd4fb887c2dc3de301b813278bb6f616992061141b5f15ac36'],stdout=subprocess.DEVNULL)
    created.append(DB)
    for i in range(30):
        if subprocess.run(['docker','exec',DB,'pg_isready','-h','127.0.0.1','-U','identity_test','-d','jakawi_club_identity_contract_test'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:break
        time.sleep(1)
    else:raise RuntimeError('Disposable PostgreSQL unavailable')
    run(['docker','run','-d','--name',COM,'--label','jakawi.club.identity-contract-test=true','--network','jakawi-club-private',
      '--memory','256m','--memory-swap','320m','--cpus','0.65','--pids-limit','96','--env-file',str(fixture_env),
      '--volume',str(ROOT/'scripts')+':/checks:ro','--tmpfs','/var/www/html/storage:rw,mode=1777,size=32m',
      '--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777,size=8m',image,'sh','-c',
      'mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs && exec php -S 0.0.0.0:8080 -t public public/index.php'],stdout=subprocess.DEVNULL)
    created.append(COM)
    with (EVIDENCE/'com-fixture-init.txt').open('w') as log:run(['docker','exec',COM,'php','/checks/identity-com-fixtures.php'],stdout=log,stderr=subprocess.STDOUT)
    args=['docker','run','--rm','--name','jakawi-club-identity-contract-tests','--network','jakawi-club-private',
      '--memory','256m','--memory-swap','320m','--cpus','0.65','--pids-limit','96','--env-file','/srv/sites/jakawi.club/secrets/app.env']
    for env in ['APP_ENV=testing','APP_DEBUG=false','LOG_CHANNEL=null','APP_CONFIG_CACHE=/tmp/config.php','APP_ROUTES_CACHE=/tmp/routes.php',
      'SESSION_DRIVER=database','CACHE_STORE=array','MAIL_MAILER=array','BCRYPT_ROUNDS=4']:args+=['--env',env]
    for source,target in [(ROOT/'app','/var/www/html'),(ROOT/'scripts','/tests'),(EVIDENCE,'/evidence')]:args+=['--volume',str(source)+':'+target+('' if target=='/evidence' else ':ro')]
    args+=['--tmpfs','/var/www/html/storage:rw,mode=1777,size=32m','--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777,size=8m','jakawi-club-php:8.4-base-v1','php','/tests/identity-com-contract-tests.php']
    run(args)
    check=r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$before=json_decode(file_get_contents(storage_path('com-fixture-before.json')),true);$rows=Illuminate\Support\Facades\DB::table('users')->whereIn('email',['existing-com@example.test','other-com@example.test'])->orderBy('id')->get()->all();$new=App\Models\User::where('email','new-com-account@example.test')->firstOrFail();$result=['original_com_users_unchanged'=>$before['digest']===hash('sha256',json_encode($rows)),'exactly_one_provisioned_user'=>App\Models\User::count()===3,'new_user_not_admin'=>!$new->is_admin,'new_user_verified'=>$new->email_verified_at!==null,'new_links'=>Illuminate\Support\Facades\DB::table('identity_links')->count()===2,'no_memberships'=>Illuminate\Support\Facades\DB::table('memberships')->count()===0,'no_partner_roles'=>Illuminate\Support\Facades\DB::table('partner_user')->count()===0,'no_crm_deliveries'=>Illuminate\Support\Facades\DB::table('crm_deliveries')->count()===0];echo json_encode($result);if(in_array(false,$result,true))exit(1);'''
    result=subprocess.check_output(['docker','exec','-i',COM,'php'],input=check.encode(),cwd=ROOT)
    checks=json.loads(result);(EVIDENCE/'com-invariants.json').write_text(json.dumps(checks,indent=2)+'\n');print(json.dumps(checks))
finally:
    for name in reversed(created):subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    fixture_env.unlink(missing_ok=True)
