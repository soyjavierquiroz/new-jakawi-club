#!/usr/bin/env python3
"""Read-only verification; output fingerprints, never commercial/account data."""
import hashlib,json,re,subprocess
from pathlib import Path
from urllib.request import urlopen

source=Path(__file__).resolve().parent.parent
live=Path('/opt/sites/jakawi.club')
out=source/'artifacts/editorial-metrics/LIVE.json'
result=json.loads(out.read_text())
assert subprocess.check_output(['git','rev-parse','HEAD'],cwd=live,text=True).strip()==result['sha']
assert not subprocess.check_output(['git','status','--porcelain'],cwd=live)
manifest=json.loads((live/'app/public/build/manifest.json').read_text())
assert hashlib.sha256((live/'app/public/build/manifest.json').read_bytes()).hexdigest()==result['manifest_sha256']
contents=[];htmls=[]
for route in ['/','/principal','/ads/meta/webinar']:
    with urlopen('https://jakawi.club'+route,timeout=20) as response:
        assert response.status==200
        html=response.read().decode();htmls.append(html)
    page=json.loads(re.search(r'<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)</script>',html)[1])
    contents.append(page['props']['landing']['content'])
assert contents[0]==contents[1]==contents[2]
# Verify primary public/editor entrypoints and all their imported dependencies.
keys=[k for k in manifest if k in ['resources/js/app.tsx','resources/css/app.css'] or 'Welcome' in k or 'LandingEditor' in k or 'OpportunityCard' in k]
assert any('LandingEditor' in k for k in keys) and any('Welcome' in k for k in keys)
files=set();seen=set()
def visit(key):
    if key in seen:return
    seen.add(key);entry=manifest[key];files.add(entry['file']);files.update(entry.get('css',[]))
    for dep in entry.get('imports',[]):visit(dep)
for key in keys:visit(key)
for file in files:
    with urlopen('https://jakawi.club/build/'+file,timeout=20) as response:
        assert response.status==200 and response.read()==(source/'app/public/build-next'/file).read_bytes(),file
for html in htmls:
    assert '/build/'+manifest['resources/css/app.css']['file'] in html
health={}
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']:
    with urlopen(url,timeout=20) as response:health[url]=response.status
assert set(health.values())=={200}
after=json.loads(subprocess.check_output(['docker','compose','exec','-T','--user','www-data','app','php'],cwd=live,input=(source/'scripts/editorial-metrics-live-snapshot.php').read_bytes()))
before=json.loads((Path(result['rollback'])/'before.json').read_text())
assert after==before
result.update(status='PASS',health=health,public_channels_equal=True,public_assets_verified=len(files),published_content_sha256=hashlib.sha256(json.dumps(contents[0],sort_keys=True,ensure_ascii=False).encode()).hexdigest(),configuration_and_economics_preserved=True)
out.write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
