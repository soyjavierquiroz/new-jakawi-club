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

    await call('Page.addScriptToEvaluateOnNewDocument',{source:`new MutationObserver((_, observer)=>{if(document.body){window.__themeAtBody=document.documentElement.dataset.accountTheme;observer.disconnect();}}).observe(document,{subtree:true,childList:true});`});
    let html = await (await fetch('https://jakawi.club/login')).text();
    if (!live) {
        const old = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
        const next = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
        for (const [name, entry] of Object.entries(old)) {
            if (!next[name]) continue;
            html = html.replaceAll(`/build/${entry.file}`, `/build/${next[name].file}`);
            for (let i=0;i<Math.min((entry.css||[]).length,(next[name].css||[]).length);i++) html=html.replaceAll(`/build/${entry.css[i]}`,`/build/${next[name].css[i]}`);
        }
        const blade=await readFile('/workspace/resources/views/app.blade.php','utf8');
        html=html.replace('</head>',blade.match(/<script>[\s\S]*?<\/script>/)[0]+'</head>');
    }
    html=html.replace('<html ', '<html data-account-context ');
    const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const version=JSON.parse(html.match(pattern)[2]).version;
    const fixtures=JSON.parse(await readFile('/evidence/results-browser-fixtures.json','utf8'));
    const accounts=JSON.parse(await readFile('/evidence/account-fixtures.json','utf8'));
    const account=structuredClone(accounts.pioneer);
    account.props.member.name='Cuenta de prueba';
    account.props.account_navigation=fixtures.explore.props.account_navigation;
    // Browser-only catalog image, served from the existing CLUB brand asset.
    // Fixture records retain backend counts, codes and URLs from the isolated kernel runner.
    const logo=(await readFile('/workspace/public/jakawi-logo.svg','utf8')).replace(/<\?xml[^>]*>/, '');
    const image=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 540"><rect width="960" height="540" fill="#fff8f2"/><svg x="120" y="125" width="720" height="288">${logo}</svg></svg>`;
    for(const fixture of Object.values(fixtures)) {
        if (fixture.props.offers) for(const offer of fixture.props.offers.data) offer.image_url='https://jakawi.club/__ux-fixture-image.svg';
    }
    const checks=[], writes=[], outbound=[];
    const fulfill=(event,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(event.request.method!=='GET'){writes.push(url.pathname);return await fulfill(event,204,'text/plain');}
            if(url.hostname!=='jakawi.club') {outbound.push(url.hostname);return await fulfill(event,204,'text/plain');}
            if(url.pathname==='/__ux-fixture-image.svg')return await fulfill(event,200,'image/svg+xml',image);
            if(url.pathname.startsWith('/r/'))throw new Error('No real referral requests allowed');
            if(!live && url.pathname.startsWith('/build/assets/')) {
                try {const file=await readFile('/workspace/public/build-next/assets/'+url.pathname.split('/').at(-1));return await fulfill(event,200,url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':'application/octet-stream',file);}catch{}
            }
            if(url.pathname==='/mi-cuenta'||url.pathname==='/afiliados') {
                const page=structuredClone(url.pathname==='/mi-cuenta'?account:fixtures[url.searchParams.get('q')?'filter':url.searchParams.get('tab')||'explore']);
                if(url.searchParams.get('q')==='sin-resultados') page.props.offers={...page.props.offers,data:[],total:0};
                page.url=url.pathname+url.search;page.version=version;page.props.status=null;
                const inertia=Object.entries(event.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(event,200,'text/html',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
            }
            return await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(event,500,'text/plain','Fixture failed');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expression})`))return;await pause(100);}throw new Error(label);};
    const navigate=async(path)=>{await call('Page.navigate',{url:'https://jakawi.club'+path});await until('document.querySelector("select[aria-label=\\"Tema de cuenta\\"]")','account loads');await evaluate('document.fonts.ready');};
    const select=async(mode)=>{await evaluate(`(()=>{const s=document.querySelector('select[aria-label="Tema de cuenta"]');s.value=${JSON.stringify(mode)};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);};
    const mode=()=>evaluate('document.documentElement.dataset.accountTheme');
    const screenshot=async(name)=>{await pause(150);await writeFile(`${output}/${name}-${live?'live':'preview'}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));};
    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:960,deviceScaleFactor:1,mobile:width===390});
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'light'}]});
        await navigate('/afiliados?tab=links');
        await call('Browser.grantPermissions',{origin:'https://jakawi.club',permissions:['clipboardReadWrite','clipboardSanitizedWrite']});
        for(const theme of ['light','dark']) {
            await select(theme);assert.equal(await mode(),theme,'immediate theme');
            assert.equal(await evaluate('getComputedStyle(document.documentElement).backgroundColor'),theme==='dark'?'rgb(17, 17, 17)':'rgb(255, 248, 242)');
            assert.equal(await evaluate('getComputedStyle(document.querySelector(".feature-card")).backgroundColor'),theme==='dark'?'rgb(28, 28, 28)':'rgb(255, 255, 255)');
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'no overflow');
            assert.equal(await evaluate('document.querySelector("h1").textContent'),'Mis referidos y ganancias');
            const summary=await evaluate('Array.from(document.querySelectorAll(".account-summary article > p:first-of-type")).map(e=>e.textContent)');
            assert.deepEqual(summary,[String(fixtures.links.props.links_count),String(fixtures.links.props.results.leads_captured),String(fixtures.links.props.results.memberships_activated),String(fixtures.links.props.economics.approved)],'backend values unchanged');
            await screenshot(`afiliados-${theme}-${width}`);
            await call('Page.reload');await until('document.querySelector(".account-summary")','reload');assert.equal(await mode(),theme,'reload keeps explicit choice');assert.equal(await evaluate('window.__themeAtBody'),theme,'theme resolved before body appears');
            await evaluate(`document.querySelector('nav[aria-label="Catálogo de afiliados"] a[href="/afiliados?tab=explore"]').click()`);
            await until('document.querySelector("[data-catalog-tab=explore]")','Inertia navigation');assert.equal(await mode(),theme,'Inertia keeps theme');
            await evaluate(`document.querySelector('nav[aria-label="Catálogo de afiliados"] a[href="/afiliados?tab=links"]').click()`);
            await until('document.querySelector("[data-catalog-tab=links]")','links tab');
            checks.push(`${width} ${theme}: real fixture totals, instant change, reload, Inertia, surfaces, width`);
        }
        for(const tab of ['history','results','links']) {
            await evaluate(`document.querySelector('nav[aria-label="Catálogo de afiliados"] a[href="/afiliados?tab=${tab}"]').click()`);
            await until(`document.querySelector('[data-catalog-tab="${tab}"]')`,'preserved tab '+tab);
            assert.equal(await mode(),'dark');assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        }
        const personal=await evaluate('document.querySelector("[data-personal-link]").value');
        await evaluate('Array.from(document.querySelectorAll("button")).find(b=>b.textContent==="Copiar enlace").click()');
        await until('document.body.innerText.includes("¡Enlace copiado!")','copy feedback');
        assert.equal(await evaluate('navigator.clipboard.readText()'),personal,'clipboard exact permanent URL');
        assert.equal(await evaluate('new URL(document.querySelector(".account-whatsapp-secondary").href).searchParams.get("text").split("\\n").at(-1)'),personal,'WhatsApp URL exact attribution');
        await select('system');assert.equal(await mode(),'light');
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});await until('document.documentElement.dataset.accountTheme==="dark"','device change');
        await select('light');await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'light'}]});await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});assert.equal(await mode(),'light','explicit choice wins over device');
        await select('dark');
        if(width===390)await evaluate('document.querySelector("button[aria-controls=account-menu]").click()');
        await evaluate('document.querySelector("#account-menu a[href=\\"/mi-cuenta\\"]").click()');
        await until('document.querySelector("#com-plan-title")','account via menu');assert.equal(await mode(),'dark');
        assert.equal(await evaluate('document.querySelectorAll("header").length'),1,'single header');
        assert.equal(await evaluate('document.querySelectorAll("a[href=\\"https://jakawi.com/perfil\\"]").length')>0,true);
        assert.equal(await evaluate('Array.from(document.querySelectorAll("main a,main button")).some(e=>e.textContent==="Cerrar sesión")'),false,'no duplicate logout');
        if(width===1440)assert(await evaluate('getComputedStyle(document.querySelector(".account-menu-toggle")).display==="none"'),'desktop menu toggle hidden');
        await screenshot(`mi-cuenta-dark-${width}`);
        await select('light');await screenshot(`mi-cuenta-light-${width}`);
        await navigate('/afiliados?q=sin-resultados');
        assert(await evaluate('document.body.innerText.includes("No encontramos oportunidades") && !!Array.from(document.querySelectorAll("a")).find(e=>e.textContent==="Limpiar filtros")'));
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        await evaluate('document.querySelector("#catalog-search").value="Segunda";document.querySelector("form").requestSubmit()');
        await until('location.search.includes("q=Segunda") && document.querySelector("[data-catalog-tab=explore]")','filter submission');
        checks.push(`${width}: exact clipboard and WhatsApp, system changes, explicit override, account navigation, empty state and filter`);
    }
    // A fresh document with cleared session cookies uses the same durable local preference.
    await select('dark');await call('Network.clearBrowserCookies');await navigate('/mi-cuenta');assert.equal(await mode(),'dark');
    assert.equal(await evaluate('localStorage.getItem("jakawi.club.appearance")'),'dark');
    checks.push('Theme survives fresh authenticated document without session cookies; durable localStorage, no session coupling');
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);assert.deepEqual(outbound,[]);
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify({checks,manifest_sha256:createHash('sha256').update(await readFile(live?'/workspace/public/build/manifest.json':'/workspace/public/build-next/manifest.json')).digest('hex'),no_live_mutations:true,protected_pages:'isolated HTTP kernel fixtures; illustrative local brand image',runtimeErrors,failedAssets},null,2));
    console.log(JSON.stringify({checks:checks.length,no_live_mutations:true}));
} finally {
    const stopped=new Promise(resolve=>chrome.once('exit',resolve));
    await closeBrowser().catch(()=>{});
    await Promise.race([stopped,pause(3000)]);
    finish();if(socket)socket.close();if(chrome.exitCode===null)chrome.kill('SIGTERM');
}
await import('./check-theme-reopen.mjs');
