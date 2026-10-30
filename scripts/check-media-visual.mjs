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
    const fixture=JSON.parse(await readFile('/evidence/media-browser-fixtures.json','utf8'));
    const catalog=JSON.parse(await readFile('/evidence/catalog-browser-fixtures.json','utf8'));
    let page,heldUpload;
    const checks=[],writes=[],uploads=[],unexpected=[];
    const fulfill=(e,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async e=>{
        try{
            const url=new URL(e.request.url);
            if(url.hostname==='img.jakawi.com')return await fulfill(e,200,'image/png',await readFile('/workspace/public/marketing/club-card.png'));
            if(url.pathname.startsWith('/build/')&&!live){
                const file=await readFile('/workspace/public/build-next/'+url.pathname.slice(7));
                return await fulfill(e,200,url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.woff2')?'font/woff2':'text/javascript',file);
            }
            if(url.pathname==='/marketing/poster-fixture.png')return await fulfill(e,200,'image/png',await readFile('/workspace/public/marketing/club-card.png'));
            if(url.pathname==='/media/uploads'){
                uploads.push(e.request);heldUpload=e;return;
            }
            if(url.pathname.startsWith('/admin/landings/')||url.pathname.startsWith('/afiliados')){
                if(e.request.method!=='GET'){
                    const data=JSON.parse(e.request.postData);writes.push(data);
                    assert(!JSON.stringify(data.configuration).includes('blob:'),'Blob never persisted');
                    page.props.landing.revision++;
                    if(data.commercial_image_remove){page.props.landing.configuration.commercial.image=null;page.props.commercial_image_url=null;page.props.commercial_card_url=null;}
                    else page.props.landing.configuration.commercial.image=fixture.editor.props.landing.configuration.commercial.image;
                    return await fulfill(e,303,'text/plain','',[{name:'Location',value:page.url}]);
                }
                const response=structuredClone(url.pathname.includes('/preview')?fixture.welcome:url.pathname.startsWith('/afiliados')?catalog.links:page);
                response.url=url.pathname+url.search;response.version=version;response.props.errors={};
                if(response.component==='Welcome'){response.props.launch.video_url=null;response.props.landing.content.presentation.show_video=false;}
                if(response.component==='Affiliates'){
                    const club=response.props.offers.data.find(o=>o.source==='club');
                    response.props.offers.data=[{...club,...fixture.card}];response.props.offers.total=1;
                }
                const inertia=Object.entries(e.request.headers).some(([k,v])=>k.toLowerCase()==='x-inertia'&&v==='true');
                if(inertia)return await fulfill(e,200,'application/json',JSON.stringify(response),[{name:'X-Inertia',value:'true'}]);
                return await fulfill(e,200,'text/html',html.replace(pattern,(_,a,b,c)=>a+JSON.stringify(response).replaceAll('<','\\u003c')+c));
            }
            if(e.request.method!=='GET'){unexpected.push(url.pathname);return await fulfill(e,204,'text/plain');}
            await call('Fetch.continueRequest',{requestId:e.requestId});
        }catch(error){runtimeErrors.push(error.message);await fulfill(e,500,'text/plain','Fixture error');}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://img.jakawi.com/*'}]});
    await call('Network.setCookie',{name:'XSRF-TOKEN',value:'fixture-csrf-token',domain:'jakawi.club',path:'/',secure:true});
    on('Page.javascriptDialogOpening',()=>call('Page.handleJavaScriptDialog',{accept:true}));
    const until=async(expr,label)=>{for(let i=0;i<100;i++){if(await evaluate(`!!(${expr})`))return;await pause(100);}throw new Error(label);};
    const image=(await readFile('/workspace/public/marketing/club-card.png')).toString('base64');
    const choose=async(type='image/png',size=0,drop=false)=>evaluate(`(()=>{const file=new File([${size?`new Uint8Array(${size})`:`Uint8Array.from(atob(${JSON.stringify(image)}),c=>c.charCodeAt(0))`}],'browser-fixture.png',{type:${JSON.stringify(type)}});const d=new DataTransfer();d.items.add(file);${drop?`document.querySelector('input[aria-label="Seleccionar imagen promocional"]').parentElement.dispatchEvent(new DragEvent('drop',{bubbles:true,dataTransfer:d}));`:`const e=document.querySelector('input[aria-label="Seleccionar imagen promocional"]');e.files=d.files;e.dispatchEvent(new Event('change',{bubbles:true}));`}})()`);
    for(const width of [390,1440]){
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
        page=structuredClone(fixture.editor);
        await call('Page.navigate',{url:new URL(page.url,'https://jakawi.club').href});
        await until("document.querySelector('input[aria-label=\"Seleccionar imagen promocional\"]')",'Upload editor ready');
        assert.equal(await evaluate("document.querySelectorAll('#commercial-image').length"),0,'No manual URL input');
        await choose('text/plain');
        await until("document.querySelector('[role=alert]')?.textContent.includes('JPEG')",'Invalid MIME message');
        await choose('image/png',10*1024*1024+1);
        await until("document.querySelector('[role=alert]')?.textContent.includes('10 MiB')",'Size limit message');
        const before=uploads.length;heldUpload=null;
        await choose('image/png',0,true);
        for(let i=0;i<100&&!heldUpload;i++)await pause(100);
        assert(heldUpload,'Drag/drop starts multipart request');
        assert.equal(uploads.length,before+1);
        assert(Object.entries(heldUpload.request.headers).some(([k,v])=>k.toLowerCase()==='content-type'&&v.startsWith('multipart/form-data;')));
        assert(Object.entries(heldUpload.request.headers).some(([k,v])=>k.toLowerCase()==='x-xsrf-token'&&v==='fixture-csrf-token'));
        await until("document.querySelector('progress') && document.querySelector('button[type=submit][form=landing-form]').disabled",'Progress and save lock while uploading');
        assert(await evaluate("[...document.images].some(e=>e.src.startsWith('blob:'))"),'Immediate local preview');
        await fulfill(heldUpload,200,'application/json',JSON.stringify(fixture.upload));heldUpload=null;
        await until("document.body.textContent.includes('Imagen lista.')",'Uploaded temporary image ready');
        assert(await evaluate("document.querySelector('[data-commercial-preview] img').src.startsWith('https://img.jakawi.com/')"),'Draft card uses temporary signed preview');
        await evaluate("document.querySelector('[data-commercial-preview]').scrollIntoView({behavior:'instant',block:'center'})");
        await writeFile(`${output}/upload-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        const saved=writes.length;
        await evaluate("document.querySelector('button[type=submit][form=landing-form]').click()");
        for(let i=0;i<100&&writes.length===saved;i++)await pause(100);
        assert.equal(writes.length,saved+1);assert.equal(writes.at(-1).commercial_image_upload,fixture.upload.token);
        await until("document.querySelector('input[aria-label=\"Seleccionar imagen promocional\"]') && !document.querySelector('button[type=submit][form=landing-form]').disabled",'Saved editor returns');
        heldUpload=null;await choose('image/png');
        for(let i=0;i<100&&!heldUpload;i++)await pause(100);
        assert(heldUpload,'Replacement request ready');
        await fulfill(heldUpload,422,'application/json',JSON.stringify({errors:{image:['La imagen no se pudo guardar. La anterior se conserva.']}}));heldUpload=null;
        await until("document.querySelector('[role=alert]')?.textContent.includes('La anterior se conserva')",'Readable backend failure');
        assert(await evaluate("!document.querySelector('[data-commercial-preview] img').src.startsWith('blob:')"),'Failed replacement restores saved preview');
        await evaluate("[...document.querySelectorAll('button')].find(e=>e.textContent==='QUITAR IMAGEN').click()");
        await until("document.body.textContent.includes('Imagen retirada')",'Remove state');
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        await evaluate("document.querySelector('button[type=submit][form=landing-form]').click()");
        for(let i=0;i<100&&!writes.at(-1)?.commercial_image_remove;i++)await pause(100);
        assert(writes.at(-1).commercial_image_remove,'Remove sent separately from upload token');
        await call('Page.navigate',{url:'https://jakawi.club/afiliados?tab=links'});
        await until("document.querySelector('[data-catalog-offer] > img')?.naturalWidth>0",'Catalog shows published image');
        const src=await evaluate("document.querySelector('[data-catalog-offer] > img').src");
        assert(src.startsWith('https://img.jakawi.com/')&&src.includes('/rs:fill:960:540/'));
        assert(await evaluate("document.querySelector('[data-personal-link]').value.startsWith('https://jakawi.club/r/')"));
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        await writeFile(`${output}/catalog-${live?'live':'preview'}-${width}.png`,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        checks.push({width,drag_drop:true,select_file:true,immediate_preview:true,progress:true,multipart_csrf:true,token_save:true,remove:true,server_failure:true,validation:true,catalog_signed_image:true,affiliate_link:true,no_overflow:true});
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(unexpected,[]);
    const result={live_assets:live,checks,no_live_mutations:true,no_live_accounts:true,runtime_errors:runtimeErrors,failed_assets:failedAssets};
    await writeFile(`${output}/browser-${live?'live':'preview'}.json`,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}finally{socket?.close();chrome.kill('SIGTERM');}
