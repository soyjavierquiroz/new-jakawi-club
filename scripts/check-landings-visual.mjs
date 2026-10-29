// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const providerOnly = process.argv.includes('--real-provider-only');
await mkdir(output, { recursive: true });
const reference = await readFile('/workspace/resources/css/jakawi-theme.css', 'utf8');
const token = name => reference.match(new RegExp(`--${name}:\\s*(#[a-fA-F0-9]+);`))[1].toLowerCase();
const rgb = name => `rgb(${token(name).slice(1).match(/../g).map(channel => parseInt(channel, 16)).join(', ')})`;
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
    const providerFailures = [];
    let checkingRealVideo = false;
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
    on('Network.loadingFailed', event => { console.log('Browser request failed', event.type, event.errorText); if (checkingRealVideo) providerFailures.push({ type: event.type, error: event.errorText }); });
    on('Network.responseReceived', ({ response, type }) => {
        if (response.status >= 400 && ['Script', 'Stylesheet', 'Font', 'Image'].includes(type)) {
            if (response.url.startsWith('https://jakawi.club/')) failedAssets.push({ url: response.url, status: response.status });
            else if (checkingRealVideo) providerFailures.push({ host: new URL(response.url).hostname, status: response.status, type });
        }
    });
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Page.bringToFront');
    await call('Emulation.setFocusEmulationEnabled', { enabled: true });
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

    let html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const oldManifest = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
    const nextManifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
    for (const [name, entry] of Object.entries(oldManifest)) {
        const next = nextManifest[name];
        if (!next) continue;
        html = html.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
        for (let i = 0; i < (entry.css || []).length; i++) html = html.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
    }
    const fixtures = JSON.parse(await readFile('/evidence/browser-fixtures.json', 'utf8'));
    const operations = [], metrics = [], checks = [];
    let editor = structuredClone(fixtures.editor);
    const base = `/admin/landings/${editor.props.landing.id}`;
    const fulfill = (event, code, type, body = '', extra = []) => call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: code, responseHeaders: [{name:'Content-Type',value:type}, ...extra], body: Buffer.from(body).toString('base64') });
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            if (url.pathname === '/medicion') { metrics.push(JSON.parse(event.request.postData)); await fulfill(event,204,'application/json'); return; }
            if (url.pathname === '/registro') throw new Error('No registration request allowed');
            let page;
            if (event.request.method !== 'GET') {
                operations.push({ method: event.request.method, path: url.pathname });
                const data = JSON.parse(event.request.postData);
                if (url.pathname.endsWith('/import')) {
                    await fulfill(event, 303, 'text/plain', '', [{name:'Location',value:base+'/editar'}]); return;
                }
                if (event.request.method === 'PUT') {
                    Object.assign(editor.props.landing, data);
                    editor.props.landing.revision++;
                    editor.props.status = 'Borrador guardado.';
                } else if (url.pathname.endsWith('/publicar')) {
                    editor.props.landing.published_configuration = structuredClone(editor.props.landing.configuration);
                    editor.props.landing.status = 'published';
                    editor.props.landing.revision++;
                    editor.props.status = 'Versión guardada publicada.';
                }
                page = structuredClone(editor);
                page.url = base+'/editar';
            } else {
                page = structuredClone(url.pathname.endsWith('/crear') ? fixtures.create : url.pathname.endsWith('/preview') ? fixtures.preview : url.pathname === '/admin/landings' ? fixtures.index : editor);
                if (url.pathname.endsWith('/preview')) {
                    page.props.landing.content = structuredClone(editor.props.landing.configuration);
                    page.props.landing.revision = editor.props.landing.revision;
                    page.props.launch.video_url = page.props.landing.content.video.youtube_url;
                }
                page.url = url.pathname;
            }
            const inertia = event.request.headers['X-Inertia'] || event.request.headers['x-inertia'];
            if (inertia) await fulfill(event, 200, 'application/json', JSON.stringify(page), [{name:'X-Inertia',value:'true'}]);
            else await fulfill(event, 200, 'text/html; charset=utf-8', html.replace(pattern, (_, open, _body, close) => open+JSON.stringify(page).replaceAll('<','\\u003c')+close));
        } catch (error) { runtimeErrors.push(error.message); await call('Fetch.failRequest', {requestId:event.requestId,errorReason:'Failed'}); }
    });
    await call('Fetch.enable', {patterns:[{urlPattern:'https://jakawi.club/admin/landings*'},{urlPattern:'https://jakawi.club/medicion'},{urlPattern:'https://jakawi.club/registro'}]});
    const until = async (expression, message) => {
        for (let i=0;i<100;i++) { try { if (await evaluate(expression)) return; } catch {} await pause(75); }
        throw new Error(message);
    };
    const navigate = async path => { await call('Page.navigate',{url:'https://jakawi.club'+path}); await until('document.readyState === "complete" && !!document.querySelector("h1")','Page renders'); await pause(250); };
    const screenshot = async name => { const result = await call('Page.captureScreenshot',{format:'png'}); await writeFile(`${output}/${name}.png`,Buffer.from(result.data,'base64')); };
    const click = text => evaluate(`(()=>{const b=[...document.querySelectorAll('button')].find(b=>b.textContent===${JSON.stringify(text)}); if(!b||b.disabled)throw Error('Button unavailable');b.click();})()`);
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate(base+'/editar');
    await until('!!document.querySelector("#landing-title")','Editor renders');
    assert.equal(await evaluate('document.querySelectorAll("iframe").length'),0,'Preview is not automatic');
    await screenshot('editor-desktop');
    await evaluate(`(()=>{const input=document.getElementById('content.hero.title');Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set.call(input,'Titular desde el editor');input.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    await until(`[...document.querySelectorAll('button')].find(b=>b.textContent==='Publicar versión guardada').disabled`,'Unsaved changes disable publication');
    assert.equal(operations.length,0);
    await click('Guardar borrador');
    await until(`[...document.querySelectorAll('button')].find(b=>b.textContent==='Publicar versión guardada')?.disabled===false`,'Save completes');
    assert.equal(operations.length,1); assert.equal(operations[0].method,'PUT');
    assert.equal(editor.props.landing.configuration.hero.title,'Titular desde el editor');
    assert.notEqual(editor.props.landing.published_configuration.hero.title,'Titular desde el editor','Save does not publish');
    await click('Vista previa real');
    for (const width of [390,430,1440]) {
        await evaluate(`[...document.querySelectorAll('button')].find(b=>b.textContent===${JSON.stringify((width===1440?'Desktop · 1440':width)+' px')}).click()`);
        await until(`document.querySelector('iframe')?.contentWindow?.innerWidth===${width} && !!document.querySelector('iframe')?.contentDocument?.querySelector('h1')`,'Preview frame renders at requested width');
        const result = await evaluate(`(()=>{const f=document.querySelector('iframe'),d=f.contentDocument,w=f.contentWindow,p=d.querySelector('.vsl-frame').getBoundingClientRect();return {width:w.innerWidth,overflow:d.documentElement.scrollWidth>w.innerWidth,title:d.querySelector('h1').textContent,closed:d.querySelector('#registro fieldset').disabled,ratio:p.width/p.height,preview:d.body.textContent.includes('Vista previa del borrador')};})()`);
        assert.equal(result.width,width); assert(!result.overflow); assert(result.title.includes('Titular desde el editor')); assert(result.closed && result.preview); assert(Math.abs(result.ratio-16/9)<.02);
        checks.push({name:'real-renderer-preview',...result});
        await evaluate(`document.querySelector('iframe').scrollIntoView({block:'start'})`);
        await screenshot(`preview-${width}`);
    }
    assert.equal(metrics.length,0,'Preview sends no analytics');
    assert.equal(operations.length,1,'Preview never saves or publishes');
    await click('Publicar versión guardada');
    await until('document.body.textContent.includes("Versión guardada publicada.")','Publish completes');
    assert.equal(operations.length,2); assert(operations[1].path.endsWith('/publicar'));
    assert.equal(editor.props.landing.published_configuration.hero.title,'Titular desde el editor');
    checks.push({name:'save-preview-publish-independent',passed:true});
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
    await navigate(base+'/editar');
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Editor fits mobile');
    await screenshot('editor-390');
    await navigate('/admin/landings/crear');
    await until('!!document.getElementById("landing-slug")','Create renders');
    assert(!await evaluate('!!document.querySelector("iframe")'));
    assert(!await evaluate('document.body.textContent.includes("Publicar versión guardada")'));
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'));
    await screenshot('create-390');
    await navigate('/admin/landings');
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'));
    checks.push({name:'editor-create-index-mobile',width:390,passed:true});
    assert.deepEqual(runtimeErrors,[]); assert.deepEqual(failedAssets,[]);
    await writeFile(`${output}/editor-visual.json`,JSON.stringify({checks,operations,preview_metrics:metrics.length,live_test_users:0,backend_fixtures:'real isolated HTTP kernel responses; browser mutations intercepted',runtime_errors:runtimeErrors,failed_assets:failedAssets},null,2)+'\n');
    console.log(`${checks.length} editor/preview groups passed; 390, 430, 1440 px; no LIVE writes.`);
} finally { socket?.close(); chrome.kill('SIGTERM'); }
