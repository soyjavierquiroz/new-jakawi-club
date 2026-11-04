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
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

    const template = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const fixtures = JSON.parse(await readFile('/evidence/cta-fixtures.json','utf8'));
    const manifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8'));
    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    let shell=template;
    for(const [name,entry] of Object.entries(oldManifest)) {
        const next=manifest[name]; if(!next)continue;
        shell=shell.replaceAll('/build/'+entry.file,'/build/'+next.file);
        for(let i=0;i<(entry.css||[]).length;i++) if(next.css?.[i]) shell=shell.replaceAll('/build/'+entry.css[i],'/build/'+next.css[i]);
    }
    let page=fixtures.pages['/']; let heldSubmission; const submissions=[];
    const checks=[], writes=[];
    const fulfill=(e,type,bytes,status=200)=>call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(bytes).toString('base64')});
    on('Fetch.requestPaused',async e=>{
        try {
            const url=new URL(e.request.url);
            if(url.pathname==='/medicion'){await fulfill(e,'application/json','',204);return;}
            if(e.request.method==='POST' && ['/registro','/ads/meta/webinar/registro'].includes(url.pathname)) {submissions.push({path:url.pathname,data:JSON.parse(e.request.postData)});heldSubmission=e;return;}
            if(e.request.method!=='GET') { writes.push(url.pathname); await fulfill(e,'application/json','',204); return; }
            if(url.pathname.startsWith('/build/')) { const f='/workspace/public/build-next/'+url.pathname.slice(7); await fulfill(e,f.endsWith('.css')?'text/css':f.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(f));return; }
            if(e.resourceType==='Document' && url.hostname==='jakawi.club') {await fulfill(e,'text/html',shell.replace(pattern,(_,a,b,c)=>a+JSON.stringify(url.pathname.includes('/preview') ? fixtures.preview : page).replaceAll('<','\\u003c')+c));return;}
            if(url.hostname==='jakawi.club' && (url.pathname.startsWith('/marketing/') || ['/jakawi-logo.svg','/jakawi-mark.svg'].includes(url.pathname))) {await fulfill(e,url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',await readFile('/workspace/public'+url.pathname));return;}
            await fulfill(e,e.resourceType==='Script'?'application/javascript':'application/json',e.resourceType==='Script'?'':'{}');
        } catch(error) {if(!closing)runtimeErrors.push(String(error));await fulfill(e,'text/plain','fixture error',500).catch(()=>{});}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const ready=async selector=>{for(let i=0;i<100;i++){if(await evaluate('!!document.querySelector('+JSON.stringify(selector)+')'))return;await pause(100);}throw Error('Missing '+selector);};
    const navigate=async(fixture,path,width)=>{page=structuredClone(fixture);await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await call('Page.navigate',{url:'https://jakawi.club'+path});await ready(page.component==='Welcome'?'[data-opportunity-card]':'#landing-form');await evaluate('document.fonts.ready');await pause(200);};
    const screenshot=async(name,selector)=>{
        const clip=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1}})()`);
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});await writeFile(output+'/'+name+'.png',Buffer.from(shot.data,'base64'));
    };
    const until=async(expression)=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await pause(50);}throw Error(expression);};
    const fill=async(id,value)=>{await evaluate(`(()=>{const e=document.getElementById(${JSON.stringify(id)});Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));})()`);await pause(40);};
    await call('Network.setCookie',{name:'XSRF-TOKEN',value:'fixture',domain:'jakawi.club',path:'/',secure:true});
    const nav='QUIERO RESERVAR MI LUGAR GRATIS',submit='COMPLETAR MI REGISTRO';
    for(const width of [1440,390]) {
        for(const path of ['/','/principal','/ads/meta/webinar']) {
            await navigate(fixtures.pages[path],path,width);
            const state=await evaluate(`(()=>{const links=[...document.querySelectorAll('[data-marketing-cta]')],b=document.querySelector('#registro button[type=submit]');return{labels:links.map(e=>e.textContent.trim()),hrefs:links.map(e=>e.getAttribute('href')),className:b.className,ink:getComputedStyle(b).getPropertyValue('--ink'),submit:b.textContent.trim(),navBg:getComputedStyle(links[0]).backgroundColor,navFg:getComputedStyle(links[0]).color,bg:getComputedStyle(b).backgroundColor,fg:getComputedStyle(b).color,overflow:document.documentElement.scrollWidth>innerWidth+1};})()`);
            assert(state.labels.length>=2 && state.labels.every(l=>l===nav));assert(state.hrefs.every(h=>h==='#registro'));assert.equal(state.submit,submit);
            assert.equal(state.navBg,'rgb(255, 90, 79)');assert.equal(state.navFg,'rgb(255, 255, 255)');assert.equal(state.bg,'rgb(17, 17, 17)');assert.equal(state.fg,'rgb(255, 255, 255)');assert(!state.overflow);
            const before=submissions.length;
            await evaluate(`document.querySelector('[data-marketing-cta=hero]').click()`);
            await until("location.hash==='#registro' && document.activeElement.id==='registro'");
            assert.equal(submissions.length,before);
            assert(await evaluate("document.querySelector('[data-account-microcopy]').textContent.includes('cuenta gratuita JAKAWI')"));
            checks.push(`navigation only, labels, coral/ink, account explanation, no overflow ${path} ${width}`);
        }
        await screenshot('submit-'+width,'#registro');
        await evaluate("document.querySelector('[data-webinar-section=hero]').scrollIntoView({behavior:'instant'})");
        await screenshot('navigation-'+width,'[data-webinar-section=hero]');
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'},{name:'prefers-reduced-motion',value:'reduce'}]});
        assert.equal(await evaluate("getComputedStyle(document.querySelector('#registro button[type=submit]')).backgroundColor"),'rgb(17, 17, 17)');
        checks.push(`dark preference preserves marketing contrast ${width}`);
        await fill('name','Persona de prueba');await fill('email','fixture@example.invalid');await fill('phone','70000000');
        const before=submissions.length;
        await evaluate("(()=>{const f=document.querySelector('#registro form');f.requestSubmit();f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));})()");
        await until("document.querySelector('#registro button[type=submit]').disabled");
        await evaluate("document.querySelector('#registro form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))");await pause(200);
        assert.equal(submissions.length,before+1);assert.equal(submissions.at(-1).path,'/ads/meta/webinar/registro');assert.equal(submissions.at(-1).data.phone,'+59170000000');
        assert.equal(await evaluate("document.querySelector('#registro button[type=submit]').textContent.trim()"),'Un momento…');
        const errorPage=structuredClone(fixtures.pages['/ads/meta/webinar']);errorPage.props.errors={email:'Error de prueba controlado'};
        await call('Fetch.fulfillRequest',{requestId:heldSubmission.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'X-Inertia',value:'true'}],body:Buffer.from(JSON.stringify(errorPage)).toString('base64')});
        await until("!!document.querySelector('#email-error') && !document.querySelector('#registro button[type=submit]').disabled");
        assert.equal(await evaluate("document.querySelector('#registro button[type=submit]').textContent.trim()"),submit);
        assert.equal(await evaluate("document.querySelector('#email').value"),'fixture@example.invalid');checks.push(`one submit only, existing endpoint/E164, loading/error preserved ${width}`);
        for(const template of ['WEBINAR','LEAD_VSL','WAITLIST']) {
            const old=structuredClone(fixtures.pages['/']);old.props.landing.content=structuredClone(fixtures.legacy);old.props.landing.content.presentation.template=template;
            await navigate(old,'/principal',width);
            assert.equal(await evaluate("document.querySelector('#registro button[type=submit]').textContent.trim()"),'MI CTA ANTERIOR');
            assert(await evaluate("[...document.querySelectorAll('[data-marketing-cta]')].every(e=>e.textContent.trim()==='MI CTA ANTERIOR')"));
            assert.equal(await evaluate("document.querySelectorAll('.registration-submit-separated').length"),0);
        }
        checks.push(`legacy labels/styles in all three templates ${width}`);
        const custom=structuredClone(fixtures.pages['/']);custom.props.landing.content.registration.navigation_label='NAVEGAR AL REGISTRO';custom.props.landing.content.registration.submit_label='CONFIRMAR DATOS';
        await navigate(custom,'/principal',width);
        assert.equal(await evaluate("document.querySelector('[data-marketing-cta=hero]').textContent.trim()"),'NAVEGAR AL REGISTRO');assert.equal(await evaluate("document.querySelector('#registro button[type=submit]').textContent.trim()"),'CONFIRMAR DATOS');checks.push(`future funnel labels configurable ${width}`);
        await navigate(fixtures.editor,'/admin/landings/1/editar',width);
        assert.equal(await evaluate("document.getElementById('content.registration.navigation_label').value"),nav);checks.push(`editor exposes separate fields ${width}`);
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);
    const {createHash}=await import('node:crypto');
    await writeFile(output+'/browser.json',JSON.stringify({checks,no_live_mutations:true,submissions_intercepted:submissions.length,manifest_sha256:createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex')},null,2)+'\n');
    closing=true;await call('Fetch.disable');
    console.log(JSON.stringify({passed:checks.length,no_live_mutations:true}));
} finally {closing=true;socket?.close();chrome.kill('SIGTERM');}
