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
    const fixtures=JSON.parse(await readFile('/evidence/affiliate-browser-fixtures.json','utf8'));
    const checks=[], mutations=[];
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
            if(['/afiliados','/dashboard'].includes(url.pathname)) {
                const page=structuredClone(url.pathname==='/dashboard'?fixtures.dashboard:url.searchParams.has('empty')?fixtures.empty:fixtures.populated);
                page.url=url.pathname+url.search;page.version=version;
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Browser fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}throw new Error(label);};
    await call('Browser.grantPermissions',{origin:'https://jakawi.club',permissions:['clipboardReadWrite','clipboardSanitizedWrite']});
    const expected=fixtures.populated.props.campaign.personal_url;
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width<500});
        await call('Page.navigate',{url:'https://jakawi.club/dashboard'});
        await until(`document.querySelector('a[href="/afiliados"]')`,'Dashboard affiliate navigation renders');
        await evaluate(`document.querySelector('a[href="/afiliados"]').click()`);
        await until(`document.querySelector('#affiliate-link')`,'Affiliate panel renders through private navigation');
        assert.equal(await evaluate(`document.querySelector('#affiliate-link').value`),expected);
        assert.equal(await evaluate(`document.querySelector('[data-conversion-count]').textContent`),'2');
        assert.equal(await evaluate(`document.querySelectorAll('tbody tr').length`),2);
        assert(await evaluate(`document.querySelector('tbody').textContent.includes('fixture-order-one') && document.querySelector('tbody').textContent.includes('Confirmado')`));
        assert(await evaluate(`!document.body.textContent.includes('private-order-b')`));
        assert(await evaluate(`document.querySelector('tbody time').textContent.includes('9:00')`),'Bolivia time uses UTC minus four');
        assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
        assert(await evaluate(`Array.from(document.querySelectorAll('button,a.btn')).every(el=>el.getBoundingClientRect().right<=innerWidth && el.getBoundingClientRect().left>=0)`));
        await evaluate(`document.querySelector('button').click()`);
        await until(`document.querySelector('[role="status"]')?.textContent.includes('Enlace copiado')`,'Copy confirmation renders');
        assert.equal(await evaluate(`navigator.clipboard.readText()`),expected);
        const share=await evaluate(`(()=>{const a=document.querySelector('a[href^="https://wa.me/"]');let clicked=false;a.addEventListener('click',e=>{e.preventDefault();clicked=true},{once:true});a.click();return {href:a.href,target:a.target,rel:a.rel,clicked}})()`);
        assert(share.clicked && share.target==='_blank' && share.rel.includes('noopener'));
        const shareUrl=new URL(share.href);
        assert.equal(shareUrl.origin,'https://wa.me');
        assert.equal(shareUrl.searchParams.get('text'),fixtures.populated.props.campaign.name+'\n'+expected);
        await evaluate(`window.originalCopy=navigator.clipboard.writeText;navigator.clipboard.writeText=async()=>{throw Error('denied')};document.querySelector('button').click()`);
        await until(`document.querySelector('[role="status"]')?.textContent.includes('Selecciona y copia')`,'Denied clipboard has selectable fallback');
        assert(await evaluate(`document.activeElement.id==='affiliate-link'&&document.activeElement.selectionStart===0&&document.activeElement.selectionEnd===document.activeElement.value.length`));
        await evaluate(`navigator.clipboard.writeText=window.originalCopy`);
        await writeFile(`${output}/affiliate-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        await call('Page.navigate',{url:'https://jakawi.club/afiliados?empty=1'});
        await until(`document.querySelector('main')?.textContent.includes('Todavía no tienes conversiones confirmadas')`,'Empty state renders');
        assert.equal(await evaluate(`document.querySelector('[data-conversion-count]').textContent`),'0');
        assert(await evaluate(`!document.querySelector('table')&&document.documentElement.scrollWidth<=innerWidth+1`));
        checks.push({width,private_navigation:true,real_personal_link:true,clipboard_copy:true,clipboard_fallback:true,whatsapp_share:true,verified_conversions:true,bolivia_time:true,no_overflow:true,empty_state:true});
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(mutations,[]);
    const result={live_assets:live,data:'isolated HTTP kernel fixtures',checks,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_mutations:true,no_live_session:true};
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify(result,null,2)+'\n');
    console.log(JSON.stringify(result));
}finally{socket?.close();chrome.kill('SIGTERM');}
