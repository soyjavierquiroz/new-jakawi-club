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


    const fixtures=JSON.parse(await readFile('/evidence/checkout-browser-fixtures.json','utf8'));
    const checks=[], unexpectedWrites=[];
    const fulfill=(event,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(!live && url.pathname.startsWith('/build/assets/')) {
                try { const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));
                    return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.woff2')?'font/woff2':'application/octet-stream',file);
                } catch {}
            }
            if(event.request.method!=='GET') {unexpectedWrites.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(url.pathname==='/activar/qr')return await fulfill(event,200,'image/svg+xml','<svg xmlns="http://www.w3.org/2000/svg" width="288" height="288"><rect width="288" height="288" fill="white"/><text x="30" y="145" fill="black">QR · fixture visual</text></svg>');
            if(url.pathname==='/membresia'||url.pathname==='/activar') {
                const key=url.searchParams.get('fixture') || (url.pathname==='/membresia'?'sales':'empty');
                if(live && !url.searchParams.has('fixture'))return await call('Fetch.continueRequest',{requestId:event.requestId});
                const page=structuredClone(fixtures[key]);page.url=url.pathname+url.search;page.version=version;page.props.status=null;
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Browser fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}console.error(JSON.stringify({runtimeErrors,failedAssets,dom:await evaluate('document.body.innerHTML.slice(0,1500)')}));throw new Error(label);};
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:960,deviceScaleFactor:1,mobile:width===390});
        for(const key of ['sales','empty','signed','qr','review','active','existing','signed_review','signed_active']) {
            const path=key==='sales'?'/membresia':'/activar';
            const query=live&&['sales','empty'].includes(key)?'':'?fixture='+key;
            await call('Page.navigate',{url:'about:blank'}); await pause(100);
            await call('Page.navigate',{url:'https://jakawi.club'+path+query});
            await until(`document.querySelector('${key==='sales'?'[data-membership-sales]':'[data-membership-checkout]'}')`,'page '+key);
            await pause(180);
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),'no horizontal overflow '+key+' '+width);
            if(!await evaluate('!!document.querySelector("main")')) console.error(JSON.stringify({key,runtimeErrors,dom:await evaluate('document.body.innerText.slice(0,600)')}));
            const content=await evaluate('document.querySelector("main").innerText');
            assert(!/SSO|OIDC|HMAC|global_subject_id|\bCOM\b/.test(content),'buyer sees no internal terms');
            if(key==='sales')assert(await evaluate('[...document.querySelectorAll("main a")].filter(a=>a.textContent.includes("Activar mi membresía")&&a.getAttribute("href").startsWith("/activar")).length>=3'),'repeated direct purchase CTAs');
            if(key==='empty')assert(await evaluate('!!document.querySelector("#buyer-name") && !!document.querySelector("#buyer-email") && !document.querySelector("input[type=password]")'),'only purchase details without password');
            if(['signed','signed_review','signed_active'].includes(key))assert(await evaluate('!document.querySelector("#buyer-name") && !document.querySelector("#buyer-email")'),'no repeated authenticated identity fields');
            if(key==='review'||key==='signed_review')assert(content.includes('¡Recibimos tu comprobante!')&&content.includes('Estamos verificando tu pago.'),'exact review message');
            if(key==='active'||key==='signed_active')assert(content.includes('Tu membresía está activada')&&!content.includes('Ya pagué'),'active state blocks second charge');
            if(key==='qr') {
                await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent.includes("Ya pagué")).click()');
                await until('document.querySelector("#receipt")','receipt picker');
                assert(await evaluate('!document.querySelector("#receipt").multiple && document.querySelector("#receipt").accept === "image/jpeg,image/png,application/pdf"'),'single private receipt control');
            }
            const screenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            await writeFile(`${output}/${key}-${width}-${live?'live':'preview'}.png`,Buffer.from(screenshot.data,'base64'));
            checks.push(`${key} ${width}`);
        }
    }
    assert.deepEqual(runtimeErrors,[],'no browser runtime errors');assert.deepEqual(failedAssets,[],'all assets load');assert.deepEqual(unexpectedWrites,[],'no live purchase/receipt writes');
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify({checks,no_live_mutations:true,runtimeErrors,failedAssets},null,2));
    console.log(JSON.stringify({checks:checks.length,no_live_mutations:true}));
} finally { if(socket)socket.close();chrome.kill('SIGTERM'); }
