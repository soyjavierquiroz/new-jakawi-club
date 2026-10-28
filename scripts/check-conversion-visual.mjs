// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
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
    on('Network.loadingFailed', event => console.log('Browser request failed', event.type, event.errorText));
    on('Network.responseReceived', ({ response, type }) => {
        if (response.status >= 400 && ['Script', 'Stylesheet', 'Font', 'Image'].includes(type)) failedAssets.push({ url: response.url, status: response.status });
    });
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await call('Page.bringToFront');
    await call('Emulation.setFocusEmulationEnabled', { enabled: true });
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

    const html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const original = JSON.parse(html.match(pattern)[2]);
    const launch = original.props.launch;
    assert.equal(launch.registration_open, false);
    assert.equal(launch.video_url, null);
    assert.equal(launch.video_poster, null);
    const metrics = [], submissions = [], apiRequests = [];
    let heldSubmission;
    const fixtures = {
        registration: { ...launch, registration_open: true },
        video: { ...launch, video_url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE' },
        'video-error': { ...launch, video_url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE' },
    };
    const apiFixture = `window.__vslPlays=0;window.YT={Player:class{constructor(mount,options){window.__vslOptions=options;this.muted=true;this.time=0;const iframe=document.createElement('iframe');iframe.src='about:blank';mount.replaceWith(iframe);window.__vslPlayer=this;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){window.__vslPlays++;}pauseVideo(){window.__vslOptions.events.onStateChange({data:2});}seekTo(t){this.time=t;}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return 100;}setVolume(){}getCurrentTime(){return this.time;}getDuration(){return 90;}destroy(){document.querySelector('.vsl-host iframe')?.remove();}}};window.onYouTubeIframeAPIReady?.();`;
    on('Fetch.requestPaused', async event => {
        try {
            const url = new URL(event.request.url);
            const fulfill = async (status, contentType, body = '') => call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: status, responseHeaders: [{ name: 'Content-Type', value: contentType }], body: Buffer.from(body).toString('base64') });
            if (url.pathname === '/medicion') { metrics.push(JSON.parse(event.request.postData)); await fulfill(204, 'application/json'); return; }
            if (url.pathname === '/registro') { heldSubmission = event.requestId; submissions.push(JSON.parse(event.request.postData)); return; }
            if (url.hostname === 'www.youtube.com') {
                apiRequests.push(event.request.url);
                if (await evaluate(`location.pathname.endsWith('video-error')`)) await call('Fetch.failRequest', { requestId: event.requestId, errorReason: 'ConnectionFailed' });
                else await fulfill(200, 'application/javascript', apiFixture);
                return;
            }
            const name = url.pathname.split('/').at(-1);
            assert(fixtures[name]);
            const page = { ...original, url: url.pathname, props: { ...original.props, errors: {}, launch: fixtures[name] } };
            const body = html.replace(pattern, (_, open, data, close) => open + JSON.stringify(page).replaceAll('<', '\\u003c') + close);
            await fulfill(200, 'text/html; charset=utf-8', body);
        } catch (error) { runtimeErrors.push(error.message); }
    });
    await call('Fetch.enable', { patterns: [
        { urlPattern: 'https://jakawi.club/__conversion/*', requestStage: 'Request' },
        { urlPattern: 'https://jakawi.club/registro', requestStage: 'Request' },
        { urlPattern: 'https://jakawi.club/medicion', requestStage: 'Request' },
        { urlPattern: 'https://www.youtube.com/iframe_api', requestStage: 'Request' },
    ] });
    const until = async (expression, message) => {
        let result = false;
        for (let i = 0; i < 100; i++) {
            try { result = await evaluate(expression); } catch { /* Navigation context. */ }
            if (result) break;
            await pause(75);
        }
        assert(result, message);
        return result;
    };
    const navigate = async path => {
        console.log('Check', path);
        const url = 'https://jakawi.club' + path;
        await call('Page.navigate', { url });
        await until(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && !!document.querySelector('h1')`, `Loaded ${path}`);
        await evaluate(`document.fonts.ready.then(() => true)`);
    };
    const key = async (key, code = key, windowsVirtualKeyCode = 0) => {
        await call('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode });
        await call('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode });
    };
    const fill = async (selector, value) => {
        await evaluate(`(()=>{const input=document.querySelector(${JSON.stringify(selector)});input.focus();input.select();})()`);
        await call('Input.insertText', { text: value });
    };
    const screenshot = async name => {
        const { data } = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(`${output}/${name}.png`, Buffer.from(data, 'base64'));
    };
    const checks = [];
    for (const width of [390, 768, 1440]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
        await navigate('/');
        const result = await evaluate(`(()=>{
            const frame=document.querySelector('.vsl-frame').getBoundingClientRect();
            const css=getComputedStyle(document.documentElement);
            return { overflow:document.documentElement.scrollWidth>innerWidth, h1:document.querySelector('h1').textContent, frameRatio:frame.width/frame.height, frameWidth:frame.width, fieldsetDisabled:document.querySelector('#registro fieldset').disabled, country:document.querySelector('.phone-country').textContent, videoState:document.querySelector('[data-vsl-state]').dataset.vslState, youtube:!!document.querySelector('iframe,script[src*="youtube"]'), formCount:document.querySelectorAll('#registro form').length, targets:[...document.querySelectorAll('[data-marketing-cta]')].map(a=>a.hash), coral:getComputedStyle(document.querySelector('.btn-primary')).backgroundColor, bodyFont:getComputedStyle(document.body).fontFamily, inputFont:getComputedStyle(document.querySelector('#phone')).fontFamily, brokenImages:[...document.images].filter(i=>i.getClientRects().length && i.getBoundingClientRect().top<innerHeight && i.getBoundingClientRect().bottom>0 && (!i.complete||!i.naturalWidth)).length, reduced:matchMedia('(prefers-reduced-motion: reduce)').matches, scroll:css.scrollBehavior, spinnerAnimation:getComputedStyle(document.querySelector('.vsl-frame')).animationName };
        })()`);
        assert(!result.overflow); assert(Math.abs(result.frameRatio - 16 / 9) < .02); assert(result.frameWidth > 300);
        assert(result.fieldsetDisabled); assert(result.country.includes('+591')); assert.equal(result.videoState, 'unavailable'); assert(!result.youtube);
        assert.equal(result.formCount, 1); assert(result.targets.every(target => target === '#registro')); assert.equal(result.coral, rgb('brand'));
        assert(result.bodyFont.includes('Montserrat')); assert(result.inputFont.includes('Instrument Sans')); assert.equal(result.brokenImages, 0); assert(result.reduced); assert.equal(result.scroll, 'auto');
        assert(result.h1.includes('Tu ciudad tiene'));
        assert(await evaluate(`document.querySelector('.phone-country img').getAttribute('src') === '/marketing/flags/BO.svg'`));
        checks.push({ name: 'landing', width, ...result });
        if (width !== 768) await screenshot(`landing-${width}`);
        await evaluate(`document.querySelector('[data-marketing-cta="hero"]').click()`);
        await until(`location.hash === '#registro'`, 'CTA points to the only registration form');
        await until(`document.querySelector('.phone-country img').complete && document.querySelector('.phone-country img').naturalWidth > 0`, 'Local Bolivia flag loads when the form is visible');
        await navigate('/__conversion/registration');
        await evaluate(`document.querySelector('.phone-country').click()`);
        assert(await evaluate(`document.querySelector('dialog').open && document.activeElement.id === 'country-search'`));
        await fill('#country-search', 'espana');
        await until(`document.querySelectorAll('[role="option"]').length === 1`, 'Accent-insensitive country search');
        assert(await evaluate(`(()=>{const d=document.querySelector('dialog');const r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&d.scrollWidth<=d.clientWidth;})()`), 'Country dialog fits the viewport');
        await until(`document.querySelector('[role="option"] img').naturalWidth > 0`, 'Country search result flag loads locally');
        if (width !== 768) await screenshot(`country-selector-${width}`);
        await key('Enter', 'Enter', 13);
        assert(await evaluate(`!document.querySelector('dialog').open && document.querySelector('.phone-country').textContent.includes('+34')`));
        await fill('#phone', '612345678');
        assert(await evaluate(`document.querySelector('input[name="phone"]').value === '+34612345678'`));
        await evaluate(`document.querySelector('.phone-country').click()`);
        await fill('#country-search', '+591'); await key('Enter', 'Enter', 13);
        await fill('#phone', '+34612345678');
        assert(await evaluate(`document.querySelector('.phone-country').textContent.includes('+591') && document.querySelector('input[name="phone"]').value === ''`));
        await fill('#phone', '70000000');
        assert(await evaluate(`document.querySelector('input[name="phone"]').value === '+59170000000'`));
        await evaluate(`document.querySelector('.phone-country').click()`);
        await fill('#country-search', 'zzzz-no-country');
        assert(await evaluate(`document.querySelectorAll('[role="option"]').length === 0 && document.querySelector('dialog [role="status"]').textContent.includes('No encontramos')`));
        await fill('#country-search', ''); await key('ArrowDown', 'ArrowDown', 40);
        assert(await evaluate(`document.querySelector('#country-search').getAttribute('aria-activedescendant') === 'country-option-1'`));
        await key('Tab', 'Tab', 9);
        assert(await evaluate(`document.querySelector('dialog').contains(document.activeElement)`), 'Native dialog traps focus');
        await key('Escape', 'Escape', 27);
        assert(await evaluate(`!document.querySelector('dialog').open && document.activeElement.matches('.phone-country')`));
        await fill('#name', 'Persona de prueba'); await fill('#email', 'browser@example.invalid');
        await evaluate(`document.querySelector('#privacy').click();document.querySelector('#registro form').requestSubmit()`);
        await until(`document.querySelector('#registro fieldset').disabled`, 'Form locks while sending');
        for (let i = 0; !heldSubmission && i < 50; i++) await pause(75);
        assert(heldSubmission); assert.equal(submissions.at(-1).phone, '+59170000000');
        assert.equal(submissions.at(-1).privacy, true);
        const responsePage = { ...original, url: '/__conversion/registration', props: { ...original.props, launch: fixtures.registration, errors: { email: 'Revisa tu correo electrónico.' } } };
        await call('Fetch.fulfillRequest', { requestId: heldSubmission, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'X-Inertia', value: 'true' }], body: Buffer.from(JSON.stringify(responsePage)).toString('base64') });
        heldSubmission = undefined;
        await until(`document.querySelector('#email').getAttribute('aria-invalid') === 'true' && !document.querySelector('#registro fieldset').disabled`, 'Backend errors mark and unlock the form');
        await until(`document.activeElement.id === 'email'`, 'Focus returns to the invalid field after controls unlock');
        checks.push({ name: 'phone-country-search-keyboard-e164-form-error', width, passed: true });
        if (width !== 768) await screenshot(`form-error-${width}`);
        await navigate('/__conversion/video');
        const requestsBefore = apiRequests.length;
        assert(await evaluate(`!document.querySelector('iframe,script[src*="youtube"]')`), 'YouTube waits for click');
        await evaluate(`document.querySelector('.vsl-play').click()`);
        await until(`window.__vslPlays === 1`, 'Click loads API and requests playback');
        assert.equal(apiRequests.length, requestsBefore + 1);
        assert(await evaluate(`document.querySelector('[data-vsl-state]').dataset.vslState !== 'playing' && window.__vslOptions.host === 'https://www.youtube-nocookie.com' && window.__vslOptions.playerVars.autoplay === 0`));
        const startsBefore = metrics.filter(event => event.event === 'video_start').length;
        await evaluate(`window.__vslOptions.events.onStateChange({data:1})`);
        await until(`document.querySelector('[data-vsl-state]').dataset.vslState === 'playing'`, 'Provider playing event drives UI');
        for (let i = 0; metrics.filter(event => event.event === 'video_start').length === startsBefore && i < 50; i++) await pause(75);
        assert(metrics.filter(event => event.event === 'video_start').length > startsBefore);
        await evaluate(`document.querySelector('.vsl-controls button').click()`);
        await until(`document.querySelector('[data-vsl-state]').dataset.vslState === 'paused'`, 'VSL pause control');
        await evaluate(`window.__vslOptions.events.onError({data:150})`);
        await until(`!!document.querySelector('.vsl-error[role="alert"]')`, 'Video failure feedback and retry');
        if (width !== 768) await screenshot(`video-error-${width}`);
        checks.push({ name: 'lazy-vsl-real-event-contract-controls-error', width, passed: true, provider: 'browser API fixture; no approved LIVE video' });
    }
    await navigate('/__conversion/video-error');
    await evaluate(`document.querySelector('.vsl-play').click()`);
    await until(`document.querySelector('[data-vsl-state]').dataset.vslState === 'error'`, 'API load failure is recoverable');
    assert(await evaluate(`document.querySelector('.vsl-play').textContent.includes('Intentar de nuevo')`));
    assert(metrics.some(event => event.event === 'landing_visit')); assert(metrics.some(event => event.event === 'cta_click'));
    assert(!metrics.some(event => event.event === 'registration_completed'), 'Conversion never originates from a browser click');
    assert.deepEqual(runtimeErrors, []); assert.deepEqual(failedAssets, []);
    await writeFile(`${output}/visual-validation.json`, JSON.stringify({ checks, browser_api_fixtures: true, no_live_test_accounts: true, live_registration_closed: true, api_loading_error_retry: true, metrics_vocabulary_verified: true, runtime_errors: runtimeErrors, failed_assets: failedAssets }, null, 2) + '\n');
    console.log(`${checks.length} grouped responsive checks passed: landing, keyboard/search/country/E.164/form states and VSL lifecycle; 390, 768, 1440 px.`);
} catch (error) {
    console.error(browserMessages.find(message => message.includes('FATAL')) || 'Browser check failed; see assertion below.');
    throw error;
} finally { socket?.close(); chrome.kill('SIGTERM'); }
