#!/usr/bin/env python3
"""Verify this SSO release against its private baseline without impersonating the pilot."""
from datetime import datetime
from hashlib import sha256
import base64, http.cookiejar, json, shlex, subprocess
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from urllib.request import Request, build_opener, HTTPCookieProcessor, HTTPRedirectHandler, urlopen
from urllib.error import HTTPError
from zoneinfo import ZoneInfo
ROOT=Path('/opt/sites/jakawi.club')
OUT=ROOT/'artifacts/identity-phase04'
PRIVATE=Path('/srv/sites/jakawi.club/rollback-identity-phase04')
base=json.loads((PRIVATE/'baseline.json').read_text())
def command(args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def php(source):return json.loads(command(['docker','exec','-i','jakawi-club-app','php'],input=source.encode()))
head=command(['git','rev-parse','HEAD']).decode().strip()
assert not command(['git','status','--porcelain'])
assert head==command(['git','rev-parse','origin/main']).decode().strip()==command(['git','ls-remote','origin','refs/heads/main']).decode().split()[0]
assert sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest()==base['env_sha256']
# Everything outside the explicit SSO change set must retain the previous source bytes.
allowed={'app/app/Http/Controllers/AuthController.php','app/app/Http/Middleware/HandleInertiaRequests.php','app/composer.json','app/composer.lock','app/resources/js/pages/Auth.tsx','app/routes/web.php','compose.yaml','README.md','.gitignore','artifacts/global-identity/REPORT.md','artifacts/identity-phase03/REPORT.md','docker/nginx/nginx.conf','docker/nginx/default.conf'}
protected=[p for p in base['files'] if p.startswith(('app/','docker/')) and p not in allowed]
assert all(sha256((ROOT/p).read_bytes()).hexdigest()==base['files'][p] for p in protected)
files=command(['git','ls-files','app']).decode().splitlines()
expected={p.removeprefix('app/'):sha256((ROOT/p).read_bytes()).hexdigest() for p in files if (ROOT/p).is_file() and not p.startswith(('app/storage/','app/bootstrap/cache/'))}
encoded=base64.b64encode(json.dumps(list(expected)).encode()).decode()
assert php("<?php $r=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $f)$r[$f]=hash_file('sha256',$f);echo json_encode($r);")==expected
web_files={'/etc/nginx/nginx.conf':'docker/nginx/nginx.conf','/etc/nginx/conf.d/default.conf':'docker/nginx/default.conf'}
for mounted,local in web_files.items():assert command(['docker','exec','jakawi-club-web','cat',mounted])==(ROOT/local).read_bytes()
encoded=base64.b64encode(json.dumps(base['tables']).encode()).decode()
state=php(r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before=json_decode(base64_decode('''+"'"+encoded+"'"+r'''),true);$tables=[];foreach($before as $table=>$b){$rows=Illuminate\Support\Facades\DB::table($table)->select($b['columns'])->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$table]=count($rows)===$b['count']&&hash('sha256',json_encode($rows))===$b['digest'];}
$u=App\Models\User::where('email','javierquiroztv@gmail.com')->firstOrFail();$link=app(App\Services\Identity\ComIdentityClient::class)->link($u->uuid);
echo json_encode(['protected_tables'=>$tables,'oidc_enabled'=>config('oidc.enabled')===true,'client_id'=>config('oidc.client_id'),'secret_digest'=>hash('sha256',(string)config('oidc.client_secret')),'pilot_activated_verified_admin'=>$u->activated_at!==null&&$u->hasVerifiedEmail()&&$u->is_marketing_admin,'pilot_linked_same_subject'=>$u->global_subject_id!==null&&$u->global_subject_id===($link['global_subject_id']??null)&&($link['status']??null)==='LINKED','registration_open'=>App\Support\LaunchSettings::registrationOpen(),'smtp_ready'=>App\Support\LaunchSettings::mailReady(),'session_host_only'=>config('session.domain')===null,'session_secure'=>config('session.secure')===true&&config('session.http_only')===true&&config('session.same_site')==='lax'&&config('session.encrypt')===true,'library_version'=>Composer\InstalledVersions::getPrettyVersion('jumbojett/openid-connect-php'),'cache_store'=>config('cache.default'),'routes_cached'=>app()->routesAreCached(),'config_cached'=>app()->configurationIsCached()]);''')
assert all(state['protected_tables'].values()),state['protected_tables']
for key in ['oidc_enabled','pilot_activated_verified_admin','pilot_linked_same_subject','registration_open','smtp_ready','session_host_only','session_secure','routes_cached','config_cached']:assert state[key],key
assert state['cache_store']=='database', 'OIDC route blocking requires a shared lock store'
secret_file=Path('/srv/sites/jakawi.club/secrets/com-oidc.env')
assert secret_file.stat().st_mode&0o777==0o600 and secret_file.stat().st_uid==0
values={k:shlex.split(v)[0] for line in secret_file.read_text().splitlines() for k,sep,v in [line.partition('=')] if sep}
assert state.pop('secret_digest')==sha256(values['OIDC_CLUB_CLIENT_SECRET'].encode()).hexdigest()
assert state['client_id']==values['OIDC_CLUB_CLIENT_ID']
for name,old in base['containers'].items():
 if name=='jakawi-club-app':continue
 c=json.loads(command(['docker','inspect',name]))[0]
 assert c['Id']==old['id'] and c['Image']==old['image'] and c['State']['StartedAt']==old['started_at'] and c['RestartCount']==old['restarts'],name
com_sha=command(['docker','inspect','jakawi-app-1','--format','{{index .Config.Labels "org.opencontainers.image.revision"}}']).decode().strip()
assert com_sha=='61af05e4a0b153cfa9970db435bb467bc6971d4c'
health={}
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up','https://jakawi.com/up']:
 with urlopen(url,timeout=20)as response:assert response.status==200;health[url]=200
assets={}
for entry in json.loads((ROOT/'app/public/build/manifest.json').read_text()).values():
 for filename in [entry['file'],*entry.get('css',[])]:
  if filename in assets:continue
  with urlopen('https://jakawi.club/build/'+filename,timeout=20)as response:assert response.status==200;content=response.read()
  assert content==(ROOT/'app/public/build'/filename).read_bytes();assets[filename]=sha256(content).hexdigest()
for file in (PRIVATE/'build').rglob('*'):
 if file.is_file() and file.name!='manifest.json':assert file.read_bytes()==(ROOT/'app/public/build'/file.relative_to(PRIVATE/'build')).read_bytes()
class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*a,**kw):return None
browser=build_opener(NoRedirect(),HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(url):
 try:return browser.open(url,timeout=20)
 except HTTPError as error:return error
response=get('https://jakawi.club/login');assert response.code==200
page=response.read().decode();assert '"jakawiSso":true' in page and '"mode":"login"' in page
response=get('https://jakawi.club/auth/jakawi');assert response.code==302
url=response.headers['Location'];q=parse_qs(urlparse(url).query)
assert url.startswith('https://jakawi.com/oauth/authorize?') and q['redirect_uri']==['https://jakawi.club/auth/jakawi/callback'] and q['code_challenge_method']==['S256']
assert q['client_id']==[state['client_id']] and len(q['state'][0])>=64 and len(q['nonce'][0])>=64
assert 'no-store' in response.headers['Cache-Control']
response=get(url);assert response.code==302 and response.headers['Location']=='https://jakawi.com/login'
# A real provider response with prompt=none exercises state/cookie continuity without any account login.
response=get(url+'&prompt=none');assert response.code==302
callback=response.headers['Location'];cq=parse_qs(urlparse(callback).query)
assert cq['error']==['login_required'] and cq['state']==q['state']
response=get(callback);assert response.code==302 and response.headers['Location']=='https://jakawi.club/login'
assert 'no-store' in response.headers['Cache-Control'] and response.headers['Referrer-Policy']=='no-referrer'
assert set(response.headers.get_all('Referrer-Policy'))=={'no-referrer'}
response=get('https://jakawi.club/auth/jakawi/callback?code=invalid&state=invalid');assert response.code==302 and response.headers['Location']=='https://jakawi.club/login'
# Verify the installed confidential credential without obtaining a token or touching a user.
from urllib.parse import urlencode
basic=base64.b64encode((state['client_id']+':'+values['OIDC_CLUB_CLIENT_SECRET']).encode()).decode()
request=Request('https://jakawi.com/oauth/token',data=urlencode({'grant_type':'authorization_code','code':'invalid-club-deployment-check','redirect_uri':'https://jakawi.club/auth/jakawi/callback','code_verifier':'a'*64}).encode(),headers={'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded','Authorization':'Basic '+basic})
try: response=browser.open(request,timeout=20)
except HTTPError as error: response=error
assert response.code==400 and json.loads(response.read())['error']=='invalid_grant'
checks=json.loads((OUT/'sso-tests.json').read_text())
browser_checks=json.loads((OUT/'browser-live.json').read_text())
assert all(x['primary_sso'] and x['local_login_retained'] and x['no_overflow'] for x in browser_checks['checks'])
result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,'origin_main_synced':True,'tree_clean':True,'scope':'CLUB only','com_sha_unchanged':com_sha,'other_containers_unchanged':True,'mounted_files_verified':len(expected),'previous_sources_preserved':len(protected),'smtp_environment_unchanged':True,'previous_assets_preserved':True,'https_assets_verified':len(assets),'health':health,'state':state,'isolated_contract_checks_passed':checks['passed'],'live_login_redirect_callback_error_checked':True,'live_confidential_credential_verified':True,'pilot_real_browser_login':'pending holder verification; no impersonation','no_live_test_accounts':True}
(OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
