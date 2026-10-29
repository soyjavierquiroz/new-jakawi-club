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
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    let html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const oldManifest = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
    const nextManifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
    if (!live) for (const [name, entry] of Object.entries(oldManifest)) {
        const next = nextManifest[name];
        if (!next) continue;
        html = html.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
        for (let i = 0; i < Math.min((entry.css || []).length, (next.css || []).length); i++) html = html.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
    }

    const fixtures = JSON.parse(await readFile('/evidence/funnel-fixtures.json', 'utf8'));
    const operations=[], metrics=[], checks=[], groups=[];
    const record = (name, details={}) => { checks.push({name,...details,passed:true}); console.log('PASS',name,details.width||''); };
    let editor=structuredClone(fixtures.editor);
    const base=`/admin/landings/${editor.props.landing.id}`;
    const defaultGroup='https://chat.whatsapp.com/DefaultGroupFixture1234';
    const effectiveGroup=content=>{
        const group=content.confirmation_whatsapp_group;
        const inherited=group.mode==='inherit'?content.whatsapp_group:group;
        return inherited.mode==='custom'?inherited.url:inherited.mode==='default'?defaultGroup:null;
    };
    const fulfill=(event,code,type,body='',headers=[])=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:code,responseHeaders:[{name:'Content-Type',value:type},...headers],body:Buffer.from(body).toString('base64')});
    const render=(event,page,inertia)=>inertia?fulfill(event,200,'application/json',JSON.stringify(page),[{name:'X-Inertia',value:'true'}]):fulfill(event,200,'text/html; charset=utf-8',html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+c));
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(url.href==='https://www.youtube.com/iframe_api') {
                await fulfill(event,200,'application/javascript',"window.__calls=[];window.YT={Player:class{constructor(mount,options){this.muted=true;this.time=0;this.options=options;window.__vslOptions=options;window.__vslPlayer=this;const frame=document.createElement('iframe');frame.src='about:blank';frame.dataset.videoId=options.videoId;mount.replaceWith(frame);this.frame=frame;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){this.options.events.onStateChange({data:1});}pauseVideo(){this.options.events.onStateChange({data:2});}seekTo(t){this.time=t;}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return 100;}setVolume(){}getCurrentTime(){return this.time;}getDuration(){return 90;}destroy(){this.frame.remove();}}};window.onYouTubeIframeAPIReady?.();");return;
            }
            if(url.host==='chat.whatsapp.com'){groups.push(url.href);await fulfill(event,204,'text/plain');return;}
            if(url.pathname.startsWith('/build/')) {
                if(live){await call('Fetch.continueRequest',{requestId:event.requestId});return;}
                const file='/workspace/public/build-next/'+url.pathname.slice(7);const data=await readFile(file);
                await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'application/javascript'}],body:data.toString('base64')});return;
            }
            if(event.request.method!=='GET') {
                if(url.pathname==='/medicion'){metrics.push(JSON.parse(event.request.postData));await fulfill(event,204,'application/json');return;}
                assert(url.pathname.startsWith(base),'Only isolated editor fixture mutations allowed');
                const data=JSON.parse(event.request.postData);operations.push({method:event.request.method,path:url.pathname});
                if(event.request.method==='PUT') {
                    assert.equal(data.configuration.video.youtube_url,fixtures.saved_editor.props.landing.configuration.video.youtube_url);
                    assert.deepEqual(data.configuration.confirmation_video,editor.props.landing.configuration.confirmation_video,'Switching tabs preserves independent confirmation media');
                    editor=structuredClone(fixtures.saved_editor);
                } else if(url.pathname.endsWith('/publicar')) {
                    assert.equal(data.revision,editor.props.landing.revision);editor=structuredClone(fixtures.published_editor);
                } else throw Error('Unexpected mutation');
                editor.props.status=url.pathname.endsWith('/publicar')?'Versión guardada publicada.':'Borrador guardado. La versión pública no cambia hasta publicar.';
                await render(event,editor,true);return;
            }
            let page;
            if(url.pathname.startsWith(base)) {
                if(url.pathname.endsWith('/preview/confirmado')) {
                    page=structuredClone(fixtures.confirmation_preview);
                    const content=editor.props.landing.configuration;
                    page.props.confirmation.content=content.after_registration;page.props.confirmation.video=content.confirmation_video;
                    page.props.confirmation.whatsapp_group_url=effectiveGroup(content);
                } else if(url.pathname.endsWith('/preview')) {
                    page=structuredClone(fixtures.preview);page.props.landing.content=structuredClone(editor.props.landing.configuration);
                    delete page.props.landing.content.whatsapp_group;delete page.props.landing.content.confirmation_whatsapp_group;
                    page.props.launch.video_url=editor.props.landing.configuration.video.youtube_url;page.props.launch.whatsapp_url=null;
                } else page=structuredClone(editor);
            } else if(url.pathname==='/__funnel/published') {
                page=structuredClone(fixtures.preview);page.props.landing.content=structuredClone(editor.props.landing.published_configuration);
                page.props.launch.video_url=editor.props.landing.published_configuration.video.youtube_url;page.props.launch.whatsapp_url=null;page.props.landing.preview=false;
            } else if(url.pathname.startsWith('/__funnel/landing/')) {
                page=structuredClone(fixtures.landings[url.pathname.split('/').at(-1)]);
            } else if(url.pathname.startsWith('/__funnel/receipt')) page=structuredClone(fixtures.receipt);
            else {await call('Fetch.continueRequest',{requestId:event.requestId});return;}
            page.url=url.pathname;
            await render(event,page,!!(event.request.headers['X-Inertia']||event.request.headers['x-inertia']));
        } catch(error) {
            if(/invalid interceptionid|no resource with given identifier/i.test(error.message))return;
            runtimeErrors.push(error.message);await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'}).catch(()=>{});
        }
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://www.youtube.com/iframe_api'},{urlPattern:'https://chat.whatsapp.com/*'}]});
    const until=async(expression,label)=>{for(let i=0;i<100;i++){try{if(await evaluate(expression))return;}catch{}await pause(75);}throw Error(label+' '+JSON.stringify(runtimeErrors));};
    const navigate=async path=>{await call('Page.navigate',{url:'https://jakawi.club'+path});await until(`location.pathname===${JSON.stringify(path)}&&document.readyState==='complete'&&!!document.querySelector('h1')`,'Page renders');await evaluate('document.fonts.ready');};
    const click=text=>evaluate(`(()=>{const b=[...document.querySelectorAll('button')].find(b=>b.textContent===${JSON.stringify(text)});if(!b||b.disabled)throw Error('Unavailable: '+${JSON.stringify(text)});b.click()})()`);
    const setField=(id,value,tag='HTMLTextAreaElement')=>evaluate(`(()=>{const e=document.getElementById(${JSON.stringify(id)});Object.getOwnPropertyDescriptor(${tag}.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    const screenshot=async name=>{const {data}=await call('Page.captureScreenshot',{format:'png'});await writeFile(`${output}/${live?'live-':''}${name}.png`,Buffer.from(data,'base64'));};
    const frameReady=stage=>until(`!!document.querySelector('iframe')?.contentDocument?.querySelector(${JSON.stringify(stage==='confirmation'?'.confirmation-page':'#registro')})`,'Stage preview renders');
    await call('Browser.setPermission',{permission:{name:'clipboard-write'},setting:'granted',origin:'https://jakawi.club'});
    await call('Browser.setPermission',{permission:{name:'clipboard-read'},setting:'granted',origin:'https://jakawi.club'});
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate(base+'/editar');await frameReady('landing');
    assert(await evaluate(`!!document.getElementById('content.video.youtube_url')&&!document.getElementById('content.confirmation_video.youtube_url')&&!document.querySelector('[id*="whatsapp_group"]')`));
    assert.equal(await evaluate(`document.querySelectorAll('[role=tab]').length`),2);
    assert(await evaluate(`getComputedStyle(document.querySelector('.editor-save-bar')).position==='sticky'&&document.querySelector('.editor-save-bar').getBoundingClientRect().top<500`));
    assert(await evaluate(`[...document.querySelectorAll('button')].find(b=>b.textContent==='Publicar cambios').disabled`));
    record('top-save-publish-status-and-exclusive-capture-fields');
    await click('Copiar URL de landing');assert.equal(await evaluate('navigator.clipboard.readText()'),'https://jakawi.club/principal');
    await click('Copiar URL de confirmación');assert.equal(await evaluate('navigator.clipboard.readText()'),'https://jakawi.club/principal/gracias');
    record('copy-independent-page-urls');
    const newVideo=fixtures.saved_editor.props.landing.configuration.video.youtube_url;
    await setField('content.video.youtube_url',newVideo);
    await until(`document.querySelector('[data-publication-state]').textContent.includes('sin guardar')`,'Unsaved state');
    await click('PÁGINA DE CONFIRMACIÓN');await frameReady('confirmation');
    assert(await evaluate(`!document.getElementById('content.video.youtube_url')&&!!document.getElementById('content.confirmation_video.youtube_url')&&!!document.getElementById('content.confirmation_whatsapp_group.mode')&&!document.getElementById('landing-title')`));
    assert.equal(await evaluate(`document.getElementById('content.confirmation_whatsapp_group.mode').value`),'custom');
    assert.equal(await evaluate(`document.getElementById('content.confirmation_whatsapp_group.url').value`),'https://chat.whatsapp.com/LegacyCampaignFixture12');
    await until(`!!document.querySelector('iframe')?.contentWindow?.__vslOptions`,'Confirmation preview player ready');
    await evaluate(`(()=>{const w=document.querySelector('iframe').contentWindow;w.__vslOptions.events.onStateChange({data:1});w.__vslOptions.events.onStateChange({data:0})})()`);
    assert.equal(groups.length,0,'Preview ended never navigates');
    record('confirmation-tab-isolated-preview-and-legacy-group');
    await click('LANDING DE CAPTACIÓN');await frameReady('landing');
    assert.equal(await evaluate(`document.getElementById('content.video.youtube_url').value`),newVideo);
    assert(await evaluate(`document.querySelector('iframe').contentDocument.querySelector('.vsl-host iframe')?.dataset.videoId!=='M7lc1UVf-VE'`),'Unsaved media never changes saved preview');
    await click('Guardar borrador');
    await until(`document.querySelector('[data-publication-state]').textContent.includes('falta publicar')`,'Saved draft awaits explicit publish');
    assert.equal(operations.length,1);assert.equal(operations[0].method,'PUT');
    assert.notEqual(editor.props.landing.published_configuration.video.youtube_url,newVideo);
    await frameReady('landing');await until(`document.querySelector('iframe').contentDocument.querySelector('.vsl-host iframe')?.dataset.videoId==='M7lc1UVf-VE'`,'Saved preview shows changed video');
    await click('Publicar cambios');
    await until(`document.querySelector('[data-publication-state]').textContent.includes('Publicado')`,'Publish updates state');
    assert.equal(operations.length,2);assert(operations[1].path.endsWith('/publicar'));
    assert.equal(editor.props.landing.published_configuration.video.youtube_url,newVideo);
    assert(await evaluate(`document.querySelector('[data-published-video]').textContent.includes('M7lc1UVf-VE')`));
    record('save-publish-video-snapshot-independent');
    await navigate('/__funnel/published');
    await until(`document.querySelector('.vsl-host iframe')?.dataset.videoId==='M7lc1UVf-VE'`,'Public renderer displays published video');
    record('public-video-after-explicit-publication');

    for(const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
        await navigate(base+'/editar');await frameReady('landing');
        assert(!await evaluate('document.documentElement.scrollWidth>innerWidth'));
        await screenshot(`editor-capture-${width}`);
        await click('PÁGINA DE CONFIRMACIÓN');await frameReady('confirmation');
        if(width===390)await click('Preview');
        await evaluate(`document.querySelector('.landing-editor-preview').scrollIntoView({block:'start',behavior:'instant'})`);
        assert(!await evaluate('document.documentElement.scrollWidth>innerWidth'));
        await screenshot(`editor-confirmation-${width}`);
        record('editor-tabs-previews-responsive',{width});
        for(const template of ['LEAD_VSL','WEBINAR','WAITLIST']) {
            await navigate('/__funnel/landing/'+template);
            assert.equal(await evaluate(`document.querySelector('[data-landing-template]').dataset.landingTemplate`),template);
            assert(!await evaluate(`document.documentElement.scrollWidth>innerWidth||!!document.querySelector('a[href*="chat.whatsapp.com"]')`));
            const links=await evaluate(`([...document.querySelectorAll('a')].map(a=>({href:a.getAttribute('href'),text:a.textContent})))`);
            assert(links.every(a=>['#registro','/privacidad','/login','/dashboard'].includes(a.href)));
            const ctas=links.filter(a=>a.href==='#registro');assert(ctas.length>=2);assert(ctas.every(a=>a.text==='QUIERO SER PARTE DE JAKAWI'));
            assert.equal(await evaluate(`document.querySelector('#registro button[type=submit]').textContent`),'QUIERO SER PARTE DE JAKAWI');
            await evaluate(`document.querySelector('[data-marketing-cta=hero]').click()`);
            await until(`location.hash==='#registro'&&document.activeElement.id==='registro'`,'Repeated CTA focuses registration');
            if(template==='LEAD_VSL')await screenshot(`capture-${width}`);
            record('template-capture-single-action-no-whatsapp',{width,template});
        }
        await navigate('/__funnel/receipt');
        assert.equal(await evaluate(`document.querySelectorAll('a').length`),1);
        assert.equal(await evaluate(`document.querySelector('a').href`),fixtures.receipt.props.confirmation.whatsapp_group_url);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('a')).animationName`),'confirmation-whatsapp-bounce');
        await until(`!!window.__vslOptions&&document.querySelector('[data-vsl-state]').dataset.vslState==='playing'`,'Receipt player ready');
        await evaluate(`window.__vslOptions.events.onStateChange({data:2})`);assert.equal(groups.length,0);
        await evaluate(`window.__vslOptions.events.onError({data:150})`);assert.equal(groups.length,0);
        assert(await evaluate(`!!document.querySelector('a[href*="chat.whatsapp.com"]')`));
        await navigate('/__funnel/receipt');await until(`!!window.__vslOptions&&document.querySelector('[data-vsl-state]').dataset.vslState==='playing'`,'Receipt ready again');
        await evaluate(`window.__vslOptions.events.onStateChange({data:0})`);
        await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='ended'`,'Ended emitted by real engine');
        assert.equal(groups.at(-1),fixtures.receipt.props.confirmation.whatsapp_group_url);groups.length=0;
        record('confirmation-group-bounce-ended-without-pause-error-redirect',{width});
    }
    if(live) {
        await navigate('/principal');
        assert(!await evaluate(`!!document.querySelector('a[href*="chat.whatsapp.com"]')`));
        const payload=await evaluate(`JSON.parse(document.querySelector('script[data-page=app]').textContent)`);
        await until(`document.querySelector('.vsl-host iframe')?.dataset.videoId===${JSON.stringify(new URL(payload.props.launch.video_url).searchParams.get('v'))}`,'LIVE video matches public effective source');
        record('actual-principal-https-video-and-no-whatsapp');
        await call('Page.navigate',{url:'https://jakawi.club/principal/gracias'});
        await until(`location.pathname==='/principal'&&document.readyState==='complete'&&!!document.querySelector('#registro')`,'No-receipt confirmation returns to landing');
        assert.equal(await evaluate('location.pathname'),'/principal');
        record('actual-gracias-without-receipt-returns-to-landing');
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    assert(!metrics.some(x=>x.event==='registration_completed'));
    const report={stage:live?'HTTPS LIVE assets':'build-next intercepted in browser',checks,operations,viewports:[390,1440],runtime_errors:runtimeErrors,failed_assets:failedAssets,
        no_live_accounts:true,all_browser_mutations_intercepted:true,provider:'YouTube signals intercepted through the official engine',physical_playback_tested:false};
    await writeFile(`${output}/${live?'live-browser':'browser'}.json`,JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify({passed_groups:checks.length,live,viewports:[390,1440]}));
} finally { socket?.close();chrome.kill('SIGTERM'); }
