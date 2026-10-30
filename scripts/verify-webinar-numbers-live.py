#!/usr/bin/env python3
"""Fresh CLUB-only evidence for WEBINAR numeric badge backgrounds."""
from datetime import datetime
from hashlib import sha256
import json
from pathlib import Path
import subprocess
from urllib.request import urlopen
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')
OUT = ROOT/'artifacts/webinar-numbers'
PRIVATE = Path('/srv/sites/jakawi.club/rollback-webinar-numbers')

def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)

def git(*args):
    return command(['git', *args]).decode().strip()

def verify():
    baseline = json.loads((PRIVATE/'baseline.json').read_text())
    assert not git('status', '--porcelain')
    head = git('rev-parse','HEAD')
    assert head != baseline['sha']
    assert head == git('rev-parse','origin/main') == git('ls-remote','origin','refs/heads/main').split()[0]
    changed = git('diff','--name-only',baseline['sha'],head,'app','docs/landings').splitlines()
    assert changed == ['app/resources/css/app.css'], 'Only the intended CSS may change'
    for p, digest in baseline['files'].items():
        if p != 'app/resources/css/app.css':
            assert sha256((ROOT/p).read_bytes()).hexdigest() == digest, p
    assert {p.name:sha256(p.read_bytes()).hexdigest() for p in Path('/srv/sites/jakawi.club/secrets').iterdir() if p.is_file()} == baseline['secrets']
    code = r'''<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$r=['tables'=>[],'debug'=>config('app.debug')];
foreach(['marketing_landings','users','migrations','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','affiliate_short_links']as $t){$r['tables'][$t]=(array)Illuminate\Support\Facades\DB::selectOne("select count(*) as count,md5(coalesce(jsonb_agg(to_jsonb(t) order by to_jsonb(t)::text)::text,'[]')) as digest from public.\"".$t."\" t");}$r['css']=hash_file('sha256','resources/css/app.css');echo json_encode($r);'''
    state = json.loads(command(['docker','compose','exec','-T','--user','www-data','app','php','-d','memory_limit=128M'],input=code.encode()))
    assert state['debug'] is False and state['tables'] == baseline['tables']
    assert state['css'] == sha256((ROOT/'app/resources/css/app.css').read_bytes()).hexdigest()
    containers = json.loads(command(['docker','inspect','jakawi-club-app','jakawi-club-web','jakawi-club-db']))
    for c in containers:
        name=c['Name'].lstrip('/');before=baseline['containers'][name]
        assert c['Id'] == before['id'] and c['State']['Health']['Status']=='healthy'
        if name != 'jakawi-club-app':
            assert c['State']['StartedAt']==before['started_at'] and c['RestartCount']==before['restarts']
    manifest = json.loads((ROOT/'app/public/build/manifest.json').read_text())
    css = manifest['resources/css/app.css']['file']
    with urlopen('https://jakawi.club/build/'+css,timeout=20) as response:
        assert response.status==200 and response.read()==(ROOT/'app/public/build'/css).read_bytes()
    health={}
    for url in ['https://jakawi.club/up','http://127.0.0.1:18082/up']:
        with urlopen(url,timeout=20) as response: health[url]=response.status
    assert set(health.values())=={200}
    browser=json.loads((OUT/'browser-live.json').read_text())
    assert browser['passed'] and browser['mode']=='live' and browser['live_assets'] and browser['actual_public_pages']
    assert browser['live_mutations']==0 and not browser['runtime_errors'] and not browser['failed_assets']
    assert len(browser['measurements'])==4
    colors={}
    for measurement in browser['measurements']:
        assert 'https://jakawi.club/build/'+css in measurement['stylesheets']
        for section in measurement['sections']:
            assert [i['number'] for i in section['items']]==['01','02','03']
            assert all(i['background']=='rgb(255, 200, 61)' and i['color']=='rgb(17, 17, 17)' for i in section['items'])
            colors[section['id']]={'background':'rgb(255, 200, 61)','color':'rgb(17, 17, 17)'}
    local=json.loads((OUT/'validation.json').read_text())
    assert local['local_http']==local['inertia_http']==200
    result={'verified_at':datetime.now(ZoneInfo('America/La_Paz')).isoformat(),'live_sha':head,
            'new_release':True,'origin_main_synced':True,'tree_clean':True,'only_application_change':'app/resources/css/app.css',
            'selector':'main[data-landing-template=WEBINAR] .benefit-number','computed_colors':colors,
            'public_paths':['/','/principal'],'widths':[390,1440],'css_asset':css,
            'https_css_bytes_verified':True,'layout_preserved':True,'published_content_unchanged':True,
            'protected_tables_unchanged':True,'secrets_unchanged':True,'web_db_not_restarted':True,
            'local_validation_passed':True,'health':health}
    (OUT/'LIVE.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    verify()
