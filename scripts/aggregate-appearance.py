import hashlib,json
from pathlib import Path
root=Path(__file__).resolve().parent.parent
p=root/'artifacts/global-appearance-v1'
manifest=hashlib.sha256((root/'app/public/build-next/manifest.json').read_bytes()).hexdigest()
parts=[p/f'browser-{width}-{mode}.json' for width in [390,1440] for mode in ['light','dark']]+[p/'behavior-browser.json']
checks=[];contrast=[]
for part in parts:
 data=json.loads(part.read_text())
 assert data['manifest_sha256']==manifest and data['no_live_mutations']
 checks.extend(data['checks']);contrast.extend(data['contrast'])
video=json.loads((p/'video.json').read_text())
video_pass=len(video['videoReport'])==2 and all(row['verified'] for row in video['videoReport'])
if video_pass: assert json.loads((p/'video-browser.json').read_text())['manifest_sha256']==manifest
(p/'browser.json').write_text(json.dumps({'checks':checks,'contrast':contrast,'no_live_mutations':True,'manifest_sha256':manifest,'video_verified':video_pass},indent=2)+'\n')
print(json.dumps({'passed':len(checks),'real_video':video_pass,'manifest_sha256':manifest}))
