// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Reuses isolated kernel receipts; provider signals and every mutation are intercepted.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const fixture = JSON.parse(await readFile('/checks/fixtures/confirmation-pages.json', 'utf8'));
const receipts = fixture.receipts;
const confirmations = { confirmed: fixture.confirmed };
for (const [name, page] of Object.entries(receipts)) confirmations['campaign-' + name] = page;
const widths = [390, 430, 1440];
const live = process.argv.includes('--live');
await mkdir(output, { recursive: true });
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


    let html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;

    let nextBuildHtml = value => value;
    if (!live) {
        const before = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
        const after = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
        nextBuildHtml = value => {
            for (const [name, entry] of Object.entries(before)) {
                const next = after[name];
                if (!next) continue;
                value = value.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
                for (let i = 0; i < Math.min((entry.css || []).length, (next.css || []).length); i++) value = value.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
            }
            return value;
        };
        html = nextBuildHtml(html);
    }
    const checks = [], metrics = [];
    const record = check => { checks.push(check); console.log('PASS', check.name, check.campaign || '', check.width || ''); };
    let fixturePage, navigation = 0, confirmationKind = 'pending';
    let blockGroupNavigation = false;
    const navigationRequests = [];
    on('Page.frameRequestedNavigation', event => { if (event.url.startsWith('https://chat.whatsapp.com/')) navigationRequests.push(event.url); });
    const groupNavigations = [];
    const fulfill = (event, status, type, body = '') => call('Fetch.fulfillRequest', {
        requestId: event.requestId, responseCode: status,
        responseHeaders: [{ name: 'Content-Type', value: type }], body: Buffer.from(body).toString('base64'),
    });
    on('Fetch.requestPaused', async event => {
        if (stopping) return;
        try {
            const url = new URL(event.request.url);
            if (url.pathname === '/marketing/poster-fixture.png') { const body=await readFile('/checks/fixtures/poster-fixture.png'); await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/png'}],body:body.toString('base64')}); return; }
            if (url.href === 'https://www.youtube.com/iframe_api') { await fulfill(event,200,'application/javascript',"window.__vslFixture=true;window.__instances=0;window.__calls=[];window.__vslEvents=[];window.YT={Player:class{constructor(mount,options){window.__instances++;this.muted=true;this.volume=100;this.time=0;window.__vslOptions=options;window.__vslPlayer=this;for(const [name,callback] of Object.entries(options.events))options.events[name]=event=>{window.__vslEvents.push({name,data:event?.data??null});callback(event);};const iframe=document.createElement('iframe');iframe.src='about:blank';mount.replaceWith(iframe);this.iframe=iframe;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){window.__calls.push('play');window.__vslOptions.events.onStateChange({data:1});}pauseVideo(){window.__calls.push('pause');window.__vslOptions.events.onStateChange({data:2});}seekTo(time){this.time=time;window.__calls.push('seek:'+time);}mute(){this.muted=true;window.__calls.push('mute');}unMute(){this.muted=false;window.__calls.push('unmute');}isMuted(){return this.muted;}getVolume(){return this.volume;}setVolume(volume){this.volume=volume;}getCurrentTime(){return this.time;}getDuration(){return 90;}destroy(){this.iframe.remove();window.__calls.push('dispose');}}};window.onYouTubeIframeAPIReady?.();"); return; }
            if (url.host === 'chat.whatsapp.com') {
                groupNavigations.push(url.href);
                if (blockGroupNavigation) { await fulfill(event, 204, 'text/plain'); return; }
                await fulfill(event, 200, 'text/html', '<h1>Destino aprobado interceptado por la prueba</h1>'); return;
            }
            if (url.pathname.startsWith('/__confirmation/')) {
                const page = structuredClone(fixturePage || confirmations[confirmationKind]); page.url = url.pathname + url.search;
                await fulfill(event, 200, 'text/html; charset=utf-8', html.replace(pattern,
                    (_, open, _body, close) => open + JSON.stringify(page).replaceAll('<', '\\u003c') + close)); return;
            }
            if (event.request.method !== 'GET') {
                if (url.pathname === '/medicion') metrics.push(JSON.parse(event.request.postData));
                await fulfill(event, 204, 'application/json');
                return; // Browser tests never write to LIVE.
            }
            if (url.pathname.startsWith('/__registration/')) {
                const page = structuredClone(fixturePage);
                page.url = url.pathname + url.search;
                await fulfill(event, 200, 'text/html; charset=utf-8', html.replace(pattern,
                    (_, open, _body, close) => open + JSON.stringify(page).replaceAll('<', '\\u003c') + close));
                return;
            }
            if (url.pathname.startsWith('/build/') && !live) {
                const file = '/workspace/public/build-next/' + url.pathname.slice('/build/'.length);
                const body = await readFile(file);
                await call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: 200,
                    responseHeaders: [{ name: 'Content-Type', value: file.endsWith('.css') ? 'text/css' : file.endsWith('.woff2') ? 'font/woff2' : 'application/javascript' }],
                    body: body.toString('base64') });
                return;
            }
            if (!live && event.resourceType === 'Document') {
                const response = await fetch(url);
                await fulfill(event, response.status, 'text/html; charset=utf-8', nextBuildHtml(await response.text()));
                return;
            }
            await call('Fetch.continueRequest', { requestId: event.requestId });
        } catch (error) {
            if (stopping || /invalid interceptionid|no resource with given identifier/i.test(error.message)) return;
            runtimeErrors.push(error.message);
            try { await call('Fetch.failRequest', { requestId: event.requestId, errorReason: 'Failed' }); }
            catch { /* A navigation may already have cancelled the paused request. */ }
        }
    });
    await call('Fetch.enable', { patterns: [{ urlPattern: 'https://jakawi.club/*' }, { urlPattern: 'https://chat.whatsapp.com/*' }, { urlPattern: 'https://www.youtube.com/iframe_api' }] });
    const until = async (expression, label) => {
        for (let i = 0; i < 100; i++) {
            try { if (await evaluate(expression)) return; } catch {}
            await pause(75);
        }
        console.error('Navigation diagnostics', await evaluate(`({path:location.pathname,ready:document.readyState,title:document.title,policy:!!document.querySelector('.privacy-copy'),formDisabled:document.querySelector('#registro fieldset')?.disabled})`), runtimeErrors, failedAssets);
        throw Error(label);
    };

    const thankYou = async (kind, patch = {}) => {
        confirmationKind = kind;
        fixturePage = structuredClone(confirmations[kind]);
        Object.assign(fixturePage.props, patch);
        const url = `https://jakawi.club/__confirmation/${kind}?check=${++navigation}`;
        await call('Page.navigate', { url });
        await until(`location.href===${JSON.stringify(url)}&&document.readyState==='complete'&&!!document.querySelector('.confirmation-page')`, 'Confirmation renders');
        await evaluate('document.fonts.ready');
        if (fixturePage.props.confirmation.video?.youtube_url) await until(`!!window.__vslOptions&&document.querySelector('[data-vsl-state]').dataset.vslState!=='initializing'`, 'Official player ready');
        return url;
    };
    const signal = async (data, repeat = 1) => evaluate(`(()=>{for(let i=0;i<${repeat};i++)window.__vslOptions.events.onStateChange({data:${data}})})()`);
    const screenshot = async name => {
        const { cssContentSize } = await call('Page.getLayoutMetrics');
        const { data } = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: cssContentSize.width, height: cssContentSize.height, scale: 1 } });
        await writeFile(`${output}/${live ? 'live-' : ''}${name}.png`, Buffer.from(data, 'base64'));
    };
    for (const width of (process.argv.includes('--events-only') ? [] : widths)) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
        for (const [name, page] of Object.entries(receipts)) {
            await thankYou('campaign-' + name);
            const expected = page.props.confirmation.whatsapp_group_url;
            const view = await evaluate(`(()=>{
                const frame=document.querySelector('.vsl-frame')?.getBoundingClientRect();
                const link=document.querySelector('.confirmation-whatsapp-cta');
                return {header:!!document.querySelector('header,nav,footer'),overflow:document.documentElement.scrollWidth>innerWidth,
                    title:document.querySelector('h1').textContent,align:getComputedStyle(document.querySelector('main')).textAlign,
                    eyebrow:document.querySelector('.eyebrow').textContent,email:!!document.querySelector('.confirmation-email svg path'),
                    links:[...document.querySelectorAll('a')].map(a=>a.href),secondary:[...document.querySelectorAll('main > button,main > a,[role=timer],#personal-link')].length,
                    ratio:frame?frame.width/frame.height:null,cta:link?{label:link.textContent,target:link.target,rel:link.rel,color:getComputedStyle(link).backgroundColor,
                    animation:getComputedStyle(link).animationName,iterations:getComputedStyle(link).animationIterationCount}:null,
                    centered:[...document.querySelector('main').children].every(e=>{const r=e.getBoundingClientRect();return Math.abs(r.left+r.width/2-innerWidth/2)<2})};})()`);
            assert(!view.header && !view.overflow && view.email && view.centered);
            assert.equal(view.align, 'center'); assert.equal(view.eyebrow, 'REGISTRO RECIBIDO');
            assert.equal(view.title, '¡Felicidades por dar el siguiente paso!');
            assert.deepEqual(view.links, expected ? [expected] : []); assert.equal(view.secondary, 0);
            if (expected) {
                assert.equal(view.cta.label, 'UNIRME AL GRUPO DE WHATSAPP');
                assert.equal(view.cta.target, '_blank'); assert.equal(view.cta.rel, 'noopener noreferrer');
                assert.equal(view.cta.color, 'rgb(37, 211, 102)');
                assert.equal(view.cta.animation, 'confirmation-whatsapp-bounce'); assert.equal(view.cta.iterations, 'infinite');
            }
            if (page.props.confirmation.video.youtube_url) assert(Math.abs(view.ratio - .75) < .002);
            else assert.equal(view.ratio, null);
            assert(!await evaluate(`document.querySelector('main').textContent.includes('Solicitar otro enlace de acceso')`));
            if (name === 'custom-inherit') await screenshot(`confirmation-${width}`);
            record({ name: 'centered-single-cta-email-video-responsive', campaign: name, width, group: expected, passed: true });
        }
    }
    await thankYou('campaign-custom-inherit');
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.confirmation-whatsapp-cta')).animationName`), 'none');
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    record({ name: 'bounce-honors-reduced-motion', passed: true });

    const custom = structuredClone(confirmations['campaign-custom-inherit'].props.confirmation);
    custom.content = { title: '¡Bienvenido a nuestra campaña!', message: 'Mensaje exclusivo de esta campaña.', instructions: 'Instrucciones editoriales propias.', cta_label: 'Texto anterior' };
    await thankYou('campaign-custom-inherit', { confirmation: custom, signed_in: true, referral_url: 'https://jakawi.club/?ref=FIXTURE', can_manage_landings: false });
    assert.equal(await evaluate(`document.querySelector('h1').textContent`), custom.content.title);
    assert(await evaluate(`document.querySelector('.confirmation-message').textContent==='Mensaje exclusivo de esta campaña.'&&document.querySelector('.confirmation-email').textContent.includes('Instrucciones editoriales propias.')`));
    assert.equal(await evaluate(`document.querySelectorAll('a').length`), 1);
    record({ name: 'preserve-custom-editorial-copy', passed: true });

    await thankYou('confirmed');
    assert.equal(await evaluate(`document.querySelectorAll('a').length`), 1);
    assert(!await evaluate(`!!document.querySelector('#personal-link')||document.body.textContent.includes('Ir a mi cuenta')`));
    record({ name: 'activated-receipt-single-cta', passed: true });

    // No synthetic ended event: deliver provider state 0 to the real official engine,
    // which emits its typed ended event through LandingVideo into Registered.
    for (const kind of ['campaign-default-inherit', 'campaign-custom-inherit', 'campaign-default-custom']) {
        const url = await thankYou(kind);
        const before = navigationRequests.length;
        await signal(1);
        await evaluate(`window.__vslPlayer.time=90`);
        await signal(2);
        await pause(600);
        assert.equal(await evaluate('location.href'), url);
        assert.equal(navigationRequests.length, before);
        record({ name: 'no-redirect-on-mount-progress-or-pause', campaign: kind, passed: true });
        await signal(1);
        const expected = confirmations[kind].props.confirmation.whatsapp_group_url;
        blockGroupNavigation = true;
        await signal(0, 4);
        await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='ended'`, 'Provider ended reaches official player');
        await pause(150);
        assert.equal(navigationRequests.length, before + 1, 'Repeated ended navigates exactly once');
        assert.equal(navigationRequests.at(-1), expected);
        assert.equal(groupNavigations.at(-1), expected, 'Browser requests the receipt destination');
        assert.equal(await evaluate(`document.querySelector('.confirmation-whatsapp-cta').href`), expected, 'Manual CTA survives pending navigation');
        blockGroupNavigation = false;
        record({ name: 'provider-ended-browser-navigation-once', campaign: kind, destination: expected, passed: true });
    }
    for (const mode of ['preview', 'admin', 'hidden']) {
        const confirmation = structuredClone(confirmations['campaign-custom-inherit'].props.confirmation);
        if (mode === 'preview') confirmation.preview = true;
        if (mode === 'hidden') confirmation.whatsapp_group_url = null;
        const url = await thankYou('campaign-custom-inherit', { confirmation, can_manage_landings: mode === 'admin' });
        const before = navigationRequests.length;
        await signal(1); await signal(0, 2); await pause(150);
        assert.equal(await evaluate('location.href'), url);
        assert.equal(navigationRequests.length, before);
        if (mode === 'preview') assert(await evaluate(`document.querySelector('.confirmation-whatsapp-cta').disabled&&document.querySelectorAll('a').length===0`));
        if (mode === 'hidden') assert.equal(await evaluate(`document.querySelectorAll('.confirmation-whatsapp-cta').length`), 0);
        record({ name: 'no-ended-redirect-' + mode, passed: true });
    }
    const errorUrl = await thankYou('campaign-custom-inherit');
    const beforeError = navigationRequests.length;
    await signal(1);
    await evaluate(`window.__vslOptions.events.onError({data:150})`);
    await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='error'`, 'Provider failure remains an error');
    assert.equal(navigationRequests.length, beforeError);
    assert.equal(await evaluate('location.href'), errorUrl);
    // Even a late provider ended after failure cannot turn the error into completion.
    await signal(0);
    assert.equal(navigationRequests.length, beforeError);
    assert.equal(await evaluate(`document.querySelector('.confirmation-whatsapp-cta').href`), confirmations['campaign-custom-inherit'].props.confirmation.whatsapp_group_url);
    record({ name: 'error-keeps-manual-cta-without-redirect', passed: true });

    // A video's reported duration and the independent decorative bar never navigate.
    await thankYou('campaign-custom-inherit');
    await signal(1);
    await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='playing'&&parseFloat(document.querySelector('[data-vsl-progress] > div').style.transform.slice(7))>0`, 'Decorative clock starts');
    const beforeDummy = navigationRequests.length;
    await evaluate(`(()=>{const clock=performance.now.bind(performance);performance.now=()=>clock()+96000;const wall=Date.now.bind(Date);Date.now=()=>wall()+96000;})()`);
    await until(`parseFloat(document.querySelector('[data-vsl-progress] > div').style.transform.slice(7))>=.96`, 'Decorative bar advances independently');
    await pause(600);
    assert.equal(navigationRequests.length, beforeDummy);
    record({ name: 'decorative-progress-and-elapsed-time-never-redirect', passed: true });

    fixturePage = structuredClone(fixture.main);
    const mainUrl = 'https://jakawi.club/__registration/main-vsl';
    const beforeMain = navigationRequests.length;
    await call('Page.navigate', { url: mainUrl });
    await until(`location.href===${JSON.stringify(mainUrl)}&&!!window.__vslOptions&&document.querySelector('[data-vsl-state]')?.dataset.vslState==='playing'`, 'Main VSL loads');
    await signal(0, 2);
    assert.equal(navigationRequests.length, beforeMain);
    record({ name: 'main-vsl-never-redirects', passed: true });

    assert.deepEqual(runtimeErrors, []); assert.deepEqual(failedAssets, []);
    assert(!metrics.some(event => event.event === 'registration_completed'));
    const report = { stage: live ? 'HTTPS LIVE assets' : 'build-next intercepted in browser', viewports: widths, checks,
        provider: 'YouTube IFrame API intercepted; real official engine and event wiring', physical_playback_tested: false,
        navigation: 'Real window.location.assign; WhatsApp requests answered 204 inside browser; no invitation opened',
        group_navigation_requests: navigationRequests, group_requests_intercepted: groupNavigations, runtime_errors: runtimeErrors, failed_assets: failedAssets,
        no_live_accounts: true, all_browser_mutations_intercepted: true };
    await writeFile(`${output}/${live ? 'live-browser' : 'browser'}.json`, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ passed_groups: checks.length, viewports: widths, live, no_live_accounts: true }));
} catch (error) {
    console.error(browserMessages.find(message => message.includes('FATAL')) || 'Browser assertion failed');
    throw error;
} finally { stopping = true; socket?.close(); chrome.kill('SIGTERM'); }
