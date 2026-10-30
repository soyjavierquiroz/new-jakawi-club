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

    const fixtures=JSON.parse(await readFile('/evidence/results-browser-fixtures.json','utf8'));
    const checks=[], unexpectedWrites=[];
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
            if(event.request.method!=='GET') {unexpectedWrites.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(url.pathname.startsWith('/r/')) {unexpectedWrites.push('Unexpected public link test');return await fulfill(event,204,'text/plain');}
            if(url.pathname==='/afiliados'||url.pathname==='/dashboard') {
                const key=url.pathname==='/dashboard'?'dashboard':url.searchParams.get('q')?'filter':url.searchParams.get('tab')||'explore';
                const page=structuredClone(fixtures[key]);
                page.url=url.pathname+url.search;page.version=version;page.props.status=null;
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Browser fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}throw new Error(label);};
    const clickTab=async tab=>{
        await evaluate(`document.querySelector('nav[aria-label="Catálogo de afiliados"] a[href="/afiliados?tab=${tab}"]').click()`);
        await until(`document.querySelector('[data-catalog-tab="${tab}"]') && new URLSearchParams(location.search).get('tab')==='${tab}'`,'URL and active tab '+tab);
        assert.equal(await evaluate(`location.hash`),'');
    };
    const history=async(direction,tab)=>{
        await evaluate(`window.history.${direction}()`);
        await until(`document.querySelector('[data-catalog-tab="${tab}"]') && new URLSearchParams(location.search).get('tab')==='${tab}'`,'History '+direction+' '+tab);
    };
    const screenshot=async name=>writeFile(`${output}/${name}-${live?'live':'preview'}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.__legacyExploreSeen=false;new MutationObserver(()=>{if(location.hash==='#mis-conversiones'&&!location.search.includes('tab=results')&&document.querySelector('[data-catalog-tab="explore"]'))window.__legacyExploreSeen=true;}).observe(document,{childList:true,subtree:true});`});
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
        await call('Page.navigate',{url:'https://jakawi.club/afiliados#mis-conversiones'});
        await until(`document.querySelector('[data-catalog-tab="results"]') && new URLSearchParams(location.search).get('tab')==='results'`,'Legacy fragment opens canonical results');
        assert.equal(await evaluate(`location.hash`),'#mis-conversiones');
        assert.equal(await evaluate(`window.__legacyExploreSeen`),false);
        await until(`Math.abs(document.querySelector('#mis-conversiones').getBoundingClientRect().top)<innerHeight`,'Conversions anchor is visible');
        assert.equal(await evaluate(`document.querySelector('[data-conversion-count]').textContent`),String(fixtures.results.props.conversions.total));
        assert.equal(await evaluate(`document.querySelector('[data-clicks-total]').textContent`),'40');
        assert.equal(await evaluate(`document.querySelector('[data-clicks-recent]').textContent`),'33');
        assert.equal(await evaluate(`document.querySelector('[data-attributed-registrations]').textContent`),'1');
        assert.equal(await evaluate(`document.querySelector('[data-confirmed-redemptions]').textContent`),String(fixtures.results.props.results.confirmed_redemptions));
        assert.equal(await evaluate(`document.querySelector('[data-approved-currency="BOB"]').textContent`),'10.2500 BOB');
        assert.equal(await evaluate(`document.querySelector('[data-approved-currency="USD"]').textContent`),'7.1250 USD');
        assert(await evaluate(`document.body.textContent.includes('no personas únicas')`));
        assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
        await screenshot('conversions-'+width);
        await evaluate(`window.scrollTo(0,0)`);await screenshot('results-'+width);
        await clickTab('explore');
        const labels=await evaluate(`Array.from(document.querySelectorAll('nav[aria-label="Catálogo de afiliados"] a')).map(el=>el.textContent)`);
        assert.deepEqual(labels,['EXPLORAR','MIS ENLACES','HISTORIAL','MIS RESULTADOS']);
        await clickTab('links');
        assert((await evaluate(`document.querySelector('[data-offer-clicks]').textContent`)).includes('39 clics'));
        await history('back','explore');
        await history('back','results');
        assert.equal(await evaluate(`location.hash`),'#mis-conversiones');
        await history('forward','explore');
        await history('forward','links');
        await clickTab('history');
        assert(await evaluate(`document.body.textContent.includes('Todavía no tienes ofertas finalizadas')`));
        await clickTab('results');
        assert.equal(await evaluate(`document.querySelectorAll('[data-result-offer]').length`),2);
        assert.equal(await evaluate(`Array.from(document.querySelectorAll('[data-opportunity-clicks]')).reduce((sum,el)=>sum+Number(el.textContent),0)`),40);
        // A native hash change on another tab must also open its section.
        await clickTab('links');
        await evaluate(`location.hash='mis-conversiones'`);
        await until(`document.querySelector('[data-catalog-tab="results"]') && location.search.includes('tab=results')`,'Native anchor switches to results');
        await call('Page.navigate',{url:'https://jakawi.club/dashboard'});
        await until(`document.querySelector('a[href="/afiliados?tab=results#mis-conversiones"]')`,'Dashboard uses canonical results destination');
        await evaluate(`document.querySelector('a[href="/afiliados?tab=results#mis-conversiones"]').click()`);
        await until(`document.querySelector('[data-catalog-tab="results"]')`,'Dashboard link opens results directly');
        assert.equal(await evaluate(`location.hash`),'#mis-conversiones');
        await clickTab('explore');
        await evaluate(`(()=>{const el=document.querySelector('#catalog-search');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(el,'Segunda');el.dispatchEvent(new Event('input',{bubbles:true}));el.closest('form').requestSubmit();})()`);
        await until(`document.querySelectorAll('[data-catalog-offer]').length===1 && location.search.includes('q=Segunda')`,'Server search and URL agree');
        await clickTab('results');await history('back','explore');
        assert.equal(await evaluate(`document.querySelector('#catalog-search').value`),'Segunda');
        assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
        checks.push({width,legacy_anchor:true,no_explore_flash:true,anchor_visible:true,canonical_results:true,back_forward:true,native_hash:true,dashboard_link:true,search_restored:true,opportunity_clicks:true,approved_currencies:true,no_overflow:true});
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(unexpectedWrites,[]);
    const result={live_assets:live,data:'isolated real-kernel fixtures',checks,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_live_mutations:true,no_live_test_clicks:true};
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}finally{socket?.close();chrome.kill('SIGTERM');}
