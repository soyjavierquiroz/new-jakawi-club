#!/usr/bin/env python3
"""Focused LIVE proof; reads real endpoints and assets, creates no account or economic fixture."""
import hashlib,json,subprocess,urllib.request,urllib.error
from pathlib import Path
from urllib.parse import urlparse,parse_qs
SOURCE=Path(__file__).resolve().parents[1]
LIVE=Path('/opt/sites/jakawi.club')
def git(root,*args):return subprocess.check_output(['git',*args],cwd=root,text=True).strip()
sha=git(LIVE,'rev-parse','HEAD')
assert sha==git(SOURCE,'rev-parse','HEAD')==git(SOURCE,'rev-parse','origin/main')
assert not git(LIVE,'status','--porcelain')
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
def get(path):
 try:
  response=opener.open('https://jakawi.club'+path,timeout=20)
 except urllib.error.HTTPError as e:response=e
 return response.code,response.headers,response.read()
status,_,_=get('/up');assert status==200
status,headers,_=get('/afiliados');assert status==302
location=urlparse(headers['Location']);query=parse_qs(location.query)
assert location.scheme=='https' and location.netloc=='jakawi.com' and location.path=='/oauth/authorize'
assert query['response_type']==['code'] and query['code_challenge_method']==['S256'] and query.get('nonce') and query.get('state')
assert query['redirect_uri']==['https://jakawi.club/auth/jakawi/callback']
status,headers,_=get('/auth/jakawi/callback');assert status==302 and urlparse(headers['Location']).path=='/login'
manifest=json.loads((LIVE/'app/public/build/manifest.json').read_text())
expected=SOURCE/'app/public/build-next'
assert (expected/'manifest.json').read_bytes()==(LIVE/'app/public/build/manifest.json').read_bytes()
assets=[]
for key in ['resources/js/app.tsx','resources/js/pages/Affiliates.tsx','resources/js/pages/admin/AffiliatePartners.tsx']:
 entry=manifest[key];assets.append(entry['file']);assets+=entry.get('css',[])
for asset in assets:
 status,_,raw=get('/build/'+asset);assert status==200
 assert hashlib.sha256(raw).digest()==hashlib.sha256((expected/asset).read_bytes()).digest()
baseline=json.loads(Path('/srv/sites/jakawi.club/rollback-partner-sso-10d/baseline.json').read_text())
for file in git(SOURCE,'diff','--name-only',baseline['sha'],sha,'--','app/app','app/routes').splitlines():
 if file.startswith('app/app/') or file.startswith('app/routes/'):
  assert (SOURCE/file).read_bytes()==(LIVE/file).read_bytes()
backup=Path('/srv/sites/jakawi.club/rollback-partner-sso-10d')
baseline=json.loads((backup/'baseline.json').read_text())
assert baseline['env']=={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').glob('*.env')}
for name,started in baseline['other_containers'].items():assert json.loads(subprocess.check_output(['docker','inspect',name]))[0]['State']['StartedAt']==started
result={'sha_live':sha,'up_http':200,'guest_affiliates_starts_oidc':True,'callback_without_transaction_denied':True,'published_assets_match_tested_build':True,'secrets_unchanged':True,'com_crm_not_restarted':True,'no_live_fixtures':True}
(SOURCE/'artifacts/partner-sso-10d/LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
