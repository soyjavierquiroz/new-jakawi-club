// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir, rm } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const live = process.argv.includes('--live');
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
        const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true });
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
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    const checks=[];
    async function navigate(path) {
        await call('Page.navigate',{url:'https://jakawi.club'+path});
        for(let i=0;i<100;i++) {
            await pause(100);
            if(await evaluate(`document.readyState === 'complete' && !!document.querySelector('#app') && !!document.querySelector('h1')`))break;
        }
        await pause(400);
    }
    for(const path of ['/', '/principal']) {
        await navigate(path);
        const head=await evaluate(`({title:document.title, robots:[...document.querySelectorAll('meta[name="robots"]')].map(x=>x.content), canonical:[...document.querySelectorAll('link[rel="canonical"]')].map(x=>x.href), descriptions:document.querySelectorAll('meta[name="description"]').length, og:document.querySelector('meta[property="og:image"]')?.content})`);
        assert.deepEqual(head.robots,['index, follow']);
        assert.deepEqual(head.canonical,['https://jakawi.club/']);
        assert.equal(head.descriptions,1);
        assert(head.og.includes('/f:jpg/') && !head.title.includes('Launch Engine'));
        checks.push({path,...head});
    }
    const click=await evaluate(`(()=>{const a=document.querySelector('a[href="/privacidad"]');if(!a)return false;a.click();return true})()`);
    assert(click,'privacy navigation available');
    for(let i=0;i<60;i++){await pause(100);if(await evaluate(`location.pathname==='/privacidad'`))break;}
    await pause(500);
    const privateHead=await evaluate(`({robots:[...document.querySelectorAll('meta[name="robots"]')].map(x=>x.content), canonical:document.querySelectorAll('link[rel="canonical"]').length, og:document.querySelectorAll('meta[property="og:title"]').length})`);
    assert.deepEqual(privateHead.robots,['noindex, nofollow']);assert.equal(privateHead.canonical,0);assert.equal(privateHead.og,0);
    checks.push({inertia_navigation:'privacy',...privateHead});
    const shell=await (await fetch('https://jakawi.club/login')).text();
    const fixture=JSON.parse(await readFile('/evidence/capture-cards-fixtures.json','utf8')).editor;
    const fixtureHtml=shell.replace(/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/,(_,a,b,c)=>a+JSON.stringify(fixture).replaceAll('<','\\u003c')+c);
    on('Fetch.requestPaused',async e=>{
        if(e.request.url.endsWith('/marketing/poster-fixture.png')) return call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/png'}],body:(await readFile('/workspace/public/marketing/club-card.png')).toString('base64')});
        if(e.request.url.includes('/admin/landings/')) {
            assert.equal(e.request.method,'GET');
            return call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from(fixtureHtml).toString('base64')});
        }
        return call('Fetch.continueRequest',{requestId:e.requestId});
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*jakawi.club/admin/landings/*'},{urlPattern:'*jakawi.club/marketing/poster-fixture.png'}]});
    await navigate('/admin/landings/'+fixture.props.landing.id+'/editar');
    const editor=await evaluate(`({seo:document.body.textContent.includes('SEO Y REDES SOCIALES'),title:!!document.querySelector('#seo-title'),description:!!document.querySelector('#seo-description'),robots:[...document.querySelectorAll('meta[name="robots"]')].map(x=>x.content)})`);
    assert(editor.seo && editor.title && editor.description);assert.deepEqual(editor.robots,['noindex, nofollow']);
    checks.push({editor_fixture:editor});
    assert.equal(runtimeErrors.length,0,JSON.stringify(runtimeErrors));assert.equal(failedAssets.length,0,JSON.stringify(failedAssets));
    const report={passed:true,checks,no_live_mutations:true,real_whatsapp_preview:false,real_meta_preview:false};
    await writeFile(output+'/browser-live.json',JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
} finally {
    socket?.close();chrome.kill('SIGTERM');await pause(150);await rm('/tmp/jakawi-visual',{recursive:true,force:true});
}
