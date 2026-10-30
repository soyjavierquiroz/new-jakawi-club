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

    let html=await (await fetch('https://jakawi.club/login')).text();
    const old=JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    const next=live?old:JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8'));
    for(const [name,entry] of Object.entries(old)){
        if(!next[name])continue;
        html=html.replaceAll(`/build/${entry.file}`,`/build/${next[name].file}`);
        for(let i=0;i<Math.min((entry.css||[]).length,(next[name].css||[]).length);i++)html=html.replaceAll(`/build/${entry.css[i]}`,`/build/${next[name].css[i]}`);
    }
    const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const version=JSON.parse(html.match(pattern)[2]).version;
    const fixtures=JSON.parse(await readFile('/evidence/catalog-browser-fixtures.json','utf8'));
    const capture=JSON.parse(await readFile('/evidence/capture-cards-fixtures.json','utf8'));
    const checks=[],mutations=[];
    let page;
    const fulfill=(e,code,type,body='')=>call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async e=>{
        try{
            const url=new URL(e.request.url);
            if(e.request.method!=='GET'){mutations.push(url.pathname);return await fulfill(e,204,'text/plain');}
            if(url.pathname.startsWith('/build/')&&!live){
                const file=await readFile('/workspace/public/build-next/'+url.pathname.slice(7));
                return await fulfill(e,200,url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.woff2')?'font/woff2':'text/javascript',file);
            }
            if(url.pathname==='/marketing/poster-fixture.png')return await fulfill(e,200,'image/png',await readFile('/workspace/public/marketing/club-card.png'));
            if(url.pathname==='/marketing/broken-fixture.png')return await fulfill(e,200,'image/png','invalid image fixture');
            if(url.pathname.startsWith('/afiliados')||url.pathname.startsWith('/admin/landings/')){
                const result=structuredClone(url.pathname.includes('/preview')?capture.preview:page);
                if(result.component==='Welcome'){result.props.launch.video_url=null;result.props.landing.content.presentation.show_video=false;}
                result.url=url.pathname+url.search;result.version=version;
                return await fulfill(e,200,'text/html',html.replace(pattern,(_,a,b,c)=>a+JSON.stringify(result).replaceAll('<','\\u003c')+c));
            }
            await call('Fetch.continueRequest',{requestId:e.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(e,500,'text/plain','Fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'}]});
    const until=async(expr,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expr})`))return;await pause(100);}throw new Error(label);};
    on('Page.javascriptDialogOpening',()=>call('Page.handleJavaScriptDialog',{accept:true}));
    const input=async(selector,value)=>evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});Object.getOwnPropertyDescriptor(e.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    await call('Browser.grantPermissions',{origin:'https://jakawi.club',permissions:['clipboardReadWrite','clipboardSanitizedWrite']});
    for(const width of [390,1440]){
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
        page=structuredClone(fixtures.links);page.props.tab='links';
        const clubLink=fixtures.links.props.offers.data.find(o=>o.source==='club');
        assert(clubLink?.personal_url,'Isolated real short link fixture');
        const com=fixtures.links.props.offers.data.find(o=>o.source==='com');
        page.props.offers.data=['own','poster','legacy'].map((key,i)=>({...clubLink,...capture[key],key:`capture-${i}`,name:i===2?'Tu ciudad tiene mucho más para vivir':capture[key].name}));
        page.props.offers.data.push({...clubLink,...capture.own,key:'capture-broken',image_url:'/marketing/broken-fixture.png'});
        if(com)page.props.offers.data.push(com);
        page.props.offers.total=page.props.offers.data.length;
        await call('Page.navigate',{url:'https://jakawi.club/afiliados?tab=links'});
        await until("document.querySelectorAll('[data-catalog-offer]').length===5",'Capture and COM cards render');
        await evaluate("document.querySelector('[data-catalog-offer=\"capture-broken\"]').scrollIntoView({block:'center'})");
        await until("document.querySelectorAll('[data-club-card-fallback]').length===2",'Legacy and failed image use branded title');
        assert(await evaluate("[...document.querySelectorAll('[data-club-card-fallback]')].every(e=>e.textContent.trim().length>15 && e.querySelector('img').complete)"));
        for(const key of ['capture-0','capture-1']){
            const selector=`[data-catalog-offer="${key}"] > img`;
            await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`);
            await until(`document.querySelector(${JSON.stringify(selector)}).naturalWidth>0`,'Own and poster image load');
            const ratio=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return r.width/r.height;})()`);
            assert(Math.abs(ratio-16/9)<.02,'16:9 card image');
        }
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        if(width===1440){
            const heights=await evaluate("[...document.querySelectorAll('[data-catalog-offer]')].slice(0,3).map(e=>e.getBoundingClientRect().height)");
            assert(Math.max(...heights)-Math.min(...heights)<2,'Consistent row height');
        }
        await evaluate("document.querySelector('[data-catalog-offer] button').click()");
        await until("[...document.querySelectorAll('[role=status]')].some(e=>e.textContent.includes('Enlace copiado'))",'Clipboard success');
        assert.equal(await evaluate('navigator.clipboard.readText()'),clubLink.personal_url);
        const share=await evaluate("document.querySelector('[data-catalog-offer] a[href^=\"https://wa.me/\"]').href");
        assert.equal(new URL(share).searchParams.get('text'),capture.own.name+'\n'+clubLink.personal_url);
        await evaluate('window.scrollTo(0,0)');
        await writeFile(`${output}/cards-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        page=structuredClone(capture.editor);
        await call('Page.navigate',{url:new URL(page.url,'https://jakawi.club').href});
        await until("document.querySelector('[data-commercial-preview]')",'Existing editor shows commercial section');
        await until("document.querySelector('iframe')?.contentDocument?.querySelector('meta[property=\"og:title\"]')",'Preview social metadata rendered');
        assert.equal(await evaluate("document.querySelector('iframe').contentDocument.querySelectorAll('meta[property=\"og:title\"]').length"),1);
        assert.equal(await evaluate("document.querySelector('iframe').contentDocument.querySelector('meta[property=\"og:title\"]').content"),capture.preview.props.landing.social.name);
        const id=page.props.landing.id;
        await input('#commercial-description','Una presentación nueva, todavía sin publicar.');
        await until("document.querySelector('[data-commercial-preview]').textContent.includes('todavía sin publicar')",'Preview reacts to unsaved draft');
        await input('#commercial-image','');
        await until("document.querySelector('[data-commercial-preview] [data-club-card-fallback]')",'Preview without image uses brand');
        await input('#commercial-image','/marketing/poster-fixture.png');
        await evaluate("document.querySelector('[data-commercial-preview]').scrollIntoView({block:'center'})");
        await until("document.querySelector('[data-commercial-preview] > img')?.naturalWidth>0",'Preview accepts existing media');
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        await evaluate("document.querySelector('[data-commercial-preview]').scrollIntoView({block:'center'})");
        await writeFile(`${output}/editor-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        await evaluate("document.getElementById('stage-confirmation').click()");
        await until("!document.querySelector('#commercial-image')",'Confirmation tab has no commercial fields');
        checks.push({width,own_image:true,poster_fallback:true,brand_fallback:true,failed_image_fallback:true,consistent_heights:true,clipboard:true,whatsapp:true,draft_preview:true,confirmation_unchanged:true,no_overflow:true});
    }
    // Deterministic brand artwork for social fallback, rendered from the existing logo.
    if(!live){
        const logo=(await readFile('/workspace/public/jakawi-logo.svg')).toString('base64');
        await call('Emulation.setDeviceMetricsOverride',{width:1200,height:675,deviceScaleFactor:1,mobile:false});
        await call('Page.setDocumentContent',{frameId:(await call('Page.getFrameTree')).frameTree.frame.id,html:`<!doctype html><html><body style="margin:0;background:#111111;color:#fffdf9;font-family:Arial,sans-serif;box-sizing:border-box;width:1200px;height:675px;padding:70px;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden"><img id="brand-logo" src="data:image/svg+xml;base64,${logo}" style="width:300px;background:#fffdf9;border-radius:18px;padding:24px"><div><p style="font-size:24px;letter-spacing:5px;color:#ff5a4f;margin:0 0 25px">JAKAWI CLUB</p><h1 style="font-size:74px;line-height:1.1;margin:0">Vive más.<br>Gasta menos.</h1></div></body></html>`});
        await until("document.querySelector('#brand-logo')?.complete",'Brand artwork logo');
        await writeFile(`${output}/club-card.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(mutations,[]);
    const result={live_assets:live,checks,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_live_mutations:true,no_live_accounts:true};
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}finally{socket?.close();chrome.kill('SIGTERM');}
