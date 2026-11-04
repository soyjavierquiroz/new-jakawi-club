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
let closeBrowser = async () => {};
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
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
    closeBrowser = () => call('Browser.close');
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

    const checks = [], writes = [], outbound = [];
    const fulfill = (event, code, type, body='') => call('Fetch.fulfillRequest', { requestId:event.requestId, responseCode:code, responseHeaders:[{name:'Content-Type',value:type}], body:Buffer.from(body).toString('base64') });
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            if(event.request.method !== 'GET') { writes.push(url.pathname); return await fulfill(event,204,'text/plain'); }
            if(url.hostname !== 'jakawi.club') { outbound.push(url.hostname); return await fulfill(event,204,'text/plain'); }
            if(['/activation','/recovery','/email'].includes(url.pathname)) return await fulfill(event,200,'text/html',await readFile(`${output}/${url.pathname.slice(1)}.html`));
            const path = url.pathname.startsWith('/build/') ? '/workspace/public/build-next/'+url.pathname.slice(7) : '/workspace/public'+url.pathname;
            const body = await readFile(path);
            return await fulfill(event,200,url.pathname.endsWith('.js') ? 'text/javascript' : url.pathname.endsWith('.css') ? 'text/css' : url.pathname.endsWith('.svg') ? 'image/svg+xml' : 'application/octet-stream',body);
        } catch(error) { runtimeErrors.push(error.message); await fulfill(event,404,'text/plain'); }
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const until = async expression => { for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}throw new Error('Content did not load'); };
    for (const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
        for (const kind of ['email','recovery','activation']) {
            await call('Page.navigate',{url:'https://jakawi.club/'+kind});
            await until(kind==='email' ? 'document.querySelector("h1")' : 'document.querySelector("form")');
            await evaluate('document.fonts.ready'); await pause(150);
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${kind} ${width} no overflow`);
            if(kind==='email') {
                assert.equal(await evaluate('getComputedStyle(document.querySelector("a")).color'),'rgb(255, 255, 255)');
                assert.equal(await evaluate('document.querySelector("a").textContent'),'ACTIVAR MI CUENTA');
                assert(await evaluate('document.querySelectorAll("a")[0].href===document.querySelectorAll("a")[1].href'),'fallback URL matches button');
            }
            if(kind==='recovery') {
                assert(await evaluate('document.body.innerText.includes("¿Tu enlace ya no funciona?") && document.body.innerText.includes("Te enviamos uno nuevo.")'));
                assert.equal(await evaluate('document.querySelector("input[type=email]").value'),'pendiente@example.test');
                assert(await evaluate('Array.from(document.querySelectorAll("a")).some(a=>a.textContent==="Ingresar" && a.getAttribute("href")==="/login")'));
                assert(await evaluate('document.querySelector("button[type=submit]").textContent.includes("ENVIAR NUEVO ENLACE")'));
            }
            if(kind==='activation') {
                assert.equal(await evaluate('document.querySelectorAll("form input").length'),2,'only password and confirmation');
                assert(await evaluate('Array.from(document.querySelectorAll("form input")).every(i=>i.autocomplete==="new-password")'),'password manager supported');
                await evaluate('document.querySelector("button[aria-pressed]").click()');
                assert(await evaluate('Array.from(document.querySelectorAll("form input")).every(i=>i.type==="text")'),'show password');
                await evaluate('document.querySelector("button[aria-pressed]").click()');
                assert(await evaluate('Array.from(document.querySelectorAll("form input")).every(i=>i.type==="password")'),'hide password');
            }
            await writeFile(`${output}/${kind}-${width}.png`, Buffer.from((await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
            checks.push(`${kind} ${width}: PASS`);
        }
    }
    assert.deepEqual(runtimeErrors,[]); assert.deepEqual(failedAssets,[]); assert.deepEqual(writes,[]); assert.deepEqual(outbound,[]);
    await writeFile(`${output}/browser.json`,JSON.stringify({checks,manifest_sha256:createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex'),no_live_mutations:true,runtimeErrors,failedAssets,outbound},null,2));
    console.log(JSON.stringify({checks,no_live_mutations:true}));
} finally {
    const stopped=new Promise(resolve=>chrome.once('exit',resolve));
    await closeBrowser().catch(()=>{});
    await Promise.race([stopped,pause(3000)]);
    finish();if(socket)socket.close();if(chrome.exitCode===null)chrome.kill('SIGTERM');
}
