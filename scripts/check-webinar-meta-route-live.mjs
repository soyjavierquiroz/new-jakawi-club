// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir, rm } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const live = process.argv.includes('--live');
const preview = process.argv.includes('--preview');
await mkdir(output, { recursive: true });
const addresses = await Promise.all(['jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', [...(process.argv.includes('--headful') ? [] : ['--headless=new']), '--hide-scrollbars', '--no-sandbox', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', ...(process.argv.includes('--no-helper') ? ['--disable-extensions'] : ['--disable-extensions-except=/extension', '--load-extension=/extension']), '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/jakawi-visual', '--no-first-run', '--no-default-browser-check'], { stdio: ['ignore', 'ignore', 'pipe'] });
const browserMessages = [];
chrome.stderr.on('data', data => browserMessages.push(data.toString()));
chrome.on('exit', (code, signal) => { if (code !== 0 && signal !== 'SIGTERM') console.error('Browser exit', code, signal); });
let socket;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
    let target;
    for (let i = 0; i < 150; i++) {
        try { target = await (await fetch('http://127.0.0.1:9222/json/new?about:blank', { method: 'PUT' })).json(); break; }
        catch { await pause(100); }
    }
    assert(target, 'Chromium starts: '+browserMessages.join('').slice(-1800));
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let sequence = 0;
    const pending = new Map();
    const events = new Map();
    const runtimeErrors = [];
    const failedAssets = [];
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
    socket.onclose = () => { for (const item of pending.values()) { clearTimeout(item.timer); item.reject(new Error('Browser connection closed')); } };
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id) {
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer); pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message)); else item.resolve(message.result);
        } else {
            for (const listener of events.get(message.method) || []) listener(message.params);
        }
    };
    const on = (event, fn) => events.set(event, [...(events.get(event) || []), fn]);
    const evaluate = async expression => {
        const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true });
        assert(!response.exceptionDetails, response.exceptionDetails?.exception?.description || response.exceptionDetails?.text || 'Evaluation succeeds');
        return response.result.value;
    };

    on('Runtime.exceptionThrown', event => runtimeErrors.push(event.exceptionDetails.exception?.description || event.exceptionDetails.text));
    on('Network.responseReceived', ({ response, type }) => {
        if (response.status >= 400 && ['Script', 'Stylesheet', 'Font', 'Image'].includes(type)) {
            if (response.url.startsWith('https://jakawi.club/')) failedAssets.push({ url: response.url, status: response.status });
        }
    });
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Page.bringToFront');
    await call('Emulation.setFocusEmulationEnabled', { enabled: true });
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });


    const requests=[],responses=[],failures=[],csp=[];
    on('Runtime.consoleAPICalled',e=>{if(['warning','error'].includes(e.type)) console.log(JSON.stringify({console_type:e.type,messages:e.args.map(x=>String(x.value||x.description||'').slice(0,300))}));});
    const requestIds=new Map();
    on('Network.requestWillBeSent',e=>{
        const u=new URL(e.request.url);
        const meta=['facebook.com','www.facebook.com','connect.facebook.net'].includes(u.hostname)||u.hostname.endsWith('.on.aws')||u.hostname.endsWith('.cloud.run');
        if(meta || u.pathname==='/preferencias') {
            const item={host:u.hostname,path:u.pathname,event:u.searchParams.get('ev'),pixel_id:u.searchParams.get('id'),method:e.request.method};
            requests.push(item);requestIds.set(e.requestId,item);
        }
    });
    on('Network.responseReceived',e=>{if(e.type==='Document') console.log(JSON.stringify({document_status:e.response.status,path:new URL(e.response.url).pathname}));if(requestIds.has(e.requestId))responses.push({...requestIds.get(e.requestId),status:e.response.status});});
    on('Network.loadingFailed',e=>{if(requestIds.has(e.requestId))failures.push({...requestIds.get(e.requestId),error:e.errorText,blocked:e.blockedReason});});
    await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.cspFailures=[];document.addEventListener('securitypolicyviolation', e=>window.cspFailures.push({directive:e.violatedDirective,blocked:e.blockedURI}));`});
    // Only PageView may reach Meta. Registration/purchase endpoints are never exercised.
    const oldManifest=preview?JSON.parse(await readFile('/workspace/public/build/manifest.json','utf8')):null;
    const nextManifest=preview?JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8')):null;
    await call('Fetch.enable',{patterns:[...(preview?[{urlPattern:'https://jakawi.club/build/assets/*',requestStage:'Request'},{urlPattern:'https://jakawi.club/ads/meta/webinar*',requestStage:'Response'},{urlPattern:'https://jakawi.club/principal',requestStage:'Response'}]:[]),{urlPattern:'*facebook.com/tr*',requestStage:'Request'},{urlPattern:'https://jakawi.club/activar*',requestStage:'Request'},{urlPattern:'https://jakawi.club/*registro*',requestStage:'Request'}]});
    on('Fetch.requestPaused',async e=>{
        const u=new URL(e.request.url);
        if(preview && e.responseStatusCode){if(e.responseStatusCode===200 && e.responseHeaders?.some(h=>h.name.toLowerCase()==='content-type'&&h.value.includes('text/html'))){const raw=await call('Fetch.getResponseBody',{requestId:e.requestId});let html=raw.base64Encoded?Buffer.from(raw.body,'base64').toString('utf8'):raw.body;for(const [name,v] of Object.entries(oldManifest)){if(nextManifest[name])html=html.replaceAll('/build/'+v.file,'/build/'+nextManifest[name].file);}return call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:200,responseHeaders:e.responseHeaders.filter(h=>!['content-length','content-encoding'].includes(h.name.toLowerCase())),body:Buffer.from(html).toString('base64')});}return call('Fetch.continueRequest',{requestId:e.requestId});}
        if(preview && u.hostname==='jakawi.club' && u.pathname.startsWith('/build/assets/')){let file=u.pathname.slice('/build/'.length);for(const [name,v] of Object.entries(oldManifest)){if('/build/'+v.file===u.pathname && nextManifest[name])file=nextManifest[name].file;}try {const bytes=await readFile('/workspace/public/build-next/'+file);return call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'application/octet-stream'}],body:bytes.toString('base64')});} catch {return call('Fetch.continueRequest',{requestId:e.requestId});}}
        console.log(JSON.stringify({paused_host:u.hostname,path:u.pathname,event:u.searchParams.get('ev'),body_event:new URLSearchParams(e.request.postData||'').get('ev')}));
        if(u.hostname.endsWith('facebook.com') && u.pathname.startsWith('/tr') && (u.searchParams.get('ev')==='PageView' || e.request.postData?.includes('ev=PageView'))) return call('Fetch.continueRequest',{requestId:e.requestId});
        return call('Fetch.failRequest',{requestId:e.requestId,errorReason:'BlockedByClient'});
    });
    async function snapshot(label){
        const data=await evaluate(`(()=>{const p=JSON.parse(document.querySelector('script[data-page="app"]').textContent);const a=p.props.ads||{};return {path:location.pathname,preference:a.preference,allowed:a.allowed,pixel_id:a.pixel_id,funnel:a.funnel,fbq:typeof window.fbq,callMethod:typeof window.fbq?.callMethod,queue:window.fbq?.queue?.map(x=>[x[0],x[1],x[0]==='init'?x[1]:null]),alias:window.fbq===window._fbq,sdk:!!document.querySelector('script[src*="fbevents.js"]'),state:window.fbq?.getState?.(),consentText:document.querySelector('[aria-label="Preferencias publicitarias"]')?.innerText,csp:window.cspFailures}})()`);
        const result={label,...data,requests:requests.splice(0),responses:responses.splice(0),failures:failures.splice(0)};
        console.log(JSON.stringify(result));await writeFile(output+'/'+label+'.json',JSON.stringify(result,null,2));return result;
    }
    async function navigate(path){await call('Page.navigate',{url:'https://jakawi.club'+path});for(let i=0;i<80;i++){if(await evaluate(`location.pathname===${JSON.stringify(path)} && !!document.querySelector('script[data-page="app"]')`))break;await pause(250);}await pause(4000);}
    console.log(JSON.stringify({targets:(await (await fetch('http://127.0.0.1:9222/json/list')).json()).map(x=>({id:x.id,type:x.type,url:x.url}))}));
    const results=[];
    await navigate('/ads/meta/webinar');
    for(let i=0;i<60;i++){if(responses.some(x=>x.event==='PageView'&&x.status===200))break;await pause(500);}
    const entry=await snapshot('entry');results.push(entry);
    assert.equal(entry.allowed,true);assert.equal(entry.preference,null);
    assert.equal(entry.pixel_id,'1429220108808185');assert.equal(entry.alias,true);assert.equal(entry.sdk,true);
    assert.equal(entry.consentText,undefined);assert.deepEqual(entry.csp,[]);
    assert(entry.responses.some(x=>x.path==='/en_US/fbevents.js'&&x.status===200),'real SDK 200');
    assert.equal(entry.requests.filter(x=>x.path.startsWith('/tr')&&x.event==='PageView').length,1,'one native PageView');
    assert(entry.responses.some(x=>x.path.startsWith('/tr')&&x.event==='PageView'&&x.status===200),'PageView HTTP 200');
    assert(!entry.requests.some(x=>x.path==='/preferencias'),'no preference submission');
    if(!process.argv.includes('--no-helper')) {
        const helperPage=await call('Target.createTarget',{url:'chrome-extension://nabdgbjfngilcpmgmeaoafhbolfiboao/side-panel/index.html',background:true});
        await pause(3000);
        const uiTarget=(await (await fetch('http://127.0.0.1:9222/json/list')).json()).find(t=>t.id===helperPage.targetId);
        assert(uiTarget, 'Pixel Helper UI target exists');
        if(uiTarget){
            const ui=new WebSocket(uiTarget.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ui.onopen=resolve;ui.onerror=reject;setTimeout(()=>reject(new Error('helper UI socket timeout')),6000);});let serial=0;const waiting=new Map();
            ui.onmessage=({data})=>{const m=JSON.parse(data);if(waiting.has(m.id)){waiting.get(m.id)(m);waiting.delete(m.id);}};
            const uiCall=(method,params={})=>new Promise(resolve=>{const id=++serial;const timeout=setTimeout(()=>{waiting.delete(id);resolve({error:'timeout'});},6000);waiting.set(id,m=>{clearTimeout(timeout);resolve(m);});ui.send(JSON.stringify({id,method,params}));});
            await uiCall('Runtime.enable');await pause(3000);
            const text=await uiCall('Runtime.evaluate',{expression:'document.body.innerText',returnByValue:true});
            const badge=await uiCall('Runtime.evaluate',{expression:`chrome.tabs.query({}).then(async tabs=>{const tab=tabs.find(t=>t.url?.startsWith('https://jakawi.club/ads/meta/webinar'));return {path:tab?.url ? new URL(tab.url).pathname : null,badge:tab?await chrome.action.getBadgeText({tabId:tab.id}):null}})`,awaitPromise:true,returnByValue:true});
            const helperUI={text:text.result?.result?.value,badge:badge.result?.result?.value,exception:badge.result?.exceptionDetails?.exception?.description};
            console.log(JSON.stringify({helperUI}));await writeFile(output+'/helper-ui.json',JSON.stringify(helperUI,null,2));
            assert(helperUI.text?.includes('1429220108808185') && helperUI.text?.includes('1 event') && helperUI.text?.includes('Active'),'Pixel Helper detects active webinar Pixel and one event');
            ui.close();await call('Target.closeTarget',{targetId:helperPage.targetId});
        }
    }
    const helperTarget=(await (await fetch('http://127.0.0.1:9222/json/list')).json()).find(t=>t.type==='service_worker'&&t.url.endsWith('/background.iife.js'));
    if(helperTarget){const hs=new WebSocket(helperTarget.webSocketDebuggerUrl);await new Promise(r=>hs.onopen=r);const badge=await new Promise((resolve,reject)=>{hs.onmessage=({data})=>{const m=JSON.parse(data);if(m.id===1)resolve(m);};hs.send(JSON.stringify({id:1,method:'Runtime.evaluate',params:{expression:"chrome.tabs.query({active:true,currentWindow:true}).then(async tabs=>({tabId:tabs[0]?.id,path:tabs[0]?.url ? new URL(tabs[0].url).pathname : null,badge:await chrome.action.getBadgeText({tabId:tabs[0].id})}))",awaitPromise:true,returnByValue:true}}));setTimeout(()=>reject(new Error('helper timeout')),10000);});console.log(JSON.stringify({helper_badge:badge.result?.result?.value,helper_error:badge.result?.exceptionDetails?.exception?.description,protocol_error:badge.error}));await writeFile(output+'/helper.json',JSON.stringify(badge.result?.result?.value||{},null,2));hs.close();}
    await call('Page.reload');await pause(5000);const reloaded=await snapshot('reload');results.push(reloaded);
    assert.equal(reloaded.requests.filter(x=>x.path.startsWith('/tr')&&x.event==='PageView').length,1,'one PageView on fresh document reload');
    for(const path of ['/principal','/']) {
        await navigate(path);const organic=await snapshot(path==='/'?'home':'principal');results.push(organic);
        assert.equal(organic.allowed,false);assert.equal(organic.fbq,'undefined');assert.equal(organic.sdk,false);
        assert(!organic.requests.some(x=>x.host.includes('facebook')),'organic sends no Meta requests');
        assert.deepEqual(organic.csp,[]);
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    const report={verified_at:new Date().toISOString(),results,runtimeErrors,failedAssets,real_sdk:true,browserMessages:browserMessages.filter(x=>/extension|error/i.test(x)).map(x=>x.slice(0,600)),real_pageviews_allowed:true,helper_extension_requested:!process.argv.includes('--no-helper')};
    await writeFile(output+'/real-browser.json',JSON.stringify(report,null,2));
} finally {if(socket)socket.close();chrome.kill('SIGTERM');}
