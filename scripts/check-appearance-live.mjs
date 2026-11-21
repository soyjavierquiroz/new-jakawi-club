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
        assert(!response.exceptionDetails, response.exceptionDetails?.exception?.description || response.exceptionDetails?.text || 'Evaluation succeeds');
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

    const blockedWrites=[], checks=[];
    on('Fetch.requestPaused',async e=>{
        if(e.request.method==='GET'){await call('Fetch.continueRequest',{requestId:e.requestId});return;}
        blockedWrites.push({host:new URL(e.request.url).hostname,path:new URL(e.request.url).pathname});
        await call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:204,body:''});
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.__qaDocument=crypto.randomUUID();new MutationObserver((_,o)=>{if(document.body){window.__atBody=document.documentElement.dataset.accountTheme;o.disconnect();}}).observe(document,{subtree:true,childList:true});`});
    const until=async expr=>{for(let i=0;i<120;i++){if(await evaluate(expr))return;await pause(50);}throw Error('LIVE timeout '+expr);};
    const navigate=async(path,width)=>{const old=await evaluate('window.__qaDocument');await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await call('Page.navigate',{url:'https://jakawi.club'+path});await until('window.__qaDocument!=='+JSON.stringify(old??null)+' && !!document.querySelector("select[aria-label=Apariencia]")');await evaluate('document.fonts.ready');};
    const set=async value=>{await evaluate(`(()=>{const s=document.querySelector('select[aria-label=Apariencia]');s.value=${JSON.stringify(value)};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);await until('document.documentElement.dataset.appearance==='+JSON.stringify(value));};
    for(const width of [390,1440]){
        for(const mode of ['light','dark']){
            for(const [path,label] of [['/login','login'],['/','landing']]){
                await navigate(path,width);await set(mode);await pause(200);
                assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
                const state=await evaluate('(()=>{const r=getComputedStyle(document.documentElement);return{preference:document.documentElement.dataset.appearance,resolved:document.documentElement.dataset.accountTheme,bg:r.getPropertyValue("--background").trim(),fg:r.getPropertyValue("--foreground").trim()}})()');
                assert.equal(state.resolved,mode);
                const shot=await call('Page.captureScreenshot',{format:'png'});await writeFile(output+'/live-'+label+'-'+width+'-'+mode+'.png',Buffer.from(shot.data,'base64'));
                const old=await evaluate('window.__qaDocument');await call('Page.reload');await until('window.__qaDocument!=='+JSON.stringify(old)+' && !!document.querySelector("select[aria-label=Apariencia]")');assert.equal(await evaluate('window.__atBody'),mode);
                checks.push({path,width,mode,...state,reload_prepaint:true,overflow:false});
            }
        }
    }
    await set('system');
    for(const value of ['dark','light']){await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value},{name:'prefers-reduced-motion',value:'reduce'}]});await until('document.documentElement.dataset.accountTheme==='+JSON.stringify(value));assert.equal(await evaluate('document.documentElement.dataset.appearance'),'system');}
    checks.push('LIVE system reactive with canonical selection retained');
    for(const path of ['/recuperar-password','/privacidad']){await navigate(path,390);assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));checks.push({path,width:390,overflow:false});}
    assert(!blockedWrites.some(w=>w.host==='jakawi.club' && /registro|settings|logout|compr|aviso|comprobante|internal/.test(w.path)),'No account or commercial mutations attempted');
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    const {createHash}=await import('node:crypto');
    const expected=createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex');const actual=createHash('sha256').update(await readFile('/live-build/manifest.json')).digest('hex');assert.equal(actual,expected);
    await writeFile(output+'/live-browser.json',JSON.stringify({checks,blockedWrites,no_live_account_or_commercial_writes:true,emails_sent:0,manifest_sha256:actual,runtime_errors:runtimeErrors,failed_assets:failedAssets},null,2)+'\n');
    console.log(JSON.stringify({live_browser_pass:true,checks:checks.length,emails_sent:0}));
}finally{closing=true;socket?.close();chrome.kill('SIGTERM');}
