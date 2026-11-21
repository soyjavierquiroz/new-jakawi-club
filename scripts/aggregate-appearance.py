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
video=json.loads((p/'video-local.json').read_text())
video_pass=video['real_media'] and video['native_clock'] and len(video['videoReport'])==2 and all(row['verified'] for row in video['videoReport'])
assert video_pass and json.loads((p/'video-local-browser.json').read_text())['manifest_sha256']==manifest
checks.extend(json.loads((p/'video-local-browser.json').read_text())['checks'])
(p/'browser.json').write_text(json.dumps({'checks':checks,'contrast':contrast,'no_live_mutations':True,'manifest_sha256':manifest,'video_verified':video_pass,'video_verification_scope':'Real local WebM with native playback clocks and audio; QA transport adapter only','external_youtube_verified':False},indent=2)+'\n')
print(json.dumps({'passed':len(checks),'isolated_real_media_video':video_pass,'external_youtube_verified':False,'manifest_sha256':manifest}))
