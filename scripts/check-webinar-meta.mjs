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




    const fixtures=JSON.parse(await readFile('/evidence/webinar-fixtures.json','utf8'));
    const checks=[], writes=[], external=[];
    const fulfill=(event,code,type,body='')=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(body).toString('base64')});
    let fixture='landing';
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(url.hostname!=='jakawi.club') {
                external.push(url.hostname);
                if(url.hostname==='connect.facebook.net')return await fulfill(event,200,'text/javascript','window.pixelCalls=window.fbq.queue.slice();window.fbq.callMethod=(...args)=>window.pixelCalls.push(args);');
                return await fulfill(event,204,'text/plain');
            }
            if(event.request.method!=='GET'){writes.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(url.pathname.startsWith('/build/assets/')){
                try {const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':'application/octet-stream',file);}catch{}
            }
            if(url.pathname==='/principal'||url.pathname.startsWith('/ads/meta/webinar')) {
                const key=url.pathname==='/principal'?'organic':url.pathname.endsWith('/gracias')?'thanks':fixture;
                const page=structuredClone(fixtures[key]);page.url=url.pathname;page.version=version;
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            // No other application or provider endpoint is contacted by this fixture test.
            return await fulfill(event,204,'text/plain');
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const until=async(expression,label)=>{for(let i=0;i<80;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}console.error(JSON.stringify({label,runtimeErrors,failedAssets,body:await evaluate('document.body.innerText.slice(0,180)'),location:await evaluate('location.href')}));throw new Error(label);};
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:960,deviceScaleFactor:1,mobile:width===390});
        for(const key of ['organic','no_consent','landing','thanks']) {
            fixture=key;external.length=0;console.log('Browser',width,key);
            await evaluate('try {sessionStorage.clear()}catch{}');
            await call('Page.navigate',{url:'about:blank'});await pause(100);
            const path=key==='organic'?'/principal':key==='thanks'?'/ads/meta/webinar/gracias':'/ads/meta/webinar';
            await call('Page.navigate',{url:'https://jakawi.club'+path});
            await until('document.querySelector("[data-landing-template], .confirmation-page")','page mounts');await pause(400);
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'responsive width');
            if(key==='organic') {
                assert(!external.some(h=>h.includes('facebook')||h.includes('tiktok')),'no provider without permission');
                assert(await evaluate('!window.fbq && !window.ttq'),'no unauthorized SDK');
            } else {
                await until('window.pixelCalls?.length','Meta stub loaded');
                assert(await evaluate('window.fbq === window._fbq'), 'canonical Meta runtime alias');
                assert(!external.some(h=>h.includes('tiktok')),'TikTok absent');
                assert(await evaluate('document.querySelector("meta[name=robots]").content.includes("noindex")'),'noindex');
                assert(await evaluate(`!document.querySelector('[aria-label="Preferencias publicitarias"]')`), 'no webinar preference step');
                assert(await evaluate('window.pixelCalls.filter(x=>x[0]==="init").length===1 && window.pixelCalls.filter(x=>x[0]==="track"&&x[1]==="PageView").length===1 && !window.pixelCalls.some(x=>x[0]==="consent"&&x[1]==="grant")'), 'one init and PageView, no fictional grant');
                if(key==='landing'||key==='no_consent') assert(await evaluate('window.pixelCalls.some(x=>x[0]==="track"&&x[1]==="PageView")&&!window.pixelCalls.some(x=>x[1]==="CompleteRegistration")'),'only PageView on landing');
                if(key==='thanks') {
                    const expected=fixtures.thanks.props.ads.events[0].id;
                    assert(await evaluate(`window.pixelCalls.filter(x=>x[1]==="CompleteRegistration").length===1 && window.pixelCalls.find(x=>x[1]==="CompleteRegistration")[3].eventID===${JSON.stringify(expected)}`),'Pixel shares server ID exactly once');
                    await call('Page.reload');await until('window.pixelCalls?.length','reload loads stub');await pause(200);
                    assert(await evaluate('!window.pixelCalls.some(x=>x[1]==="CompleteRegistration")'),'reload does not repeat conversion');
                }
            }
            checks.push(`${key} ${width}`);
        }
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    assert(writes.every(path=>path==='/medicion'),'all writes are intercepted local measurement');
    await writeFile(`${output}/browser-preview.json`,JSON.stringify({checks,no_live_mutations:true,provider_requests_stubbed:true,runtimeErrors,failedAssets,intercepted_writes:writes.length},null,2));
    console.log(JSON.stringify({checks:checks.length,no_live_mutations:true,provider_requests_stubbed:true}));
} finally {if(socket)socket.close();chrome.kill('SIGTERM');}
