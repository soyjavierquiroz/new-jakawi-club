// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { createHash } from 'node:crypto';
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
let finish = () => {};
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
    finish = () => { events.clear(); for (const item of pending.values()) { clearTimeout(item.timer); item.resolve({}); } pending.clear(); };
    const runtimeErrors = [];
    const failedAssets = [];
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, method === 'Page.navigate' ? 45000 : 20000);
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
    on('Runtime.consoleAPICalled', event => { if(event.type==='error') console.error('Browser console', event.args.map(arg=>arg.description||arg.value)); });
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

    let html = await (await fetch('https://jakawi.club/login')).text();
    if (!live) {
        const old = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
        const next = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
        for (const [name, entry] of Object.entries(old)) {
            if (!next[name]) continue;
            html = html.replaceAll(`/build/${entry.file}`, `/build/${next[name].file}`);
            for (let i=0;i<Math.min((entry.css||[]).length,(next[name].css||[]).length);i++) html=html.replaceAll(`/build/${entry.css[i]}`,`/build/${next[name].css[i]}`);
        }
    }
    const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const version=JSON.parse(html.match(pattern)[2]).version;


    const fixtures=JSON.parse(await readFile('/evidence/fixtures.json','utf8'));

    const checks=[],writes=[];let current='dashboard';
    const fulfill=(event,code,type,body='')=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(event.request.method!=='GET'){writes.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(!live && url.pathname.startsWith('/build/assets/')) {
                try {const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':'application/octet-stream',file);}catch{}
            }
            if(url.pathname==='/admin'||url.pathname.startsWith('/admin/')) {
                const page=structuredClone(fixtures[current]);page.url=url.pathname;page.version=version;page.props.status=null;
                if(event.request.headers['X-Inertia'])return await fulfill(event,200,'application/json',JSON.stringify(page));
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            return await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Fixture failed');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*',requestStage:'Request'}]});
    const until=async(expression,name)=>{for(let i=0;i<120;i++){if(await evaluate(`Boolean(${expression})`))return;await pause(100);}throw new Error('Timed out: '+name);};
    const selectedWidth=Number(process.argv.find(arg=>arg.startsWith('--width='))?.split('=')[1]);
    assert([390,1440].includes(selectedWidth),'choose bounded viewport');
    for(const width of [selectedWidth]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
        for(current of ['dashboard','partners-afiliados','comisiones','reglas-comisiones','ofertas-comerciales','conversiones-com','landings','soporte','restricted','support-expired']) {
            console.log('Checking '+current+' '+width);
            const path=current==='support-expired'?'/admin/soporte':['dashboard','restricted'].includes(current)?'/admin':'/admin/'+current;
            await call('Page.navigate',{url:'about:blank'});
            await call('Page.navigate',{url:'https://jakawi.club'+path});
            await until('document.querySelector("#admin-content h1")','admin shell');
            await evaluate('document.fonts.ready.then(() => true)');
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth+1'),'responsive '+current);
            assert(await evaluate('!document.querySelector(".site-header") && !document.querySelector("footer")'),'no public navigation');
            assert(await evaluate(`!!document.querySelector('nav[aria-label="Administración CLUB"]')`),'common navigation');
            if(width===1440) assert(await evaluate('getComputedStyle(document.querySelector("button[aria-controls=admin-menu]")).display==="none"'),'desktop sidebar has no mobile toggle');
            if(width===390){
                assert(await evaluate('getComputedStyle(document.querySelector("#admin-menu")).display==="none"'),'collapsed mobile menu');
                await evaluate('document.querySelector("button[aria-controls=admin-menu]").click()');
                await until('document.querySelector("button[aria-controls=admin-menu]").getAttribute("aria-expanded")==="true"','mobile opens');
            }
            assert.equal(await evaluate(`document.querySelectorAll('#admin-menu [aria-current="page"]').length`),1,'one active section');
            if(current==='restricted') assert.equal(await evaluate('document.querySelectorAll("#admin-menu a").length'),3,'restricted menu: overview support account');
            if(current==='dashboard') {
                assert(await evaluate('document.body.innerText.includes("Bs 15")'),'ledger money rendered');
                assert(await evaluate('document.body.innerText.includes("saldo efectivo pendiente de pago no está disponible")'),'payment limitation explicit');
                if(width===390) {
                    const capture=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(`${output}/admin-menu-390-${live?'live':'preview'}.png`,Buffer.from(capture.data,'base64'));
                    await evaluate('document.querySelector("button[aria-controls=admin-menu]").click()');
                }
                const capture=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(`${output}/admin-${width}-${live?'live':'preview'}.png`,Buffer.from(capture.data,'base64'));
            }
            checks.push(`${current} ${width}: common shell, active section, responsive navigation`);
        }
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);
    await writeFile(`${output}/browser-${live?'live':'preview'}-${selectedWidth}.json`,JSON.stringify({checks,manifest_sha256:createHash('sha256').update(await readFile(live?'/workspace/public/build/manifest.json':'/workspace/public/build-next/manifest.json')).digest('hex'),no_live_mutations:true,protected_pages:'real HTTP responses from isolated PostgreSQL fixtures; frontend '+(live?'LIVE':'preview'),runtimeErrors,failedAssets},null,2));
    console.log(JSON.stringify({checks:checks.length,no_live_mutations:true}));
} finally {finish();if(socket)socket.close();chrome.kill('SIGTERM');}
