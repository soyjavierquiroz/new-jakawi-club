// Focused regression: real form + Inertia 303 redirect + server-generated isolated receipts.
// Every mutation is intercepted; no LIVE registration or email is sent.
import { spawn } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { lookup } from 'node:dns/promises';
import assert from 'node:assert/strict';
const live = !process.argv.includes('--preview');
const reproduce = process.argv.includes('--reproduce');
const receipts = JSON.parse(await readFile('/evidence/campaign-receipt-fixtures.json','utf8'));
const legacy = JSON.parse(await readFile('/evidence/confirmation-fixtures.json','utf8'));
const addresses = await Promise.all(['jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', ['--headless=new', '--hide-scrollbars', '--no-sandbox', '--single-process', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', '--disable-extensions', '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/jakawi-visual', '--no-first-run', '--no-default-browser-check'], { stdio: ['ignore', 'ignore', 'pipe'] });
const browserMessages = [];
chrome.stderr.on('data', data => browserMessages.push(data.toString()));
chrome.on('exit', (code, signal) => { if (code !== 0 && signal !== 'SIGTERM') console.error('Browser exit', code, signal); });
let socket;
let stopping = false;
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
        assert(!response.exceptionDetails, response.exceptionDetails?.exception?.description || response.exceptionDetails?.text || 'Evaluation succeeds');
        return response.result.value;
    };
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

    let html = await (await fetch('https://jakawi.club/principal')).text();
    const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const original=JSON.parse(html.match(pattern)[2]);
    const manifest=JSON.parse(await readFile(`/workspace/public/${live?'build':'build-next'}/manifest.json`,'utf8'));
    if(!live){
        const before=JSON.parse(await readFile('/workspace/public/build/manifest.json','utf8'));
        for(const [key,entry] of Object.entries(before)){
            const next=manifest[key];if(!next)continue;
            html=html.replaceAll('/build/'+entry.file,'/build/'+next.file);
            (entry.css||[]).forEach((css,i)=>{if(next.css?.[i])html=html.replaceAll('/build/'+css,'/build/'+next.css[i]);});
        }
    }
    const checks=[];let destination='/principal/gracias',receipt=receipts['default-custom'];
    let validation=false,submissions=0,redirects=0,blocked=0;
    const fulfill=(event,status,type,body='',headers=[])=>call('Fetch.fulfillRequest',{
        requestId:event.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try{
            const url=new URL(event.request.url);
            if(url.pathname==='/marketing/poster-fixture.png'){
                await fulfill(event,200,'image/png',await readFile('/checks/fixtures/poster-fixture.png'));return;
            }
            if(url.host==='www.youtube.com'){await fulfill(event,200,'application/javascript','');return;}
            if(event.request.method!=='GET'){
                if(url.pathname==='/registro'){
                    submissions++;
                    const payload=JSON.parse(event.request.postData);
                    assert.equal(payload.referral_code,'BROWSERTEST1A');
                    assert.equal(payload.phone,'+59170000000');
                    assert.equal(payload.landing_id,original.props.landing.id);
                    if(validation){
                        const page=structuredClone(original);page.url='/principal';page.props.launch.registration_open=true;
                        page.props.referral_code='BROWSERTEST1A';
                        page.props.errors={email:'Revisa tu correo.'};
                        await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                    }else{
                        redirects++;await fulfill(event,303,'text/plain','',[{name:'Location',value:destination}]);
                    }
                }else{blocked++;await fulfill(event,204,'application/json');}
                return;
            }
            if(url.pathname==='/principal'){
                const page=structuredClone(original);page.url='/principal';page.props.launch.registration_open=true;
                page.props.referral_code='BROWSERTEST1A';page.props.errors={};
                await fulfill(event,200,'text/html',html.replace(pattern,(_,a,b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));return;
            }
            if(url.pathname===destination){
                const page=structuredClone(receipt);page.url=destination;page.version=original.version;page.props.errors={};
                if(event.request.headers['X-Inertia']||event.request.headers['x-inertia'])
                    await fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]);
                else await fulfill(event,200,'text/html',html.replace(pattern,(_,a,b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
                return;
            }
            if(url.pathname.startsWith('/build/')&&!live){
                const path='/workspace/public/build-next/'+url.pathname.slice(7);
                await fulfill(event,200,path.endsWith('.css')?'text/css':path.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(path));return;
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(e){runtimeErrors.push(e.message);await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'}).catch(()=>{});}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://www.youtube.com/iframe_api'}]});
    const until=async(expression,label)=>{
        for(let i=0;i<100;i++){try{if(await evaluate(expression))return;}catch{}await pause(75);}
        console.error(label,{submissions,redirects,runtimeErrors,failedAssets},await evaluate(`({path:location.pathname,confirmation:!!document.querySelector('.confirmation-page'),disabled:document.querySelector('#registro fieldset')?.disabled,valid:document.querySelector('#registro form')?.checkValidity(),focus:document.activeElement.id,phone:document.querySelector('input[name=phone]')?.value})`));
        throw Error(label);
    };
    const fill=async(selector,text)=>{await evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);await call('Input.insertText',{text});};
    for(const width of [390,1440]){
        await call('Emulation.setDeviceMetricsOverride',{width,height:width===390?844:1000,deviceScaleFactor:1,mobile:width===390});
        for(const path of ['/principal/gracias','/principal/confirmado','/bienvenida']){
            console.error('Checking',width,path);
            destination=path;receipt=path==='/bienvenida'?legacy.whatsapp:receipts['default-custom'];
            await call('Page.navigate',{url:'https://jakawi.club/principal'});
            await until(`document.readyState==='complete'&&!!document.querySelector('#registro input:not(:disabled)')`,'Open fixture landing');
            await evaluate('document.fonts.ready');
            // Reuse actual landing and put its existing form after 5000px of content.
            await evaluate(`document.querySelector('#registro').before(Object.assign(document.createElement('div'),{style:'height:5000px',id:'long-landing-spacer'}))`);
            await fill('#name','Persona de prueba');await fill('#phone','70000000');await fill('#email','scroll@example.invalid');
            await evaluate(`document.querySelector('#registro').scrollIntoView({behavior:'instant',block:'start'})`);await pause(150);
            const before=await evaluate('scrollY');assert(before>3000,'Form at end of long landing');
            if(path==='/principal/gracias'&&!reproduce){
                validation=true;
                await evaluate(`document.querySelector('#registro form').requestSubmit()`);
                await until(`document.querySelector('#email')?.getAttribute('aria-invalid')==='true'&&document.activeElement.id==='email'`,'Validation focus retained');
                assert(await evaluate('scrollY>3000'),'Validation keeps form position');
                validation=false;
            }
            await evaluate(`document.querySelector('#registro form').requestSubmit()`);
            await until(`location.pathname===${JSON.stringify(path)}&&!!document.querySelector('.confirmation-page')`,'Successful redirect');
            const arrivalScroll = await evaluate('scrollY');
            if (!reproduce) assert.equal(arrivalScroll, 0, 'Confirmation starts at zero without smooth animation');
            await evaluate('document.fonts.ready');await pause(500);
            const result=await evaluate(`({y:scrollY,x:scrollX,title:document.querySelector('h1').textContent,video:!!document.querySelector('.confirmation-video'),whatsapp:!!document.querySelector('.confirmation-whatsapp-cta'),email:!!document.querySelector('.confirmation-email'),restoration:history.scrollRestoration,autofocus:!!document.querySelector('[autofocus]')})`);
            if(reproduce)assert(result.y>0,'Reproduces inherited scroll');else assert.equal(result.y,0,'Successful confirmation starts at zero');
            assert(result.video&&result.whatsapp&&result.email,'Confirmation media, WhatsApp and email remain');
            checks.push({width,path,starting_scroll:before,arrival_scroll:arrivalScroll,...result});
            const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            await writeFile(`/evidence/${reproduce?'before':live?'live':'preview'}-${width}-${path.split('/').filter(Boolean).join('-')}.png`,Buffer.from(shot.data,'base64'));
        }
        if(!reproduce){
            // Normal Link navigation still uses Inertia's normal scroll reset.
            await call('Page.navigate',{url:'https://jakawi.club/principal'});
            await until(`document.readyState==='complete'&&!!document.querySelector('.checkbox-label a')`,'Landing for ordinary navigation');
            await evaluate(`document.querySelector('#registro').scrollIntoView({behavior:'instant'});document.querySelector('.checkbox-label a').click()`);
            await until(`location.pathname==='/privacidad'&&!!document.querySelector('.privacy-copy')`,'Ordinary privacy navigation');
            await until('scrollY===0','Normal smooth scroll finishes');
            assert.equal(await evaluate('scrollY'),0,'Normal navigation unchanged');
        }
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    const report={mode:reproduce?'reproduce':live?'live':'preview',checks,submissions,redirects,other_mutations_intercepted:blocked,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_live_registration:true,server_generated_receipts:true,registration_open:original.props.launch.registration_open,validation_scroll_and_focus_preserved:!reproduce,normal_navigation_verified:!reproduce,reduced_motion:false};
    await writeFile(`/evidence/${reproduce?'reproduction':live?'browser-live':'browser-preview'}.json`,JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify(report,null,2));
}finally{stopping=true;socket?.close();chrome.kill('SIGTERM');}
