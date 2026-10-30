// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
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
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

    let html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const publishedContent = JSON.parse(html.match(pattern)[2]).props.landing.content;
    const publishedTemplate = publishedContent.presentation.template;
    const publishedSections = publishedTemplate === 'WEBINAR' && publishedContent.presentation.webinar && publishedContent.webinar
        ? publishedContent.presentation.webinar.sections.filter(section => section.visible || section.id === 'registration').map(section => section.id) : [];
    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json', 'utf8'));
    const nextManifest = live ? oldManifest : JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
    for (const [name, entry] of Object.entries(oldManifest)) {
        const next = nextManifest[name];
        if (!next) continue;
        html = html.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
        for (let i = 0; i < Math.min((entry.css || []).length, (next.css || []).length); i++) html = html.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
    }
    const fixtures = JSON.parse(await readFile('/evidence/webinar-fixtures.json', 'utf8'));
    const historical = JSON.parse(await readFile('/evidence/browser-fixtures.json', 'utf8'));
    const v3 = JSON.parse(await readFile('/evidence/json-v3-fixtures.json', 'utf8'));
    let page, heldSubmission, navigation = 0;
    const checks = [], metrics = [], submissions = [], writes = [], imports = [];
    const fulfill = (event, code, type, body = '', extra = []) => call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: code, responseHeaders: [{name:'Content-Type',value:type}, ...extra], body: Buffer.from(body).toString('base64') });
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            if (url.href === 'https://www.youtube.com/iframe_api') { await fulfill(event,200,'application/javascript',"window.YT={Player:class{constructor(mount,options){this.muted=true;const iframe=document.createElement('iframe');iframe.src='about:blank';mount.replaceWith(iframe);this.iframe=iframe;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){}pauseVideo(){}seekTo(){}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return 100;}setVolume(){}getCurrentTime(){return 0;}getDuration(){return 90;}destroy(){this.iframe.remove();}}};window.onYouTubeIframeAPIReady?.();"); return; }
            if (url.pathname.startsWith('/build/') && live) { await call('Fetch.continueRequest', { requestId: event.requestId }); return; }
            if (url.pathname.startsWith('/build/')) {
                const file = '/workspace/public/build-next/' + url.pathname.slice('/build/'.length);
                const body = await readFile(file);
                await call('Fetch.fulfillRequest', {requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'application/javascript'}],body:body.toString('base64')}); return;
            }
            if ((url.pathname.startsWith('/marketing/') || ['/jakawi-logo.svg','/jakawi-mark.svg'].includes(url.pathname))) {
                const file = url.pathname === '/marketing/poster-fixture.png' ? '/checks/fixtures/poster-fixture.png' : '/workspace/public'+url.pathname;
                await call('Fetch.fulfillRequest', {requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:file.endsWith('.svg')?'image/svg+xml':'image/png'}],body:(await readFile(file)).toString('base64')}); return;
            }
            if (url.pathname === '/medicion') { metrics.push(JSON.parse(event.request.postData)); await fulfill(event,204,'application/json'); return; }
            if (url.pathname === '/registro') { submissions.push(JSON.parse(event.request.postData)); heldSubmission = event; return; }
            if (event.request.method === 'POST' && /\/admin\/landings\/(?:\d+\/)?import$/.test(url.pathname)) {
                const data = JSON.parse(event.request.postData);
                const document = JSON.parse(data.editorial_json);
                imports.push({path: url.pathname, document});
                if (document.content.hero?.title === null) {
                    await fulfill(event,422,'application/json',JSON.stringify(v3.errors['content.hero.title'])); return;
                }
                const result = url.pathname === '/admin/landings/import' ? v3.imported : v3.patched;
                await fulfill(event,200,'application/json',JSON.stringify(result)); return;
            }
            if (event.request.method !== 'GET') { writes.push(url.pathname); await fulfill(event,204,'application/json'); return; }
            if (live && ['/', '/principal', '/login'].includes(url.pathname)) {
                await call('Fetch.continueRequest', { requestId: event.requestId }); return;
            }
            const response = structuredClone(page);
            if (url.pathname.includes('/preview')) {
                if (url.pathname.endsWith('/confirmado')) {
                    Object.assign(response, structuredClone(v3.confirmation));
                } else {
                    Object.assign(response, structuredClone(fixtures.empty));
                    response.props.landing.content = structuredClone(page.props.landing.configuration);
                    response.props.landing.preview = true;
                    response.props.launch.video_url = response.props.landing.content.video.youtube_url;
                    response.props.launch.video_poster = response.props.landing.content.video.poster;
                    response.props.launch.registration_open = false;
                }
            }
            response.url = url.pathname + url.search;
            await fulfill(event,200,'text/html; charset=utf-8',html.replace(pattern,(_,open,_body,close)=>open+JSON.stringify(response).replaceAll('<','\\u003c')+close));
        } catch (error) {
            if (/invalid interceptionid/i.test(error.message)) return;
            runtimeErrors.push(error.message);
            await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'}).catch(()=>{});
        }
    });
    await call('Fetch.enable', {patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://www.youtube.com/iframe_api'}]});
    const until = async (expression, label) => {
        for (let i=0;i<100;i++) { if (await evaluate(expression)) return; await pause(50); }
        console.error('Diagnostics',runtimeErrors,failedAssets,await evaluate('({path:location.pathname,body:document.body.textContent.slice(0,300)})'));
        throw Error(label);
    };
    const navigate = async (fixture, mutate = () => {}) => {
        page = structuredClone(fixture); mutate(page);
        await call('Page.navigate', {url:`https://jakawi.club/__webinar/${++navigation}`});
        await until('document.readyState==="complete" && !!document.querySelector("h1")','Webinar renders');
        await pause(150);
    };
    const fill = async (id, value, tag = 'HTMLInputElement') => {
        await evaluate(`(()=>{const input=document.getElementById(${JSON.stringify(id)});Object.getOwnPropertyDescriptor(${tag}.prototype,'value').set.call(input,${JSON.stringify(value)});input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        await pause(30);
    };
    const screenshot = async name => { const shot = await call('Page.captureScreenshot',{format:'png'}); await writeFile(`${output}/${name}.png`,Buffer.from(shot.data,'base64')); };
    await call('Network.setCookie',{name:'XSRF-TOKEN',value:'webinar-browser-fixture',domain:'jakawi.club',path:'/',secure:true});
    for (const width of [390, 1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
        for (const fixture of [historical.index, historical.create, fixtures.editor]) {
            await navigate(fixture);
            assert(await evaluate('!!document.querySelector("[data-landing-ai-kit] summary")'));
            await evaluate('document.querySelector("[data-landing-ai-kit] summary").click()');
            assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-landing-ai-kit] a")].map(a=>a.getAttribute("href"))'),
                ['prompt','example','schema','kit'].map(name=>`/admin/landings/crear-con-ia/${name}`));
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
            if (fixture.component === 'admin/LandingEditor') {
                assert(await evaluate(`document.querySelector('[aria-label="Herramientas editoriales"]').nextElementSibling.matches('[data-landing-ai-kit]')`));
            }
        }
        checks.push(`ai-kit-index-create-editor-actions-responsive-${width}`);
    }
    await navigate(historical.create, p=>{p.props.ai_kit_zip_available=false;});
    await evaluate('document.querySelector("[data-landing-ai-kit] summary").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-landing-ai-kit] a").length'),3);
    assert(await evaluate('document.querySelector("[data-landing-ai-kit] button").disabled'));
    assert(await evaluate('document.querySelector("[data-landing-ai-kit]").textContent.includes("tres archivos individuales")'));
    checks.push('ai-kit-zip-unavailable-keeps-three-individual-downloads');
    for (const width of [390,430,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
        await navigate(fixtures.empty, p => {p.props.launch.registration_open=true;p.props.landing.preview=false;p.props.launch.video_url=null;});
        assert.equal(await evaluate('!!document.querySelector("[data-webinar-countdown]")'),false);
        assert.equal(await evaluate('document.querySelector("#webinar-event-title").textContent'),'Próximo webinar en vivo');
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        assert.equal(await evaluate('document.querySelectorAll("#registro").length'),1);
        assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-marketing-cta]")].map(e=>e.getAttribute("href"))'),['#registro','#registro','#registro']);
        assert(await evaluate('[...document.querySelectorAll("[data-marketing-cta]")].every(e=>e.textContent.includes("RESERVAR MI LUGAR GRATIS"))'));
        assert.equal(await evaluate(`document.querySelectorAll('a[href*="chat.whatsapp.com"]').length`),0);
        assert(await evaluate('[...document.querySelectorAll(".brand-logo")].every(e=>e.complete && e.naturalWidth>0)'));
        await screenshot(`webinar-${width}`);
        await evaluate('document.querySelector("[data-marketing-cta=hero]").click()');
        await until('document.activeElement.id==="registro"','Hero CTA focuses shared form');
        if (width<768) {
            await evaluate('document.querySelector("#name").focus()');
            assert(await evaluate('document.querySelector(".mobile-sticky-cta").hidden'));
            await evaluate('document.activeElement.blur();document.querySelector("[data-webinar-section=opportunity]").scrollIntoView({behavior:"instant",block:"start"})');
            await until('!document.querySelector(".mobile-sticky-cta").hidden','Sticky available away from form');
            await evaluate('document.querySelector("[data-marketing-cta=sticky]").click()');
            await until('document.activeElement.id==="registro"&&document.querySelector(".mobile-sticky-cta").hidden','Sticky focuses form and clears fields');
        }
        checks.push(`responsive-${width}-cta-sticky-no-date-no-whatsapp`);
        await navigate(fixtures.empty, p => {p.props.launch.video_url='https://www.youtube.com/watch?v=M7lc1UVf-VE';p.props.launch.video_poster=null;});
        await until('!!document.querySelector(".vsl-frame")','Shared player mounts');
        assert(await evaluate('(()=>{const r=document.querySelector(".vsl-frame").getBoundingClientRect();return Math.abs(r.width/r.height-.75)<.01})()'));
        await screenshot(`webinar-video-${width}`);
        checks.push(`shared-player-3x4-${width}-fixture-only`);
    }
    await navigate(fixtures.dated, p => {p.props.launch.video_url=null;});
    assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-webinar-section]")].map(e=>e.dataset.webinarSection)'),['final','faq','registration','steps','early','benefits','event','hero']);
    assert(await evaluate('document.querySelector("[data-webinar-schedule] time").textContent.includes("19:30")'));
    assert.equal(await evaluate('document.querySelector("[data-webinar-schedule] time").dateTime'),'2030-11-10T19:30:00-04:00');
    const clock = await evaluate('document.querySelector("[data-webinar-countdown]").textContent');
    await pause(1200);
    assert.notEqual(await evaluate('document.querySelector("[data-webinar-countdown]").textContent'),clock);
    assert.equal(metrics.filter(m=>m.event==='landing_visit').length,6,'Only public fixtures emit visits; previews do not');
    checks.push('saved-order-visibility-bolivia-date-countdown-preview-no-analytics');
    await navigate(fixtures.empty, p => {p.props.landing.content.presentation.webinar.starts_at='2020-01-01T19:30:00-04:00';p.props.launch.video_url=null;});
    assert(await evaluate('!document.querySelector("[data-webinar-countdown]") && !!document.querySelector("[data-webinar-elapsed]")'));
    checks.push('elapsed-countdown-never-restarts');
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});
    await navigate(fixtures.empty,p=>{p.props.launch.registration_open=true;p.props.landing.preview=false;p.props.launch.video_url=null;p.props.referral_code='REFERRAL_FIXTURE';});
    await fill('name','Persona de prueba'); await fill('email','browser@example.invalid'); await fill('phone','70000000');
    await evaluate('(()=>{const f=document.querySelector("#registro form");f.requestSubmit();f.dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));})()');
    await until('document.querySelector("#registro fieldset").disabled && document.querySelector("#registro button[type=submit]").disabled','Double submit disabled');
    await evaluate('document.querySelector("#registro form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
    await pause(100); assert.equal(submissions.length,1);
    assert.equal(submissions[0].referral_code,'REFERRAL_FIXTURE');assert.equal(submissions[0].phone,'+59170000000');
    const errorPage={...page,props:{...page.props,errors:{email:'Revisa tu correo.'}}};
    await fulfill(heldSubmission,200,'application/json',JSON.stringify(errorPage),[{name:'X-Inertia',value:'true'}]);
    await until('!document.querySelector("#registro fieldset").disabled && document.querySelector("#email").getAttribute("aria-invalid")==="true"','Recoverable error restores fields');
    assert.equal(await evaluate('document.querySelector("#name").value'),'Persona de prueba');
    assert.equal(await evaluate('document.querySelector("#email").value'),'browser@example.invalid');
    assert.equal(await evaluate('document.querySelector("input[name=phone]").value'),'+59170000000');
    checks.push('registration-error-preserves-data-e164-referral-single-submit');
    for (const width of [390,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
        await navigate(fixtures.editor);
        assert(await evaluate('!!document.querySelector("#stage-landing")&&!!document.querySelector("#stage-confirmation")'));
        await fill('webinar-starts-at','2031-05-02T20:15');
        await evaluate('[...document.querySelectorAll("button")].find(b=>b.getAttribute("aria-label")==="Subir CTA final")?.click()');
        await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="EXPORTAR JSON").click()');
        const exported=JSON.parse(await evaluate('document.querySelector("#editorial-output").value'));
        assert.equal(exported.schema,'jakawi-club-landing-v3');
        assert(exported.content.webinar && exported.content.presentation.webinar);
        assert.equal(exported.content.presentation.webinar.starts_at,'2031-05-02T20:15:00-04:00');
        await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Cerrar").click()');
        await evaluate('document.querySelector("#stage-confirmation").click()');
        assert(await evaluate('!!document.querySelector("#show-confirmation-video")'));
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
        await screenshot(`webinar-editor-${width}`);
        checks.push(`editor-${width}-tabs-date-layout-export-no-write`);
    }
    await navigate(historical.create);
    await fill('landing-template','WEBINAR','HTMLSelectElement');
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Usar contenido inicial WEBINAR").click()');
    assert.equal(await evaluate('document.getElementById("content.hero.description").value'),fixtures.empty.props.landing.content.hero.description);
    assert.equal(await evaluate('document.querySelector("#webinar-starts-at").value'),'');
    // Use real PHP import responses as fixtures; no second implementation of JSON merging here.
    await navigate(historical.create);
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="IMPORTAR JSON").click()');
    await fill('editorial-json',JSON.stringify(v3.document),'HTMLTextAreaElement');
    await evaluate('document.querySelector("dialog form").requestSubmit()');
    await until('!document.querySelector("dialog").open && document.querySelector("#landing-template").value==="WEBINAR"','v3 new import activates WEBINAR');
    assert.equal(imports.at(-1).path,'/admin/landings/import');
    assert.equal(await evaluate('document.querySelector("#webinar-starts-at").value'),'');
    assert.equal(await evaluate('document.querySelector("#show-main-video").checked'),false);
    assert.equal(await evaluate('[...document.querySelectorAll("[aria-label^=Subir]")].length'),9);
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="EXPORTAR JSON").click()');
    assert.deepEqual(JSON.parse(await evaluate('document.querySelector("#editorial-output").value')),v3.document);
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Cerrar").click()');
    await evaluate('document.querySelector("#stage-confirmation").click()');
    assert.equal(await evaluate('document.querySelector("#show-confirmation-video").checked'),false);
    assert.equal(await evaluate('document.getElementById("content.confirmation_whatsapp_group.mode").value'),'none');
    checks.push('v3-new-complete-import-activates-composition-no-seed-export-equivalent-no-save');
    await navigate(v3.editor);
    await fill('content.seo.title','SEO todavía sin guardar','HTMLTextAreaElement');
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="IMPORTAR JSON").click()');
    await fill('editorial-json',JSON.stringify(v3.patch),'HTMLTextAreaElement');
    await evaluate('document.querySelector("dialog form").requestSubmit()');
    await until('!document.querySelector("dialog").open && document.querySelector("#webinar-starts-at").value==="2030-11-10T19:30"','v3 existing patch applies');
    assert.equal(imports.at(-1).path,`/admin/landings/${v3.editor.props.landing.id}/import`);
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="EXPORTAR JSON").click()');
    const patchedExport = JSON.parse(await evaluate('document.querySelector("#editorial-output").value'));
    assert.equal(patchedExport.content.video.youtube_url,v3.patched.configuration.video.youtube_url);
    assert.equal(patchedExport.content.confirmation_video.youtube_url,v3.patched.configuration.confirmation_video.youtube_url);
    assert.equal(patchedExport.content.confirmation_whatsapp_group.mode,'custom');
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Cerrar").click()');
    await evaluate('Object.defineProperty(navigator,"clipboard",{configurable:true,value:{writeText:()=>Promise.reject(new Error("Clipboard fixture"))}});[...document.querySelectorAll("button")].find(b=>b.textContent==="COPIAR BRIEF PARA IA").click()');
    await until('document.querySelector("dialog").open && !!document.querySelector("#editorial-output")','v3 brief fallback opens');
    const brief = await evaluate('document.querySelector("#editorial-output").value');
    assert(brief.includes('jakawi-club-landing-v3') && brief.includes('starts_at') && brief.includes('show_confirmation_video') && brief.includes('confirmation_whatsapp_group'));
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Cerrar").click()');
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="IMPORTAR JSON").click()');
    await fill('editorial-json',JSON.stringify({schema:'jakawi-club-landing-v3',content:{hero:{title:null}}}),'HTMLTextAreaElement');
    await evaluate('document.querySelector("dialog form").requestSubmit()');
    await until('document.querySelector("dialog").textContent.includes("content.hero.title")','v3 validation error has JSON path');
    assert(await evaluate('document.querySelector("dialog").textContent.includes("obligatorio")'));
    await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent==="Cerrar").click()');
    checks.push('v3-existing-patch-independent-videos-groups-brief-errors-no-save');
    await navigate(v3.dated);
    assert.equal(await evaluate('document.querySelector("[data-webinar-schedule] time").dateTime'),'2030-11-10T19:30:00-04:00');
    assert(await evaluate('!!document.querySelector("[data-webinar-countdown]")'));
    assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-webinar-section]")].map(e=>e.dataset.webinarSection)'),['final','faq','registration','steps','early','benefits','event','hero']);
    assert.equal(await evaluate(`document.querySelectorAll('a[href*="chat.whatsapp.com"]').length`),0);
    await navigate(v3.dated_confirmation);
    await until('!!document.querySelector(".vsl-frame")','v3 confirmation mounts shared player');
    assert.equal(await evaluate('document.querySelector(".confirmation-whatsapp-cta").textContent'),'UNIRME AL GRUPO DE WHATSAPP');
    assert.equal(await evaluate('document.querySelector(".confirmation-whatsapp-cta").tagName'),'BUTTON');
    checks.push('v3-saved-previews-date-countdown-order-visibility-confirmation-player-whatsapp-only-after');
    if (live) {
        for (const width of [390,1440]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
            for (const path of ['/', '/principal']) {
                await call('Page.navigate', {url:'https://jakawi.club'+path});
                await until('document.readyState==="complete" && !!document.querySelector("#registro")','Existing public landing loads');
                await until('!document.querySelector("#registro fieldset").disabled','Existing registration remains open');
                assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
                assert.equal(await evaluate('document.querySelector("main[data-landing-template]").dataset.landingTemplate'),publishedTemplate);
                assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-webinar-section]")].map(section=>section.dataset.webinarSection)'),publishedSections);
            }
            await screenshot(`existing-home-live-${width}`);
            checks.push(`existing-home-principal-live-open-${width}`);
        }
        await call('Page.navigate', {url:'https://jakawi.club/login'});
        await until('!!document.querySelector("#jakawi-login")','Existing JAKAWI SSO login remains available');
        checks.push('existing-sso-login-live');
    }
    assert.equal(writes.length,0,'No browser mutation reaches LIVE');
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);
    checks.push('reusable-new-webinar-seed-no-save-no-publication');
    await writeFile(`${output}/browser-results.json`,JSON.stringify({checks,passed:checks.length,live_assets:live,live_registrations:0,provider_playback_verified:false},null,2));
    console.log(JSON.stringify({passed:checks.length,live_assets:live,live_registrations:0,provider_playback_verified:false}));
} finally {
    socket?.close(); chrome.kill('SIGTERM');
}
