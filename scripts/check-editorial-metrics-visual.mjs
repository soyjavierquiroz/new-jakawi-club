// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const live = false;
await mkdir(output, { recursive: true });
const addresses = await Promise.all(['jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', ['--headless=new', '--hide-scrollbars', '--no-sandbox', '--single-process', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', '--disable-extensions', '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/jakawi-opportunity-visual', '--no-first-run', '--no-default-browser-check'], { stdio: ['ignore', 'ignore', 'pipe'] });
const browserMessages = [];
chrome.stderr.on('data', data => browserMessages.push(data.toString()));
chrome.on('exit', (code, signal) => { if (code !== 0 && signal !== 'SIGTERM') console.error('Browser exit', code, signal); });
let socket, closing = false;
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
        pending.set(id, { resolve, reject, timer, method, expression: params.expression });
        socket.send(JSON.stringify({ id, method, params }));
    });
    socket.onclose = () => { for (const item of pending.values()) { clearTimeout(item.timer); item.reject(new Error('Browser connection closed')); } };
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id) {
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer); pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message + " for " + item.method + " " + item.expression)); else item.resolve(message.result);
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

    const template = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const fixtures = JSON.parse(await readFile('/evidence/editorial-metrics-fixtures.json','utf8'));
    const manifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8'));
    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    let shell=template;
    for(const [name,entry] of Object.entries(oldManifest)) {
        const next=manifest[name]; if(!next)continue;
        shell=shell.replaceAll('/build/'+entry.file,'/build/'+next.file);
        for(let i=0;i<(entry.css||[]).length;i++) if(next.css?.[i]) shell=shell.replaceAll('/build/'+entry.css[i],'/build/'+next.css[i]);
    }
    let page=fixtures.public;
    const checks=[], writes=[];
    const fulfill=(e,type,bytes,status=200)=>call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(bytes).toString('base64')});
    on('Fetch.requestPaused',async e=>{
        try {
            const url=new URL(e.request.url);
            if(url.pathname==='/medicion'){await fulfill(e,'application/json','',204);return;}
            if(e.request.method!=='GET') { writes.push(url.pathname); await fulfill(e,'application/json','',204); return; }
            if(url.pathname.startsWith('/build/')) { const f='/workspace/public/build-next/'+url.pathname.slice(7); await fulfill(e,f.endsWith('.css')?'text/css':f.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(f));return; }
            if(e.resourceType==='Document' && url.hostname==='jakawi.club') {await fulfill(e,'text/html',shell.replace(pattern,(_,a,b,c)=>a+JSON.stringify(url.pathname.includes('/preview') ? fixtures.preview : page).replaceAll('<','\\u003c')+c));return;}
            if(url.hostname==='jakawi.club' && (url.pathname.startsWith('/marketing/') || ['/jakawi-logo.svg','/jakawi-mark.svg'].includes(url.pathname))) {await fulfill(e,url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',await readFile('/workspace/public'+url.pathname));return;}
            await fulfill(e,e.resourceType==='Script'?'application/javascript':'application/json',e.resourceType==='Script'?'':'{}');
        } catch(error) {if(!closing)runtimeErrors.push(String(error));await fulfill(e,'text/plain','fixture error',500).catch(()=>{});}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const ready=async selector=>{for(let i=0;i<100;i++){if(await evaluate('!!document.querySelector('+JSON.stringify(selector)+')'))return;await pause(100);}throw Error('Missing '+selector);};
    const navigate=async(fixture,path,width)=>{page=structuredClone(fixture);await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await call('Page.navigate',{url:'https://jakawi.club'+path});await ready(page.component==='Welcome'?'[data-opportunity-card]':'#landing-form');await evaluate('document.fonts.ready');await pause(200);};
    const screenshot=async(name,selector)=>{
        const clip=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1}})()`);
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});await writeFile(output+'/'+name+'.png',Buffer.from(shot.data,'base64'));
    };
    for(const width of [1440,390]) {
        let signature;
        for(const path of ['/','/principal','/ads/meta/webinar']) {
            await navigate(fixtures.public,path,width);
            const m=await evaluate(`(()=>{const cards=[...document.querySelectorAll('[data-opportunity-card]')];return {overflow:document.documentElement.scrollWidth>innerWidth+1,cards:cards.map(e=>{const r=e.getBoundingClientRect(),n=e.querySelector('.opportunity-metric');return {metric:n.textContent,condition:e.querySelector('.opportunity-condition').textContent,status:e.querySelector('.opportunity-status').textContent,font:parseFloat(getComputedStyle(n).fontSize),height:r.height,x:r.x,y:r.y,scroll:e.scrollHeight>e.clientHeight+1 && ['auto','scroll'].includes(getComputedStyle(e).overflowY)}}),titles:[...document.querySelectorAll('h1,h2')].map(e=>e.innerHTML)};})()`);
            assert(!m.overflow);assert.deepEqual(m.cards.map(c=>c.metric),['15%','30%','50%']);assert(m.cards.every(c=>c.condition.includes('prueba') && c.status.includes('Propuesta') && !c.scroll));
            if(width===1440){assert(m.cards.every(c=>c.font>=72 && c.font<=96));assert.equal(new Set(m.cards.map(c=>c.y)).size,1);assert.equal(new Set(m.cards.map(c=>c.height)).size,1);}else assert.equal(new Set(m.cards.map(c=>c.x)).size,1);
            if(signature)assert.deepEqual(m,signature);else signature=m;
            checks.push(`metric layout conditions no scroll identical channel ${path} ${width}`);
        }
        await screenshot('cards-15-30-50-'+width,'[data-webinar-section=benefits]');
        await screenshot('headline-emphasis-'+width,'[data-webinar-section=opportunity]');
        const old=structuredClone(fixtures.public);old.props.landing.content=fixtures.legacy;
        await navigate(old,'/',width);
        assert.equal(await evaluate('document.querySelectorAll(".opportunity-metric").length'),0);
        assert.equal(await evaluate('document.querySelectorAll("h1 .editorial-coral").length'),0);
        checks.push(`legacy cards and plain headings ${width}`);
        const unit=structuredClone(fixtures.public);unit.props.landing.content.benefits.items[0].metric={value:'1250.75',unit:'Bs'};
        await navigate(unit,'/',width);assert.equal(await evaluate('document.querySelector(".opportunity-metric").textContent'),'1250.75Bs');
        assert(!(await evaluate('document.documentElement.scrollWidth>innerWidth+1')));checks.push(`alternative numeric value Bs ${width}`);
        const mixed=structuredClone(fixtures.public);delete mixed.props.landing.content.benefits.items[1].metric;
        await navigate(mixed,'/',width);assert.equal(await evaluate('document.querySelectorAll(".opportunity-metric").length'),2);checks.push(`mixed legacy and metric card ${width}`);
        await navigate(fixtures.editor,'/admin/landings/99/editar',width);
        await evaluate(`(()=>{const e=document.getElementById('content.hero.title');e.focus();e.setSelectionRange(0,2);e.dispatchEvent(new Event('select',{bubbles:true}));document.dispatchEvent(new Event('selectionchange',{bubbles:true}));})()`);await pause(100);
        // Real selection event and toolbar; no JSON entry or contenteditable.
        await evaluate(`(()=>{const g=document.querySelector('[data-title-editor]');const b=[...g.querySelectorAll('button')].find(e=>e.textContent==='Coral');b.click();})()`);await pause(100);
        assert.equal(await evaluate('document.querySelector("[data-title-editor]").querySelector("[data-title-preview] .editorial-coral").textContent'),'Tu');
        await evaluate(`(()=>{const g=document.querySelector('[data-title-editor]');[...g.querySelectorAll('button')].find(e=>e.textContent==='Quitar todo el énfasis').click();})()`);await pause(100);
        assert.equal(await evaluate('!!document.querySelector("[data-title-editor]").querySelector("[data-title-preview] .editorial-coral")'),false);checks.push(`admin select apply remove emphasis ${width}`);
        await evaluate(`(()=>{for(const d of document.querySelectorAll('details'))d.open=true;})()`);
        await ready('[data-card-preview]');
        assert.equal(await evaluate('document.querySelectorAll("[data-card-preview] .opportunity-metric").length'),3);
        await screenshot('admin-card-'+width,'[data-card-preview]');checks.push(`admin card preview ${width}`);
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);
    const {createHash}=await import('node:crypto');
    await writeFile(output+'/browser.json',JSON.stringify({checks,no_live_mutations:true,runtimeErrors,failedAssets,manifest_sha256:createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex')},null,2)+'\n');
    console.log(JSON.stringify({passed:true,checks:checks.length,no_live_mutations:true}));
} finally { closing=true;socket?.close();chrome.kill('SIGTERM'); }
