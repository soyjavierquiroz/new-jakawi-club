#!/usr/bin/env python3
"""One-time private backup for this CLUB-only SSO release. No account mutation."""
from hashlib import sha256
import json, os
from pathlib import Path
import shutil, subprocess

ROOT=Path('/opt/sites/jakawi.club')
PRIVATE=Path('/srv/sites/jakawi.club/rollback-identity-phase04')
os.umask(0o077)
PRIVATE.mkdir(exist_ok=True)
assert not (PRIVATE/'baseline.json').exists(), 'Baseline already captured; do not overwrite'
def command(args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
assert not command(['git','status','--porcelain']), 'Production checkout must be clean'
head=command(['git','rev-parse','HEAD']).decode().strip()
files={p:sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files']).decode().splitlines() if (ROOT/p).is_file()}
containers={}
for name in ['jakawi-club-app','jakawi-club-web','jakawi-club-db','jakawi-app-1','jakawi-web-1','jakawi-worker-1','jakawi-scheduler-1','crm-jakawi-private-app-1','crm-jakawi-private-web-1','crm-jakawi-private-db-1']:
 c=json.loads(command(['docker','inspect',name]))[0]
 containers[name]={'id':c['Id'],'image':c['Image'],'started_at':c['State']['StartedAt'],'restarts':c['RestartCount']}
source=r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=[];foreach(['users','password_reset_tokens','marketing_landings','club_identity_handoffs','club_identity_links'] as $table){$columns=Illuminate\Support\Facades\Schema::getColumnListing($table);if($table==='club_identity_links')$columns=array_values(array_diff($columns,['checked_at']));$rows=Illuminate\Support\Facades\DB::table($table)->select($columns)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$r[$table]=['columns'=>$columns,'count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}echo json_encode($r);'''
tables=json.loads(command(['docker','exec','-i','jakawi-club-app','php'],input=source.encode()))
shutil.copytree(ROOT/'app/public/build',PRIVATE/'build')
shutil.copytree(ROOT/'app/vendor',PRIVATE/'vendor')
shutil.copytree('/srv/sites/jakawi.club/bootstrap-cache',PRIVATE/'bootstrap-cache')
(PRIVATE/'source.tar').write_bytes(command(['git','archive',head]))
(PRIVATE/'baseline.json').write_text(json.dumps({'sha':head,'files':files,'containers':containers,'tables':tables,'env_sha256':sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest()},indent=2)+'\n')
print(json.dumps({'baseline_sha':head,'private_backup_ready':True,'scope':'CLUB source, vendor, assets, cache; account digests only'}))
