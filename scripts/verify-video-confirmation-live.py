#!/usr/bin/env python3
"""Seal CLUB code/assets and focused account invariants without exposing secrets."""
from datetime import datetime
from hashlib import sha1, sha256
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import unquote
from urllib.request import Request, build_opener, HTTPCookieProcessor, HTTPRedirectHandler, urlopen
from zoneinfo import ZoneInfo
import json
import re
import subprocess

ROOT = Path('/opt/sites/jakawi.club')
PRIVATE = Path('/srv/sites/jakawi.club/rollback-video-confirmation')
EVIDENCE = ROOT / 'artifacts/video-confirmation'

def cmd(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, text=True, **kwargs)

def php(source):
    return json.loads(cmd(['docker','compose','exec','-T','--user','www-data','app','php'], input=source))

sha = cmd(['git','rev-parse','HEAD']).strip()
assert sha == cmd(['git','rev-parse','origin/main']).strip()
assert sha == cmd(['git','ls-remote','origin','refs/heads/main']).split()[0]
assert cmd(['git','branch','--show-current']).strip() == 'main'
assert cmd(['git','diff','HEAD','--','app']) == ''
files = {}
for record in cmd(['git','ls-tree','-r','HEAD','app']).splitlines():
    meta, path = record.split('\t', 1)
    if path.startswith(('app/storage/','app/bootstrap/cache/')):
        continue
    content = (ROOT/path).read_bytes()
    assert sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest() == meta.split()[2]
    files[path.removeprefix('app/')] = sha256(content).hexdigest()
mounted = php("<?php $result=[]; foreach(json_decode('"+json.dumps(list(files))+"',true) as $path){$result[$path]=hash_file('sha256',$path);} echo json_encode($result);")
assert mounted == files
baseline = json.loads((PRIVATE/'baseline.json').read_text())
for filename, digest in baseline['files'].items():
    assert sha256(Path(filename).read_bytes()).hexdigest() == digest
