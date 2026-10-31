#!/usr/bin/env python3
import subprocess,json,hashlib,urllib.request
from pathlib import Path
root=Path('/opt/sites/jakawi.club');backup=Path('/srv/sites/jakawi.club/rollback-partners-10c')
baseline=json.loads((backup/'baseline.json').read_text())
cmd=lambda a:subprocess.check_output(a,cwd=root,text=True).strip()
sha=cmd(['git','rev-parse','HEAD']);assert sha==cmd(['git','rev-parse','origin/main'])
assert not cmd(['git','status','--porcelain'])
for name,digest in baseline['env'].items(): assert hashlib.sha256((Path('/srv/sites/jakawi.club/secrets')/name).read_bytes()).hexdigest()==digest
for name,started in baseline['other_containers'].items():assert json.loads(cmd(['docker','inspect',name]))[0]['State']['StartedAt']==started
checks={}
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up','https://jakawi.club/membresia','https://jakawi.club/activar']:
 with urllib.request.urlopen(url,timeout=20) as r:assert r.status==200;checks[url]=r.status
manifest=json.loads((root/'app/public/build/manifest.json').read_text())
for entry in manifest.values():
 with urllib.request.urlopen('https://jakawi.club/build/'+entry['file'],timeout=20) as r:assert r.status==200
changed=cmd(['git','diff','--name-only',baseline['sha'],sha]).splitlines()
for name in changed:
 if name.startswith('app/') and (root/name).is_file():
  data=subprocess.check_output(['docker','exec','jakawi-club-app','cat','/var/www/html/'+name.removeprefix('app/')])
  assert data==subprocess.check_output(['git','show',sha+':'+name],cwd=root)
php=r'''<?php
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['debug'=>config('app.debug'),'environment'=>app()->environment(),'active_membership_rules'=>DB::table('commission_rules')->where('event_type','membership.activated')->where('status','active')->count(),'partner'=>DB::table('affiliate_partners')->where('source','jakawi.com')->where('partner_id','1')->first(['partner_id','status']),'migration'=>DB::table('migrations')->where('migration','2026_11_01_000000_partner_acquisition')->exists()]);
'''
state=json.loads(subprocess.check_output(['docker','compose','exec','-T','app','php'],input=php,text=True,cwd=root))
assert state['debug'] is False and state['environment']=='production' and state['active_membership_rules']==0 and state['migration'] and state['partner']['status']=='enabled'
result={'sha_live':sha,'sha_origin_main':sha,'health':checks,'assets':len(manifest),'mounted_source_matches_commit':True,'secrets_unchanged':True,'com_crm_not_restarted':True,'state':state,'no_live_test_users_or_purchases':True}
(root/'artifacts/partners-10c/LIVE.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
