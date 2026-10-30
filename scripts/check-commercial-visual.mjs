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
    const fixtures=JSON.parse(await readFile('/evidence/commercial-browser-fixtures.json','utf8'));
    const checks=[], mutations=[], filteredRequests=[];
    const fulfill=(event,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(!live && url.pathname.startsWith('/build/assets/')) {
                try {
                    const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));
                    return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.woff2')?'font/woff2':'application/octet-stream',file);
                } catch {}
            }
            if(event.request.method!=='GET') {mutations.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(url.pathname==='/admin/conversiones-com') {
                const page=structuredClone(url.searchParams.get('campaign')==='unknown'?fixtures.empty:fixtures.populated);
                page.url=url.pathname+url.search;page.version=version;
                page.props.filters={partner:url.searchParams.get('partner')||'',campaign:url.searchParams.get('campaign')||''};
                if(url.searchParams.get('partner'))filteredRequests.push(url.search);
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Browser fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}throw new Error(label);};
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width<500});
        await call('Page.navigate',{url:'https://jakawi.club/admin/conversiones-com'});
        await until(`document.querySelector('table tbody tr')`,'Commercial table renders');
        assert(await evaluate(`document.querySelector('h1').textContent==='CONVERSIONES COM'`));
        assert(await evaluate(`document.querySelectorAll('th').length===7`));
        assert(await evaluate(`document.querySelector('tbody').textContent.includes('Código CLUB verificado')`));
        assert(await evaluate(`document.querySelector('tbody').textContent.includes('test-order-1')`));
        assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
        await writeFile(`${output}/commercial-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        await evaluate(`document.querySelector('#commercial-partner').value='com-partner-real-id';document.querySelector('#commercial-partner').dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('#commercial-campaign').value='campaign-one';document.querySelector('#commercial-campaign').dispatchEvent(new Event('change',{bubbles:true}));`);
        await evaluate(`document.querySelector('form button[type="submit"]').click()`);
        await until(`location.search.includes('partner=com-partner-real-id')&&location.search.includes('campaign=campaign-one')`,'Filters navigate together');
        await call('Page.navigate',{url:'https://jakawi.club/admin/conversiones-com?campaign=unknown'});
        await until(`document.querySelector('main')?.textContent.includes('No hay conversiones COM')`,'Empty state renders');
        assert(await evaluate(`!document.querySelector('table')&&document.documentElement.scrollWidth<=innerWidth+1`));
        checks.push({width,seven_columns:true,source_reference:true,verified_referral:true,filters_work:true,empty_state:true,no_overflow:true});
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(mutations,[]);assert(filteredRequests.length>=2);
    const result={live_assets:live,admin_data:'isolated HTTP kernel fixtures',checks,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_mutations:true,no_live_session:true};
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify(result,null,2)+'\n');
    console.log(JSON.stringify(result));
}finally{socket?.close();chrome.kill('SIGTERM');}
