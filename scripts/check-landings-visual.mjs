// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir, rm } from 'node:fs/promises';
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
        for (let i = 0; i < Math.min((entry.css || []).length, (next.css || []).length); i++) html = html.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
    }
    const fixtures = JSON.parse(await readFile('/evidence/browser-fixtures.json', 'utf8'));
    const operations = [], validations = [], metrics = [], checks = [];
    let editor = structuredClone(fixtures.editor);
    const base = `/admin/landings/${editor.props.landing.id}`;
    const fulfill = (event, code, type, body = '', extra = []) => call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: code, responseHeaders: [{name:'Content-Type',value:type}, ...extra], body: Buffer.from(body).toString('base64') });
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            if (url.pathname === '/marketing/poster-fixture.png') { const body=await readFile('/checks/fixtures/poster-fixture.png'); await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/png'}],body:body.toString('base64')}); return; }
            if (url.href==='https://www.youtube.com/iframe_api') { await fulfill(event,200,'application/javascript',"window.__editorProviderFixture=true;window.YT={Player:class{constructor(mount,options){this.muted=true;this.time=0;const iframe=document.createElement('iframe');iframe.src='about:blank';mount.replaceWith(iframe);this.iframe=iframe;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){}pauseVideo(){}seekTo(t){this.time=t;}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return 100;}setVolume(){}getCurrentTime(){return this.time;}getDuration(){return 90;}destroy(){this.iframe.remove();}}};window.onYouTubeIframeAPIReady?.();");return; }
            if (url.pathname.startsWith('/build/')) {
                const filename='/workspace/public/build-next/'+url.pathname.slice('/build/'.length);
                const body=await readFile(filename);
                await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:filename.endsWith('.css')?'text/css':filename.endsWith('.woff2')?'font/woff2':'application/javascript'}],body:body.toString('base64')});return;
            }
            if (url.pathname === '/medicion') { metrics.push(JSON.parse(event.request.postData)); await fulfill(event,204,'application/json'); return; }
            if (url.pathname === '/registro') throw new Error('No registration request allowed');
            let page;
            if (event.request.method !== 'GET') {
                const data = JSON.parse(event.request.postData);
                if (url.pathname === '/admin/landings/import') {
                    validations.push({path:url.pathname});
                    const doc = JSON.parse(data.editorial_json);
                    if (doc.content.hero?.title === null) { await fulfill(event,422,'application/json',JSON.stringify(fixtures.invalid_import)); return; }
                    const config = structuredClone(data.configuration);
                    for (const [key, value] of Object.entries(doc.content)) config[key] = {...config[key],...value};
                    if (doc.template) config.presentation.template = doc.template;
                    await fulfill(event,200,'application/json',JSON.stringify({configuration:config})); return;
                }
                operations.push({ method: event.request.method, path: url.pathname });
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
                page = structuredClone(url.pathname.endsWith('/preview/confirmado') ? fixtures.confirmation_preview : url.pathname.endsWith('/crear') ? fixtures.create : url.pathname.endsWith('/preview') ? fixtures.preview : url.pathname === '/admin/landings' ? fixtures.index : editor);
                if (url.pathname.endsWith('/preview')) {
                    page.props.landing.content = structuredClone(editor.props.landing.configuration);
                    page.props.landing.revision = editor.props.landing.revision;
                    page.props.launch.video_url = page.props.landing.content.video.youtube_url;
                    page.props.launch.video_poster = page.props.landing.content.video.poster;
                }
                if (url.pathname.endsWith('/preview/confirmado')) {
                    page.props.confirmation.content = structuredClone(editor.props.landing.configuration.after_registration);
                    page.props.confirmation.video = structuredClone(editor.props.landing.configuration.confirmation_video);
                }
                page.url = url.pathname;
            }
            const inertia = event.request.headers['X-Inertia'] || event.request.headers['x-inertia'];
            if (inertia) await fulfill(event, 200, 'application/json', JSON.stringify(page), [{name:'X-Inertia',value:'true'}]);
            else await fulfill(event, 200, 'text/html; charset=utf-8', html.replace(pattern, (_, open, _body, close) => open+JSON.stringify(page).replaceAll('<','\\u003c')+close));
        } catch (error) { if (error.message.includes('Invalid InterceptionId')) return; runtimeErrors.push(error.message); await call('Fetch.failRequest', {requestId:event.requestId,errorReason:'Failed'}).catch(() => {}); }
    });
    await call('Fetch.enable', {patterns:[{urlPattern:'https://www.youtube.com/iframe_api'},{urlPattern:'https://jakawi.club/build/*'},{urlPattern:'https://jakawi.club/marketing/poster-fixture.png'},{urlPattern:'https://jakawi.club/admin/landings*'},{urlPattern:'https://jakawi.club/medicion'},{urlPattern:'https://jakawi.club/registro'}]});
    const until = async (expression, message) => {
        for (let i=0;i<100;i++) { try { if (await evaluate(expression)) return; } catch {} await pause(75); }
        console.log('Browser state at failure',message,await evaluate(`({path:location.pathname,dirty:document.body.textContent.includes('Cambios sin guardar'),template:document.querySelector('#landing-template')?.value,frame:document.querySelector('iframe')?.contentDocument?.querySelector('[data-landing-template]')?.dataset.landingTemplate,errors:[...document.querySelectorAll('[role=alert]')].map(e=>e.textContent),invalid:[...document.querySelectorAll('#landing-form :invalid')].map(e=>e.id),busy:document.querySelector('#landing-form button[type=submit]')?.disabled})`));
        await writeFile('/evidence/browser-debug.json',JSON.stringify({operations,validations,editor},null,2));
        throw new Error(message);
    };
    const navigate = async path => { await call('Page.navigate',{url:'https://jakawi.club'+path}); await until('document.readyState === "complete" && !!document.querySelector("h1")','Page renders'); await pause(250); };
    const screenshot = async name => { const result = await call('Page.captureScreenshot',{format:'png'}); await writeFile(`${output}/${name}.png`,Buffer.from(result.data,'base64')); };
    const click = text => evaluate(`(()=>{const b=[...document.querySelectorAll('button')].find(b=>b.textContent===${JSON.stringify(text)}); if(!b||b.disabled)throw Error('Button unavailable');b.click();})()`);
    const setField = (id,value,tag='HTMLTextAreaElement') => evaluate(`(()=>{const input=document.getElementById(${JSON.stringify(id)});Object.getOwnPropertyDescriptor(${tag}.prototype,'value').set.call(input,${JSON.stringify(value)});input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await call('Browser.setPermission',{permission:{name:'clipboard-write'},setting:'granted',origin:'https://jakawi.club'});
    await call('Browser.setPermission',{permission:{name:'clipboard-read'},setting:'granted',origin:'https://jakawi.club'});
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate(base+'/editar');
    await until('!!document.querySelector("#landing-title") && !!document.querySelector("iframe")?.contentDocument?.querySelector("h1")','Editor and saved preview render');
    assert(await evaluate(`(()=>{const a=document.querySelector('.landing-editor-content').getBoundingClientRect(),b=document.querySelector('.landing-editor-preview').getBoundingClientRect();return b.left>=a.right&&Math.abs(a.top-b.top)<5})()`),'Desktop has two columns');
    assert.equal(await evaluate('document.querySelectorAll("iframe").length'),1,'Saved preview automatic');
    await screenshot('editor-1440');
    await setField('content.hero.title','Titular desde el editor');
    await until(`[...document.querySelectorAll('button')].find(b=>b.textContent==='Publicar versión guardada').disabled`,'Unsaved changes disable publication');
    assert.equal(operations.length,0);
    assert(!await evaluate(`document.querySelector('iframe').contentDocument.querySelector('h1').textContent.includes('Titular desde el editor')`),'Preview shows saved version');
    await click('EXPORTAR JSON');
    let exported=JSON.parse(await evaluate(`document.querySelector('#editorial-output').value`));
    assert.equal(exported.content.hero.title,'Titular desde el editor');
    assert.equal(exported.schema,'jakawi-club-landing-v2');
    assert(!exported.content.video && !exported.content.presentation && !exported.campaign && !exported.status);
    await click('Copiar contenido');
    assert.equal(JSON.parse(await evaluate('navigator.clipboard.readText()')).content.hero.title,'Titular desde el editor');
    await call('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:'/evidence'});
    await rm('/evidence/campana-prueba.json',{force:true});
    await click('Descargar JSON');
    await pause(300);
    assert.equal(JSON.parse(await readFile('/evidence/campana-prueba.json','utf8')).content.hero.title,'Titular desde el editor');
    await click('Cerrar');
    await click('COPIAR BRIEF PARA IA');
    const brief=await evaluate('navigator.clipboard.readText()');
    assert(brief.includes('JAKAWI.CLUB')&&brief.includes('CERRADO')&&brief.includes('jakawi-club-landing-v2')&&brief.includes('Titular desde el editor')&&brief.includes('No inventar ofertas'));
    assert(!brief.includes('youtube_url')&&!brief.includes('editor@example.test')&&!brief.includes('PasswordEditor123')&&!brief.includes('APP_KEY'));
    await click('IMPORTAR JSON');
    await setField('editorial-json',JSON.stringify({schema:'jakawi-club-landing-v1',content:{hero:{title:null}}}));
    await click('Aplicar al formulario');
    await until(`document.querySelector('dialog').textContent.includes('content.hero.title')`,'Field errors shown');
    await screenshot('json-field-errors');
    await setField('editorial-json',JSON.stringify({schema:'jakawi-club-landing-v1',content:{hero:{title:'Titular importado'}}}));
    await click('Aplicar al formulario');
    await until(`!document.querySelector('dialog').open && document.getElementById('content.hero.title').value==='Titular importado'`,'Import applies locally');
    assert.equal(operations.length,0,'Import never saves or publishes');
    assert.equal(validations.length,2,'Validation requests only');
    await click('EXPORTAR JSON');
    exported=JSON.parse(await evaluate(`document.querySelector('#editorial-output').value`));
    assert.equal(exported.content.hero.description,editor.props.landing.configuration.hero.description,'Partial import preserves omitted content');
    assert.equal(exported.content.hero.title,'Titular importado');
    await click('Cerrar');
    await writeFile('/evidence/import-example.json',JSON.stringify(exported,null,2)+'\n');
    await click('IMPORTAR JSON');
    await until(`!!document.querySelector('#editorial-file')`,'File chooser appears');
    const documentNode=await call('DOM.getDocument');
    const fileNode=await call('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'#editorial-file'});
    await call('DOM.setFileInputFiles',{nodeId:fileNode.nodeId,files:['/evidence/import-example.json']});
    await until(`document.querySelector('#editorial-json').value.includes('Titular importado')`,'File import reads full JSON');
    await click('Aplicar al formulario');
    await until(`!document.querySelector('dialog').open`,'Full file applies');
    assert.equal(operations.length,0,'Full file import never persists');
    checks.push({name:'brief-export-unsaved-copy-download-and-local-import',passed:true});
    await setField('content.video.youtube_url','https://www.youtube.com/watch?v=aqz-KE-bpKQ');
    await setField('content.video.poster','/marketing/poster-fixture.png');
    await setField('content.video.focal_x',.25,'HTMLInputElement');
    await setField('content.video.focal_y',.75,'HTMLInputElement');
    assert(!await evaluate(`!!document.getElementById('content.video.aspect_ratio')`),'Frame size cannot be edited');
    await click('Guardar borrador');
    await until(`[...document.querySelectorAll('button')].find(b=>b.textContent==='Publicar versión guardada')?.disabled===false`,'Save completes');
    assert.equal(operations.length,1); assert.equal(operations[0].method,'PUT');
    assert.equal(editor.props.landing.configuration.hero.title,'Titular importado');
    assert.equal(editor.props.landing.configuration.video.youtube_url,'https://www.youtube.com/watch?v=aqz-KE-bpKQ');
    assert.equal(editor.props.landing.configuration.video.focal_x,.25);
    assert.notEqual(editor.props.landing.published_configuration.hero.title,'Titular importado','Save does not publish');
    for (const width of [390,430,1440]) {
        await click(width===1440?'Desktop':String(width));
        await until(`document.querySelector('iframe')?.contentWindow?.innerWidth===${width} && !!document.querySelector('iframe')?.contentDocument?.querySelector('h1')`,'Preview viewport');
        const result = await evaluate(`(()=>{const f=document.querySelector('iframe'),d=f.contentDocument,w=f.contentWindow,p=d.querySelector('.vsl-frame').getBoundingClientRect();return {width:w.innerWidth,overflow:d.documentElement.scrollWidth>w.innerWidth,title:d.querySelector('h1').textContent,closed:d.querySelector('#registro fieldset').disabled,ratio:p.width/p.height,poster:d.querySelector('.vsl-frame img')?.style.objectPosition,preview:d.body.textContent.includes('Vista previa del borrador')};})()`);
        assert.equal(result.width,width); assert(!result.overflow); assert(result.title.includes('Titular importado')); assert(result.closed && result.preview); assert(Math.abs(result.ratio-3/4)<.002);
        assert.equal(result.poster,'25% 75%','Poster uses configured crop');
        checks.push({name:'real-renderer-preview',...result});
        await screenshot(`preview-${width}`);
    }
    await evaluate(`[...document.querySelectorAll('summary')].find(e=>e.textContent==='Después del registro').parentElement.open=true`);
    assert(await evaluate(`!!document.getElementById('content.after_registration.title')`));
    assert(await evaluate(`!!document.querySelector('#next-countdown')`),'WhatsApp countdown control is available');
    await click('Después del registro');
    for (const width of [390,430,1440]) {
        await click(width===1440?'Desktop':String(width));
        await until(`document.querySelector('iframe')?.contentWindow?.innerWidth===${width}&&!!document.querySelector('iframe')?.contentDocument?.querySelector('[data-activation]')`,'Saved confirmation preview renders');
        const result=await evaluate(`(()=>{const f=document.querySelector('iframe'),d=f.contentDocument;return {overflow:d.documentElement.scrollWidth>f.contentWindow.innerWidth,preview:d.body.textContent.includes('Vista previa del borrador'),activation:d.querySelector('[data-activation]').dataset.activation,title:d.querySelector('h1').textContent,timer:!!d.querySelector('[role=timer]')}})()`);
        assert(!result.overflow && result.preview && !result.timer);
        assert(await evaluate(`(()=>{const f=document.querySelector('iframe').contentDocument.querySelector('.vsl-frame').getBoundingClientRect();return Math.abs(f.width/f.height-.75)<.002})()`),'Confirmation preview keeps 3:4'); assert.equal(result.activation,'pending');
        assert.equal(result.title,editor.props.landing.configuration.after_registration.title);
        await evaluate(`document.querySelector('.landing-editor-preview').scrollIntoView({block:'start'});document.querySelector('iframe').contentWindow.scrollTo(0,document.querySelector('iframe').contentDocument.querySelector('main').offsetTop)`);
        await screenshot(`confirmation-preview-${width}`);
        checks.push({name:'real-confirmation-preview',width,...result});
    }
    await click('Landing');
    await until(`!!document.querySelector('iframe')?.contentDocument?.querySelector('#registro')`,'Back to landing preview');
    assert.equal(metrics.length,0,'Preview sends no analytics');
    assert.equal(operations.length,1,'Preview never saves or publishes');
    await click('Publicar versión guardada');
    await until('document.body.textContent.includes("Versión guardada publicada.")','Publish completes');
    assert.equal(operations.length,2); assert(operations[1].path.endsWith('/publicar'));
    assert.equal(editor.props.landing.published_configuration.hero.title,'Titular importado');
    await until(`document.querySelector('[data-publication-state]').textContent.includes('coincide')`,'Publication state accurate');
    checks.push({name:'save-preview-publish-independent',passed:true});
    for (const template of ['LEAD_VSL','WEBINAR','WAITLIST']) {
        console.log('Checking template',template);
        await setField('landing-template',template,'HTMLSelectElement');
        await click('Guardar borrador');
        await until(`!!document.querySelector('iframe')?.contentDocument?.querySelector('[data-landing-template="${template}"]')`,'Template renderer loads');
        let result=await evaluate(`(()=>{const d=document.querySelector('iframe').contentDocument,registration=d.querySelector('.registration-section'),benefits=d.querySelector('#como-funciona');return {template:d.querySelector('[data-landing-template]').dataset.landingTemplate,forms:d.querySelectorAll('#registro').length,video:!!d.querySelector('.vsl-frame'),faq:!!d.querySelector('#faq-title'),registrationFirst:!!(registration.compareDocumentPosition(benefits)&Node.DOCUMENT_POSITION_FOLLOWING),hasUnconfirmedTime:!!d.querySelector('[data-webinar-schedule] time'),benefits:benefits.querySelectorAll('article').length,title:d.querySelector('h1').textContent}})()`);
        assert.equal(result.forms,1); assert(result.title.includes('Titular importado')); assert.equal(result.template,template);
        assert.equal(result.video,editor.props.landing.configuration.presentation.show_video); assert.equal(result.registrationFirst,template==='WAITLIST'); assert(!result.hasUnconfirmedTime);
        if(template==='WAITLIST') assert(result.benefits<=3);
        checks.push({name:'template-renderer',...result});
        await screenshot(`template-${template.toLowerCase()}-desktop`);
        await navigate(base+'/preview');
        await screenshot(`renderer-${template.toLowerCase()}-1440`);
        await navigate(base+'/editar');
        await until(`!!document.querySelector('iframe')?.contentDocument?.querySelector('h1')`,'Preview reloads');
        for (const size of [390,430,768,1440]) {
            await click(size===1440?'Desktop':String(size===768?430:size));
            // Also verify the 768 public renderer independently of the editor's three controls.
            if(size===768) await evaluate(`document.querySelector('iframe').style.width='768px'`);
            await until(`document.querySelector('iframe').contentWindow.innerWidth===${size}`,'Template responsive viewport');
            assert(await evaluate(`(()=>{const f=document.querySelector('iframe');return f.contentDocument.documentElement.scrollWidth<=f.contentWindow.innerWidth})()`),'No renderer overflow');
        }
        if(template==='WEBINAR') {
            await evaluate(`document.querySelector('#show-main-video').click()`);
            await click('Guardar borrador');
            await until(`!document.querySelector('iframe').contentDocument.querySelector('.vsl-frame')`,'Webinar optional video');
            assert(editor.props.landing.configuration.video.youtube_url,'Hidden video preserved');
        }
        if(template==='WAITLIST') {
            await evaluate(`[...document.querySelectorAll('label')].find(e=>e.textContent.includes('Mostrar FAQ')).querySelector('input').click()`);
            await click('Guardar borrador');
            await until(`!document.querySelector('iframe').contentDocument.querySelector('#faq-title')`,'Waitlist optional FAQ');
        }
    }
    for(const size of [390,430,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width:size,height:900,deviceScaleFactor:1,mobile:false});
        await navigate(base+'/editar');
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Editor fits viewport');
        if(size<=768){
            assert(await evaluate(`getComputedStyle(document.querySelector('.landing-editor-preview')).display==='none'`),'Mobile editor uses tabs');
            await click('Preview');
            assert(await evaluate(`getComputedStyle(document.querySelector('.landing-editor-content')).display==='none'`),'Mobile preview tab');
            await screenshot(`editor-preview-${size}`);
            await click('Editor');
        }
        await screenshot(`editor-${size}`);
        checks.push({name:'responsive-editor',width:size,passed:true});
    }
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
    await navigate('/admin/landings/crear');
    await until('!!document.getElementById("landing-slug")','Create renders');
    assert(!await evaluate('!!document.querySelector("iframe")'));
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'));
    await click('IMPORTAR JSON'); await click('Cerrar'); await click('EXPORTAR JSON');
    assert(JSON.parse(await evaluate(`document.querySelector('#editorial-output').value`)).schema==='jakawi-club-landing-v2');
    await click('Cerrar'); await screenshot('create-390');
    await navigate('/admin/landings');
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'));
    assert(await evaluate(`document.body.textContent.includes('PORTADA PRINCIPAL')`));
    checks.push({name:'create-tools-and-home-index',passed:true});
    assert.deepEqual(runtimeErrors,[]); assert.deepEqual(failedAssets,[]);
    await writeFile(`${output}/editor-visual.json`,JSON.stringify({checks,operations,validations,preview_metrics:metrics.length,live_test_users:0,backend_fixtures:'real isolated HTTP kernel responses; browser mutations intercepted',runtime_errors:runtimeErrors,failed_assets:failedAssets},null,2)+'\n');
    console.log(`${checks.length} editor/preview groups passed; 390, 430, 768, 1440 px; no LIVE writes.`);
} finally { socket?.close(); chrome.kill('SIGTERM'); }
