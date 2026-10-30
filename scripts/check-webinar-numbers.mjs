// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const mode = process.argv.includes('--baseline') ? 'baseline' : process.argv.includes('--preview') ? 'preview' : 'live';
await mkdir(output, { recursive: true });
const addresses = await Promise.all(['jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', ['--headless=new', '--hide-scrollbars', '--no-sandbox', '--single-process', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', '--disable-extensions', '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/jakawi-visual', '--no-first-run', '--no-default-browser-check'], { stdio: ['ignore', 'ignore', 'pipe'] });
const browserMessages = [];
chrome.stderr.on('data', data => browserMessages.push(data.toString()));
chrome.on('exit', (code, signal) => { if (code !== 0 && signal !== 'SIGTERM') console.error('Browser exit', code, signal); });
let socket;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
    let target;
    for (let i = 0; i < 50; i++) {
        try { target = await (await fetch('http://127.0.0.1:9222/json/new?about:blank', { method: 'PUT' })).json(); break; }
        catch { await pause(100); }
    }
    assert(target, 'Chromium starts');
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
        const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert(!response.exceptionDetails, response.exceptionDetails?.text || 'Evaluation succeeds');
        return response.result.value;
    };
    on('Runtime.exceptionThrown', event => runtimeErrors.push(event.exceptionDetails.text));
    on('Network.responseReceived', ({ response, type }) => {
        if (response.status >= 400 && ['Script', 'Stylesheet', 'Font', 'Image'].includes(type)) {
            if (response.url.startsWith('https://jakawi.club/')) failedAssets.push({ url: response.url, status: response.status });
        }
    });
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Page.bringToFront');
    await call('Emulation.setFocusEmulationEnabled', { enabled: true });
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    const manifest = mode === 'preview' ? JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8')) : oldManifest;
    const expectedCss = [manifest['resources/css/app.css'].file];
    let blockedMutations = 0;
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            const fulfill = (type,body,status=200) => call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(body).toString('base64')});
            if (url.pathname === '/medicion') { await fulfill('application/json','',204); return; }
            if (event.request.method !== 'GET') { blockedMutations++; await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'BlockedByClient'}); return; }
            if (url.href === 'https://www.youtube.com/iframe_api') { await fulfill('application/javascript',''); return; }
            if (mode === 'preview' && url.pathname.startsWith('/build/')) {
                const file = '/workspace/public'+url.pathname.replace('/build/','/build-next/');
                await fulfill(file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(file)); return;
            }
            if (mode === 'preview' && event.resourceType === 'Document' && url.hostname === 'jakawi.club') {
                let html = await (await fetch(url)).text();
                for (const [name,entry] of Object.entries(oldManifest)) {
                    const next = manifest[name]; if (!next) continue;
                    html=html.replaceAll('/build/'+entry.file,'/build/'+next.file);
                    for(let i=0;i<(entry.css||[]).length;i++) if(next.css?.[i]) html=html.replaceAll('/build/'+entry.css[i],'/build/'+next.css[i]);
                }
                await fulfill('text/html; charset=utf-8',html); return;
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        } catch(error) { runtimeErrors.push(error.message); await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'}).catch(()=>{}); }
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://www.youtube.com/iframe_api'}]});
    const until = async expression => {
        for(let i=0;i<100;i++){ if(await evaluate(expression)) return; await pause(100); }
        throw new Error('Page did not render numeric WEBINAR sections');
    };
    const measurements = [];
    for (const path of ['/', '/principal']) {
        for (const width of [390,1440]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
            await call('Page.navigate',{url:'https://jakawi.club'+path});
            await until(`document.readyState==='complete' && document.querySelectorAll('main[data-landing-template=WEBINAR] [data-webinar-section=early] .benefit-number').length===3 && document.querySelectorAll('[data-webinar-section=steps] .benefit-number').length===3`);
            await evaluate('document.fonts.ready'); await pause(150);
            const data = await evaluate(`(()=>{
                const main=document.querySelector('main[data-landing-template=WEBINAR]');
                const sections=['early','steps'].map(id=>({id,items:[...main.querySelectorAll('[data-webinar-section='+id+'] .benefit-number')].map(e=>{
                    const s=getComputedStyle(e),card=e.closest('li'),c=getComputedStyle(card),r=e.getBoundingClientRect(),b=card.getBoundingClientRect();
                    return {number:e.textContent.trim(),background:s.backgroundColor,color:s.color,geometry:{width:r.width,height:r.height,radius:s.borderRadius,fontSize:s.fontSize,fontWeight:s.fontWeight,borderWidth:s.borderWidth,padding:s.padding,cardWidth:b.width,cardHeight:b.height,cardDisplay:c.display,cardColumns:c.gridTemplateColumns}};
                })}));
                return {sections,allNumeric:[...main.querySelectorAll('.benefit-number')].map(e=>({number:e.textContent.trim(),background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color})),stylesheets:[...document.styleSheets].map(s=>s.href).filter(Boolean),overflow:document.documentElement.scrollWidth>innerWidth+1};
            })()`);
            assert.equal(data.overflow,false);
            assert(data.stylesheets.some(href=>expectedCss.some(file=>href.endsWith('/build/'+file))),'Uses expected deployed/build CSS');
            for (const section of data.sections) {
                assert.deepEqual(section.items.map(item=>item.number),['01','02','03']);
                assert(section.items.every(item=>item.color==='rgb(17, 17, 17)'),'Numbers remain black');
                if(mode!=='baseline') assert(section.items.every(item=>item.background==='rgb(255, 200, 61)'),section.id+' exact yellow');
                await evaluate(`document.querySelector('[data-webinar-section=${section.id}]').scrollIntoView({block:'start',behavior:'instant'})`);
                const clip = await evaluate(`(()=>{const r=document.querySelector('[data-webinar-section=${section.id}]').getBoundingClientRect();return {x:Math.max(0,r.left+scrollX),y:r.top+scrollY,width:r.width,height:r.height,scale:1}})()`);
                const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
                await writeFile(`${output}/${mode}-${path==='/'?'home':'principal'}-${section.id}-${width}.png`,Buffer.from(shot.data,'base64'));
            }
            if(mode!=='baseline') assert(data.allNumeric.every(item=>item.background==='rgb(255, 200, 61)'&&item.color==='rgb(17, 17, 17)'),'All equivalent WEBINAR numeric indicators');
            measurements.push({path,width,...data});
        }
    }
    if(mode!=='baseline') {
        const before=JSON.parse(await readFile(`${output}/browser-baseline.json`,'utf8'));
        assert.deepEqual(measurements.map(m=>m.sections.map(s=>s.items.map(i=>i.geometry))),before.measurements.map(m=>m.sections.map(s=>s.items.map(i=>i.geometry))),'Card and number geometry unchanged');
    }
    assert.equal(blockedMutations,0);assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    const report={mode,live_assets:mode!=='preview',actual_public_pages:true,measurements,runtime_errors:runtimeErrors,failed_assets:failedAssets,live_mutations:0,passed:true};
    await writeFile(`${output}/browser-${mode}.json`,JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify({mode,passed:true,public_pages:2,widths:[390,1440],sections:['early','steps'],numeric_elements_verified:measurements.reduce((n,m)=>n+m.allNumeric.length,0),backgrounds:[...new Set(measurements.flatMap(m=>m.allNumeric.map(i=>i.background)))],foregrounds:[...new Set(measurements.flatMap(m=>m.allNumeric.map(i=>i.color)))]}));
} finally { socket?.close(); chrome.kill('SIGTERM'); }
