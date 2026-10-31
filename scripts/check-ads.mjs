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
    const checks=[], unexpectedWrites=[], external=[];
    const fulfill=(event,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    let currentFixture='ads_meta';
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(!url.hostname.endsWith('jakawi.club')) {
                external.push(url.hostname);
                if(url.hostname==='connect.facebook.net')return await fulfill(event,200,'text/javascript',`window.pixelCalls=window.fbq.queue.slice();window.fbq.callMethod=(...args)=>window.pixelCalls.push(args);`);
                if(url.hostname==='analytics.tiktok.com')return await fulfill(event,200,'text/javascript',`window.pixelCalls=Array.from(window.ttq);window.ttq.page=()=>window.pixelCalls.push(['page']);window.ttq.track=(...args)=>window.pixelCalls.push(['track',...args]);`);
                return await fulfill(event,204,'text/plain');
            }
            if(event.request.method!=='GET'){unexpectedWrites.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(!live && url.pathname.startsWith('/build/assets/')){
                try {const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':'application/octet-stream',file);}catch{}
            }
            if(url.pathname==='/activar/qr')return await fulfill(event,200,'image/svg+xml','<svg xmlns="http://www.w3.org/2000/svg" width="288" height="288"><text x="10" y="40">QR prueba aislada</text></svg>');
            if(['/membresia','/activar'].includes(url.pathname)||url.pathname.startsWith('/ads/')) {
                const key=url.pathname==='/membresia'?'sales':url.pathname==='/activar'?'empty':url.pathname.endsWith('/activar')?(url.pathname.includes('/meta/')?'ads_checkout':'ads_tiktok_checkout'):currentFixture;
                const page=structuredClone(fixtures[key]);page.url=url.pathname+url.search;page.version=version;page.props.status=null;
                if(['/membresia','/activar'].includes(url.pathname))page.props.ads={allowed:false,provider:null,events:[]};
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const until=async(expression,label)=>{for(let i=0;i<80;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}console.error(JSON.stringify({label,runtimeErrors,calls:await evaluate('window.pixelCalls'),provider:await evaluate('window.adProvider')}));throw new Error(label);};
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:960,deviceScaleFactor:1,mobile:width===390});
        for(const key of ['sales','ads_rejected','ads_meta','ads_tiktok']){
            console.log('Browser',width,key);
            currentFixture=key;const provider=key==='ads_tiktok'?'tiktok':'meta';external.length=0;
            await evaluate('try { sessionStorage.clear(); } catch {}');
            await call('Page.navigate',{url:'about:blank'});await pause(100);
            await call('Page.navigate',{url:'https://jakawi.club'+(key==='sales'?'/membresia':`/ads/${provider}/membresia`)});
            await until('document.querySelector("[data-membership-sales]")','sales mounts');await pause(300);
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth+1'),'responsive width');
            if(['sales','ads_rejected'].includes(key)){
                assert(!external.some(h=>/facebook|tiktok/.test(h)),'no pixels organic or consent rejected');
                assert(await evaluate('!window.fbq && !window.ttq'),'no SDK without authorization');
            }else{
                await until('window.pixelCalls?.length','provider stub loads');
                assert(external.includes(provider==='meta'?'connect.facebook.net':'analytics.tiktok.com'),'correct provider loads');
                assert(!external.some(h=>provider==='meta'?h.includes('tiktok'):h.includes('facebook')),'other platform absent');
                assert(await evaluate('document.querySelector("meta[name=robots]").content.includes("noindex")'),'Ads noindex');
                const script=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(`${output}/${key}-${width}.png`,Buffer.from(script.data,'base64'));
                await evaluate(`document.querySelector('a[href^="/ads/${provider}/activar"]').click()`);
                await until('document.querySelector("[data-membership-checkout]")','Inertia checkout navigation');
                await until('window.pixelCalls?.filter(x=>x[0]==="track" && ["Lead","CompleteRegistration","InitiateCheckout"].includes(x[1])).length===2','browser lead and checkout');
                const calls=await evaluate('window.pixelCalls');const expected=fixtures[provider==='meta'?'ads_checkout':'ads_tiktok_checkout'].props.ads.events;
                for(const e of expected){const c=calls.find(c=>c[1]===e.name);assert(c && c[3][provider==='meta'?'eventID':'event_id']===e.id,'browser uses persisted server event ID');}
                await call('Page.reload'); await pause(300);
                await until('document.querySelector("[data-membership-checkout]") && window.pixelCalls?.length','checkout reload');
                assert(await evaluate('!window.pixelCalls.some(x=>["Lead","CompleteRegistration","InitiateCheckout"].includes(x[1]))'),'reload does not repeat browser conversions');
                await evaluate(`[...document.querySelectorAll('a')].find(a=>a.getAttribute('href')==='/membresia').click()`);
                await until('document.querySelector("[data-membership-sales]") && location.pathname==="/membresia"','return to organic');
                assert(await evaluate('!window.fbq && !window.ttq'),'organic navigation unloads provider runtime');
            }
            checks.push(`${key} ${width}`);
        }
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(unexpectedWrites,[]);
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify({checks,no_live_mutations:true,provider_requests_stubbed:true,runtimeErrors,failedAssets},null,2));
    console.log(JSON.stringify({checks:checks.length,no_live_mutations:true,provider_requests_stubbed:true}));
} finally {if(socket)socket.close();chrome.kill('SIGTERM');}
