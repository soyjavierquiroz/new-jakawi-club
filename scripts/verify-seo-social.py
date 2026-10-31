#!/usr/bin/env python3
"""Read-only public HTTP checks; human requests use HEAD to avoid LIVE clicks."""
from html.parser import HTMLParser
from pathlib import Path
import hashlib,json,struct,subprocess,urllib.request

ROOT=Path('/opt/sites/jakawi.club')
class Tags(HTMLParser):
    def __init__(self): super().__init__();self.meta={};self.canonical=[];self.title='';self.in_title=False
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='meta':self.meta[a.get('property',a.get('name',''))]=a.get('content','')
        if tag=='link' and a.get('rel')=='canonical':self.canonical.append(a.get('href'))
        if tag=='title':self.in_title=True
    def handle_data(self,data):
        if self.in_title:self.title+=data
    def handle_endtag(self,tag):
        if tag=='title':self.in_title=False
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None

def fetch(url,ua='facebookexternalhit/1.1'):
    return urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':ua}),timeout=25)
def jpeg_size(body):
    assert body[:2]==b'\xff\xd8'
    pos=2
    while pos<len(body):
        assert body[pos]==255
        marker=body[pos+1];pos+=2
        length=int.from_bytes(body[pos:pos+2],'big')
        if marker in (192,193,194):
            height,width=struct.unpack('>HH',body[pos+3:pos+7]);return width,height
        pos+=length
    raise AssertionError('JPEG dimensions missing')
def parse(response):
    p=Tags();p.feed(response.read().decode());return p

evidence=[];images=set()
for path in ['/', '/principal?utm_source=seo-check']:
    p=parse(fetch('https://jakawi.club'+path))
    assert p.title and p.title==p.meta['og:title'] and p.meta['robots']=='index, follow'
    assert p.canonical==['https://jakawi.club/'] and p.meta['og:url']==p.canonical[0]
    assert p.meta['og:image:type']=='image/jpeg' and p.meta['og:image:width']=='1200' and p.meta['og:image:height']=='630'
    assert p.meta['twitter:card']=='summary_large_image' and p.meta['twitter:image']==p.meta['og:image']
    images.add(p.meta['og:image']);evidence.append({'path':path,'initial_html':True,'canonical':p.canonical[0],'title':p.title})
for source,code in [('club','NGDoyetE'),('com','Ms8xvfMr')]:
    url='https://jakawi.club/r/'+code
    # HEAD takes precisely the same destination branch without recording a click.
    try:urllib.request.build_opener(NoRedirect).open(urllib.request.Request(url,method='HEAD',headers={'User-Agent':'Mozilla/5.0'}),timeout=20);raise AssertionError('Expected 302')
    except urllib.error.HTTPError as r:
        assert r.code==302 and 'noindex' in r.headers['X-Robots-Tag']
        location=r.headers['Location'];assert location.startswith('https://jakawi.'+('com/l/' if source=='com' else 'club/r/'))
        if source=='com':assert 'club_ref=' in location
    for ua in ['WhatsApp/2.25','facebookexternalhit/1.1']:
        p=parse(fetch(url,ua));assert p.meta.get('og:title') and p.meta.get('og:description') and p.meta.get('og:image')
        assert p.meta['og:url'].startswith('https://jakawi.'+('com/l/' if source=='com' else 'club/'))
        images.add(p.meta['og:image']);evidence.append({'source':source,'ua':ua,'redirect_followed':True,'commercial_og':True,'human_head_status':302})
for url in images:
    assert url.startswith('https://')
    for ua in ['WhatsApp/2.25','facebookexternalhit/1.1']:
        r=fetch(url,ua);assert r.status==200 and r.headers.get_content_type()=='image/jpeg'
        assert jpeg_size(r.read())==(1200,630)
    evidence.append({'image_https_200_jpeg_1200x630':True,'url':url,'cookies_required':False})
for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']:
    assert fetch(url).status==200
sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
remote=subprocess.check_output(['git','rev-parse','origin/main'],cwd=ROOT,text=True).strip();assert sha==remote
# Verify that the container sees exactly the tracked source files at the release SHA.
files=subprocess.check_output(['git','ls-files','app/app','app/resources','app/routes'],cwd=ROOT,text=True).splitlines()
local={p.removeprefix('app/'):hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in files}
php="$files=json_decode(stream_get_contents(STDIN),true);$out=[];foreach($files as $p)$out[$p]=hash_file('sha256',$p);echo json_encode($out);"
mounted=json.loads(subprocess.check_output(['docker','compose','exec','-T','app','php','-r',php],cwd=ROOT,input=json.dumps(list(local)),text=True))
assert mounted==local
manifest=hashlib.sha256((ROOT/'app/public/build/manifest.json').read_bytes()).hexdigest()
report={'passed':True,'sha_live':sha,'origin_main':remote,'mounted_source_matches':True,'manifest_sha256':manifest,'checks':evidence,'real_whatsapp_preview':False,'real_meta_preview':False,'meta_debugger':'HTTP 429; no authenticated session','second_published_landing':'isolated fixture; only principal is published LIVE'}
(ROOT/'artifacts/seo-social/LIVE.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({'passed':True,'sha_live':sha,'checks':len(evidence)}))
