#!/usr/bin/env python3
"""Capture only the CLUB rollback and protected state for commercial reception."""
from hashlib import sha256
from pathlib import Path
import json, os, shutil, subprocess

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-commercial-phase05')
os.umask(0o077)
PRIVATE.mkdir(exist_ok=True)
assert not (PRIVATE/'baseline.json').exists(), 'Do not overwrite the release baseline'
def command(args, **kw):
    return subprocess.check_output(args, cwd=ROOT, **kw)
assert not command(['git','status','--porcelain'])
head = command(['git','rev-parse','HEAD']).decode().strip()
files = {p:sha256((ROOT/p).read_bytes()).hexdigest() for p in command(['git','ls-files','app','docker']).decode().splitlines() if (ROOT/p).is_file()}
env = {p.name:sha256(p.read_bytes()).hexdigest() for p in (Path('/srv/sites/jakawi.club/secrets')/name for name in ['app.env','com-identity.env','com-oidc.env','crm-hmac'])}
containers = {}
for name in ['jakawi-club-app','jakawi-club-web','jakawi-club-db']:
    c=json.loads(command(['docker','inspect',name]))[0]
    containers[name]={'id':c['Id'],'started_at':c['State']['StartedAt'],'restarts':c['RestartCount']}
source = r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=[];foreach(['users','password_reset_tokens','marketing_landings','marketing_landing_event_totals','club_identity_handoffs','club_identity_links'] as $table){$rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$r[$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows))];}echo json_encode($r);'''
tables=json.loads(command(['docker','exec','-i','jakawi-club-app','php'],input=source.encode()))
(PRIVATE/'source.tar').write_bytes(command(['git','archive',head]))
shutil.copytree(ROOT/'app/public/build',PRIVATE/'build')
shutil.copytree('/srv/sites/jakawi.club/bootstrap-cache',PRIVATE/'bootstrap-cache')
(PRIVATE/'baseline.json').write_text(json.dumps({'sha':head,'files':files,'env':env,'tables':tables,'containers':containers},indent=2)+'\n')
print(json.dumps({'baseline_sha':head,'club_rollback_ready':True}))
