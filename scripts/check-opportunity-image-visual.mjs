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

    const template = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    assert(template.match(pattern), 'real CLUB HTML shell');
    const fixtures = JSON.parse(await readFile('/evidence/opportunity-fixtures.json', 'utf8'));
    const nextManifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8'));
    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    let shell = template;
    for (const [name, entry] of Object.entries(oldManifest)) {
        const next = nextManifest[name]; if (!next) continue;
        shell = shell.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
        for (let i=0;i<Math.min((entry.css||[]).length,(next.css||[]).length);i++) shell=shell.replaceAll(`/build/${entry.css[i]}`,`/build/${next.css[i]}`);
    }
    const imageBytes = await readFile('/evidence/transparent-fixture.webp');
    let page = fixtures.published['/'];
    let rejectUpload = false;
    const checks=[], writes=[];
    const fulfill = (event, status, type, bytes) => call('Fetch.fulfillRequest', {requestId:event.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(bytes).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(url.pathname.startsWith('/build/')) {
                const file='/workspace/public/build-next/'+url.pathname.slice(7);
                await fulfill(event,200,file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(file));return;
            }
            if(url.hostname==='img.jakawi.com') {await fulfill(event,200,'image/webp',imageBytes);return;}
            if(event.request.method!=='GET') {
                writes.push({method:event.request.method,path:url.pathname});
                if(url.pathname==='/media/uploads' && rejectUpload) await fulfill(event,422,'application/json',JSON.stringify({errors:{image:['Error de prueba: archivo rechazado.']}}));
                else if(url.pathname==='/media/uploads') await fulfill(event,200,'application/json',JSON.stringify({token:'browser-only-token',url:fixtures.preview.props.landing.opportunity_image_url,metadata:{format:'image/webp',bytes:imageBytes.length,width:200,height:100}}));
                else await fulfill(event,204,'application/json','');
                return;
            }
            if(event.resourceType==='Document' && url.hostname==='jakawi.club') {
                await fulfill(event,200,'text/html',shell.replace(pattern,(_,a,b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));return;
            }
            if(url.hostname==='jakawi.club' && (url.pathname.startsWith('/marketing/') || ['/jakawi-logo.svg','/jakawi-mark.svg'].includes(url.pathname))) {
                const path='/workspace/public'+url.pathname;
                try {await fulfill(event,200,path.endsWith('.svg')?'image/svg+xml':'image/png',await readFile(path));}
                catch {await fulfill(event,200,'image/webp',imageBytes);}return;
            }
            await fulfill(event,200,event.resourceType==='Script'?'application/javascript':'application/json',event.resourceType==='Script'?'':'{}');
        } catch(error) { if (!closing) { runtimeErrors.push(String(error)); await fulfill(event,500,'text/plain','fixture failure').catch(failure => { if (!closing) runtimeErrors.push(String(failure)); }); } }
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const ready = async selector => {
        for(let i=0;i<100;i++) {if(await evaluate(`!!document.querySelector(${JSON.stringify(selector)})`))return;await pause(100);}
        throw new Error('Not ready: '+selector);
    };
    const navigate=async (fixture,path,width)=>{
        page=structuredClone(fixture);
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
        await call('Page.navigate',{url:'https://jakawi.club'+path});
        await ready(page.component==='Welcome'?'[data-webinar-section=opportunity]':'#landing-form');
        await evaluate('document.fonts.ready');await pause(250);
    };
    const screenshot=async(name,selector)=>{
        const rect=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});const r=e.getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()`);
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:rect});
        await writeFile(`${output}/${name}.png`,Buffer.from(shot.data,'base64'));
    };
    for(const width of [1440,390]) {
        const measurements=[];
        for(const route of ['/','/principal','/ads/meta/webinar']) {
            await navigate(fixtures.published[route],route,width);
            await evaluate("document.querySelector('[data-webinar-section=opportunity]').scrollIntoView()");
            await ready('[data-webinar-section=opportunity] img');
            for(let i=0;i<40;i++){if(await evaluate("document.querySelector('[data-webinar-section=opportunity] img').naturalWidth>0"))break;await pause(100);}
            const m=await evaluate(`(()=>{const s=document.querySelector('[data-webinar-section=opportunity]');const i=s.querySelector('img'),t=s.querySelector('h2');const a=i.getBoundingClientRect(),b=t.getBoundingClientRect();return {src:i.src,alt:i.alt,loaded:i.naturalWidth,fit:getComputedStyle(i).objectFit,ratio:a.width/a.height,textX:b.x,textY:b.y,imageX:a.x,imageY:a.y,bg:getComputedStyle(s).backgroundColor,overflow:document.documentElement.scrollWidth>innerWidth};})()`);
            assert.equal(m.loaded,200);assert.equal(m.fit,'contain');assert(Math.abs(m.ratio-2)<.01);assert(!m.overflow);
            if(width===1440)assert(m.imageX>m.textX+100);else assert(m.imageY>m.textY+100);
            measurements.push(m);
            checks.push(`Opportunity ${route} ${width}: visible, contain, ratio, order, no overflow`);
            if(route==='/')await screenshot(`opportunity-${width}`,'[data-webinar-section=opportunity]');
        }
        assert.deepEqual(measurements[0],measurements[1]);assert.deepEqual(measurements[0],measurements[2]);
        checks.push(`Organic/principal/Meta identical rendered Opportunity ${width}`);
        const old=structuredClone(fixtures.published['/']);delete old.props.landing.content.webinar.opportunity.image;old.props.landing.opportunity_image_url=null;
        await navigate(old,'/',width);
        assert.equal(await evaluate("document.querySelectorAll('[data-webinar-section=opportunity] img').length"),0);
        assert.equal(await evaluate("document.querySelectorAll('.webinar-opportunity-with-image').length"),0);
        await screenshot(`opportunity-no-image-${width}`,'[data-webinar-section=opportunity]');
        checks.push(`Historical text-only Opportunity without placeholder ${width}`);
        await navigate(fixtures.editor,'/admin/landings/1/editar',width);
        await evaluate("for(const d of document.querySelectorAll('details'))d.open=true;document.querySelector('[aria-label=\"Imagen de apoyo\"]').scrollIntoView()");
        assert.equal(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"] img').naturalWidth"),200);
        assert.equal(await evaluate("document.querySelector('#opportunity-image-alt').value"),'Conexiones JAKAWI');
        assert.equal(await evaluate("document.getElementById('content.webinar.opportunity.image.asset_id')"),null);
        const node=await call('DOM.getDocument');
        const input=await call('DOM.querySelector',{nodeId:node.root.nodeId,selector:'input[aria-label="Seleccionar imagen de apoyo"]'});
        await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:['/evidence/transparent-fixture.webp']});
        for(let i=0;i<60;i++){if(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Imagen lista')"))break;await pause(100);}
        assert(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Imagen lista')"));
        assert(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('200 × 100 px')"));
        await screenshot(`opportunity-editor-${width}`,'[aria-label="Imagen de apoyo"]');
        await evaluate(`(()=>{const e=document.getElementById('content.webinar.opportunity.description');Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set.call(e,'Otro texto desde el editor');e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
        assert.equal(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"] img').naturalWidth"),200);
        await evaluate("[...document.querySelector('[aria-label=\"Imagen de apoyo\"]').querySelectorAll('button')].find(b=>b.textContent.includes('QUITAR')).click()");
        assert.equal(await evaluate("document.querySelectorAll('[aria-label=\"Imagen de apoyo\"] img').length"),0);
        checks.push(`Editor ${width}: restore, alt, selection, preview, upload status, metadata, preserve on text edit, detach`);
        rejectUpload=true;
        await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:['/evidence/transparent-fixture.webp']});
        for(let i=0;i<60;i++){if(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Error de prueba')"))break;await pause(100);}
        assert(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Error de prueba')"));
        rejectUpload=false;
        await evaluate(`(()=>{const bytes=Uint8Array.from(atob('${imageBytes.toString('base64')}'),c=>c.charCodeAt(0));const dt=new DataTransfer();dt.items.add(new File([bytes],'drag.webp',{type:'image/webp'}));document.querySelector('[aria-label="Imagen de apoyo"] > div').dispatchEvent(new DragEvent('drop',{bubbles:true,dataTransfer:dt}));})()`);
        for(let i=0;i<60;i++){if(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Imagen lista')"))break;await pause(100);}
        assert(await evaluate("document.querySelector('[aria-label=\"Imagen de apoyo\"]').textContent.includes('Imagen lista')"));
        checks.push(`Editor ${width}: controlled error and drag/drop replacement recover successfully`);

    }
    assert.equal(runtimeErrors.length,0,JSON.stringify(runtimeErrors));assert.equal(failedAssets.length,0,JSON.stringify(failedAssets));
    const {createHash}=await import('node:crypto');
    await writeFile('/evidence/browser.json',JSON.stringify({checks,no_live_mutations:true,writes_intercepted:writes,manifest_sha256:createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex')},null,2)+'\n');
    closing = true;
    await call('Fetch.disable');
    console.log(JSON.stringify({passed:checks.length,no_live_mutations:true}));
} finally {
    closing = true;
    socket?.close();chrome.kill('SIGTERM');
}
