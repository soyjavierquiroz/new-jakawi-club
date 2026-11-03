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
    const adminNav=JSON.parse(await readFile('/evidence/admin-fixtures.json','utf8')).account.props.account_navigation;
    let authorized=false;
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
                if(authorized)page.props.account_navigation=adminNav;
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
    const navigate=async(path)=>{await call('Page.navigate',{url:'https://jakawi.club'+path});await until('document.querySelector(".account-header .account-icon-button[aria-label^=Cambiar]")','header loads');await evaluate('document.fonts.ready');await pause(200);};
    const mode=()=>evaluate('document.documentElement.dataset.accountTheme');
    const toggle=()=>evaluate('document.querySelector(".account-header .account-icon-button[aria-label^=Cambiar]").click()');
    const visibleLinks=()=>evaluate(`Array.from(document.querySelectorAll('.account-header nav a')).filter(a=>a.getBoundingClientRect().height>0).map(a=>a.getAttribute('href'))`);
    const capture=async(name,height)=>{await writeFile(`${output}/${name}-${live?'live':'preview'}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png',clip:{x:0,y:0,width:await evaluate('innerWidth'),height,scale:1}})).data,'base64'));};
    for(const width of [1440,390]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:960,deviceScaleFactor:1,mobile:width===390});
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'light'}]});
        await navigate('/afiliados');
        await evaluate('localStorage.removeItem("jakawi.club.appearance")');await navigate('/afiliados');
        assert.equal(await mode(),'light','automatic default resolves device');
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});await until('document.documentElement.dataset.accountTheme==="dark"','system dark');
        assert(await evaluate('!!document.querySelector("button[aria-label=\\"Cambiar a modo claro\\"]")'),'icon follows automatic changes');
        await toggle();assert.equal(await mode(),'light');
        assert.equal(await evaluate('document.querySelector(".account-header").getBoundingClientRect().height'),width===1440?65:57,'COM bar height plus border');
        assert(!await evaluate('!!document.querySelector(".account-header select")'),'no select');
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'no overflow');
        const expected=fixtures.explore.props.account_navigation.map(a=>a.href);
        if(width===390)await evaluate('document.querySelector(".account-mobile-toggle").click()');
        assert.deepEqual(await visibleLinks(),expected,'all and only server navigation');
        assert.equal(await evaluate('Array.from(document.querySelectorAll(".account-header nav button")).filter(b=>b.getBoundingClientRect().height>0&&b.textContent==="Cerrar sesión").length'),1);
        await capture(`header-light-${width}`,width===390?365:90);
        await toggle();assert.equal(await mode(),'dark');await capture(`header-dark-${width}`,width===390?365:90);
        await call('Page.reload');await until('document.querySelector(".account-header")','refresh');assert.equal(await mode(),'dark');
        assert.equal(await evaluate('localStorage.getItem("jakawi.club.appearance")'),'dark','same existing key');
        if(width===390){await evaluate('document.querySelector(".account-mobile-toggle").click()');await evaluate('document.querySelector("#account-mobile-menu a[href=\\"/mi-cuenta\\"]").click()');}
        else await evaluate('document.querySelector(".account-desktop-nav a[href=\\"/mi-cuenta\\"]").click()');
        await until('document.querySelector("#com-plan-title")','account navigation');assert.equal(await mode(),'dark');
        checks.push(`${width}: COM height, icon, automatic default, exact links, logout, refresh, Inertia and no overflow`);
    }
    authorized=true;await call('Emulation.setDeviceMetricsOverride',{width:1440,height:960,deviceScaleFactor:1,mobile:false});await navigate('/mi-cuenta');
    assert((await visibleLinks()).some(h=>h.startsWith('/admin')),'authorized admin retained');
    // Constrain the available bar without changing its height to exercise actual overflow.
    await evaluate('document.querySelector(".account-header-bar").style.maxWidth="720px"');
    await until('document.querySelector(".account-more")','More appears when links do not fit');
    assert.equal(await evaluate('document.querySelector(".account-header").getBoundingClientRect().height'),65);
    await evaluate('document.querySelector(".account-more button").click()');
    assert.deepEqual((await visibleLinks()).sort(),adminNav.map(a=>a.href).sort(),'overflow retains authorized links');
    await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
    await until('!document.querySelector("#account-more-menu")','Escape closes More');
    checks.push('Authorized admin, measured More overflow, single row and Escape');
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);assert.deepEqual(outbound,[]);
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify({checks,manifest_sha256:createHash('sha256').update(await readFile(live?'/workspace/public/build/manifest.json':'/workspace/public/build-next/manifest.json')).digest('hex'),no_live_mutations:true,protected_pages:'isolated browser fixtures',runtimeErrors,failedAssets},null,2));
    console.log(JSON.stringify({result:'PASS',checks:checks.length}));
} finally {finish();if(socket)socket.close();chrome.kill('SIGTERM');}