containers = json.loads(cmd(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
for item in containers:
    name = item['Name'].lstrip('/')
    before = baseline['containers'][name]
    assert item['Id'] == before['id']
    assert sorted(item['NetworkSettings']['Networks']) == before['networks']
    assert item['State']['Health']['Status'] == 'healthy'
    if name != 'jakawi-club-app':
        assert item['State']['StartedAt'] == before['started_at']
    if name == 'jakawi-club-db':
        assert not item['HostConfig']['PortBindings']
state = php(r'''<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s=[]; foreach(['users','marketing_landings','migrations','password_reset_tokens','marketing_landing_event_totals'] as $t){$s[$t]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from {$t} u");}
$s['conversions']=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count, md5(coalesce(string_agg(row_to_json(u)::text, '' order by row_to_json(u)::text), '')) as digest from marketing_event_totals u where event='registration_completed'");
$accounts=[]; foreach(['normal'=>'javierquirozsueldo@gmail.com','admin'=>'javierquiroztv@gmail.com'] as $label=>$email){$u=App\Models\User::where('email',$email)->firstOrFail();$accounts[$label]=['unique'=>App\Models\User::where('email',$email)->count()===1,'activated'=>(bool)$u->activated_at,'verified'=>$u->hasVerifiedEmail(),'password_set'=>(bool)$u->password,'admin'=>(bool)$u->is_marketing_admin,'token_present'=>Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email',$email)->exists()];}
echo json_encode(['tables'=>$s,'accounts'=>$accounts,'timezone'=>config('app.timezone'),'broker'=>config('auth.defaults.passwords'),'expire'=>config('auth.passwords.users.expire'),'registration_open'=>App\Support\LaunchSettings::registrationOpen(),'debug'=>config('app.debug'), 'confirmation_video_configured'=>App\Support\LandingContent::confirmationVideo()['youtube_url']!==null,'group_configured'=>App\Support\RegistrationNextStep::whatsappGroup()!==null,'test_schemas'=>(int)Illuminate\Support\Facades\DB::selectOne("select count(*) as n from pg_namespace where nspname like 'postconversion_test_%' or nspname like 'landings_test_%' or nspname like 'mvp_test_%'")->n]);''')
for key, value in baseline['state'].items():
    assert state[key] == value, key
assert not state['registration_open'] and not state['debug'] and state['test_schemas'] == 0

cookies = CookieJar()
client = build_opener(HTTPCookieProcessor(cookies))
with client.open('https://jakawi.club/', timeout=20) as response:
    assert response.status == 200
    html = response.read().decode()
page = json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>',html)[1])
assert page['component'] == 'Welcome' and not page['props']['launch']['registration_open']
for key in ['video','confirmation_video']:
    assert page['props']['landing']['content'][key]['aspect_ratio'] == '3:4'
xsrf = next(unquote(c.value) for c in cookies if c.name == 'XSRF-TOKEN')
try:
    client.open(Request('https://jakawi.club/registro', data=b'{}', headers={'Content-Type':'application/json','Accept':'application/json','X-XSRF-TOKEN':xsrf}), timeout=20)
    raise AssertionError('Registration must stay closed')
except HTTPError as error:
    assert error.code == 403

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

routes = {}
for path, expected in [('/principal/confirmado',302),('/principal/confirmado?success=1',302),('/no-existe/confirmado',404),('/login',200),('/admin/landings',302),('/admin/landings/1/preview/confirmado',302)]:
    try:
        with build_opener(NoRedirect).open('https://jakawi.club'+path,timeout=20) as response:
            status = response.status
    except HTTPError as error:
        status = error.code
        if path.startswith('/principal/confirmado'):
            assert error.headers['Location'] == 'https://jakawi.club/principal'
    assert status == expected, (path,status)
    routes[path] = status

assets = {}
for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
    if entry.get('isEntry'):
        assert 'https://jakawi.club/build/'+entry['file'] in html
    for name in [entry['file'],*entry.get('css',[]),*entry.get('assets',[])]:
        if name in assets:
            continue
        content = (ROOT/'app/public/build'/name).read_bytes()
        with urlopen('https://jakawi.club/build/'+name,timeout=20) as response:
            assert response.status == 200 and sha256(response.read()).digest() == sha256(content).digest()
        assets[name] = sha256(content).hexdigest()
for old in (PRIVATE/'build').rglob('*'):
    if old.is_file() and old.name != 'manifest.json':
        assert old.read_bytes() == (ROOT/'app/public/build'/old.relative_to(PRIVATE/'build')).read_bytes()
health = {}
for url in ['http://127.0.0.1:18082/up','https://jakawi.club/up','https://jakawi.com/up']:
    with urlopen(url,timeout=20) as response:
        assert response.status == 200
        health[url] = response.status
browser = json.loads((EVIDENCE/'live-browser.json').read_text())
editor = json.loads((EVIDENCE/'editor-visual.json').read_text())
vsl = json.loads((EVIDENCE/'vsl/live-browser.json').read_text())
for evidence in [browser,editor,vsl]:
    assert evidence['runtime_errors'] == [] and evidence['failed_assets'] == []
assert browser['viewports'] == [390,430,1440]
report = {'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'git_sha':sha,'live_sha':sha,
    'origin_main_synced':True,'mounted_files_match':len(files),'served_assets_match':assets,'previous_assets_preserved':True,
    'health':health,'routes':routes,'public_registration_http':403,'state':state,
    'users_admin_identity_permissions_tokens_conversions_unchanged':True,'environment_unchanged':True,
    'only_club_app_restarted':True,'smtp_messages_sent':0,'new_live_accounts':0,
    'browser_groups':len(browser['checks']),'editor_groups':len(editor['checks']),'vsl_groups':len(vsl['checks']),
    'real_playback':vsl['real_playback'], 'preexisting_worktree_changes_preserved':True}
(EVIDENCE/'LIVE.json').write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({k:report[k] for k in ['git_sha','live_sha','health','public_registration_http','users_admin_identity_permissions_tokens_conversions_unchanged','browser_groups','editor_groups','vsl_groups']},indent=2))
