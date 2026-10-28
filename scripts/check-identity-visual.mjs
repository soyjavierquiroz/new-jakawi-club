// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
await mkdir(output, { recursive: true });
const reference = await readFile('/reference/theme.css', 'utf8');
const token = name => reference.match(new RegExp(`--${name}:\\s*(#[a-fA-F0-9]+);`))[1].toLowerCase();
const rgb = name => `rgb(${token(name).slice(1).match(/../g).map(channel => parseInt(channel, 16)).join(', ')})`;
const addresses = await Promise.all(['jakawi.club', 'jakawi.com'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
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
    const payloadPattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const original = JSON.parse(html.match(payloadPattern)[2]);
    const launch = original.props.launch;
    assert.equal(launch.registration_open, false, 'LIVE registration remains closed');
    const fixtures = {
        activate: { component: 'Auth', props: { mode: 'activate', email: 'visual@example.invalid', token: 'browser-only' } },
        reset: { component: 'Auth', props: { mode: 'reset', email: 'visual@example.invalid', token: 'browser-only' } },
        dashboard: { component: 'Dashboard', props: { signed_in: true, member: { name: 'Persona de prueba', email: 'visual@example.invalid', phone: '+591 70000000', city: 'Cochabamba' }, referral_url: 'https://jakawi.club/r/visual', invitees_count: 0, launch } },
        pending: { component: 'Registered', props: { referral_url: null, launch } },
        registered: { component: 'Registered', props: { referral_url: 'https://jakawi.club/r/visual', launch } },
        community: { component: 'Registered', props: { referral_url: null, launch: { ...launch, whatsapp_url: 'https://chat.whatsapp.com/browser-only-fixture' } } },
        registration: { component: 'Welcome', props: { launch: { ...launch, registration_open: true }, referral_code: 'visual', referral_preference: null } },
    };
    let heldSubmission;
    on('Fetch.requestPaused', async event => {
        try {
            if (event.request.method === 'POST') { heldSubmission = event.requestId; return; }
            const name = new URL(event.request.url).pathname.split('/').at(-1);
            const fixture = fixtures[name];
            assert(fixture, `Known browser fixture ${name}`);
            const page = { ...original, ...fixture, url: `/__visual/${name}`, props: { ...original.props, errors: {}, ...fixture.props } };
            const body = html.replace(payloadPattern, (_, open, data, close) => open + JSON.stringify(page).replaceAll('<', '\\u003c') + close);
            await call('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }], body: Buffer.from(body).toString('base64') });
        } catch (error) { runtimeErrors.push(error.message); }
    });
    await call('Fetch.enable', { patterns: [{ urlPattern: 'https://jakawi.club/__visual/*', requestStage: 'Request' }, { urlPattern: 'https://jakawi.club/registro', requestStage: 'Request' }] });
    const navigate = async url => {
        console.log('Check', url.replace('/__visual/', '/browser-fixture/'));
        await call('Page.navigate', { url });
        let ready = false;
        for (let i = 0; i < 100; i++) {
            try { ready = await evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && !!document.querySelector('h1')`); }
            catch { /* Navigation replaces the execution context. */ }
            if (ready) break;
            await pause(100);
        }
        assert(ready, `Page loads: ${url}`);
        await evaluate(`Promise.all([document.fonts.load('800 20px Montserrat'), document.fonts.load('400 16px "Instrument Sans"'), document.fonts.ready]).then(() => true)`);
        await pause(50);
    };
    const screenshot = async name => {
        const { data } = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(`${output}/${name}.png`, Buffer.from(data, 'base64'));
    };
    const checks = [];
    const routes = [
        ['landing', '/', false], ['login', '/login', false], ['recovery', '/recuperar-password', false], ['privacy', '/privacidad', false],
        ...Object.keys(fixtures).map(key => [key, `/__visual/${key}`, true]),
    ];
    for (const width of [390, 768, 1440]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        for (const [name, path, fixture] of routes) {
            await navigate('https://jakawi.club' + path);
            const result = await evaluate(`(() => {
                const css = getComputedStyle(document.documentElement);
                const input = document.querySelector('.input');
                const cta = document.querySelector('.btn-primary');
                const card = document.querySelector('.feature-card, .member-card');
                return {
                    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                    bodyFont: getComputedStyle(document.body).fontFamily,
                    inputFont: input && getComputedStyle(input).fontFamily,
                    inputSize: input && getComputedStyle(input).fontSize,
                    inputHeight: input && input.getBoundingClientRect().height,
                    background: getComputedStyle(document.documentElement).backgroundColor,
                    brand: css.getPropertyValue('--brand').trim(),
                    cardRadius: card && getComputedStyle(card).borderRadius,
                    primaryForeground: cta && getComputedStyle(cta).color,
                    primaryBackground: cta && getComputedStyle(cta).backgroundColor,
                    primarySize: cta && getComputedStyle(cta).fontSize,
                    brokenImages: [...document.images].filter(image => !image.complete || !image.naturalWidth).length,
                    registrationDisabled: document.querySelector('#registro fieldset')?.disabled ?? null,
                    fontsLoaded: document.fonts.check('800 20px Montserrat') && document.fonts.check('400 16px "Instrument Sans"'),
                };
            })()`);
            assert(!result.overflow, `${name} ${width}: no horizontal overflow`);
            assert(result.bodyFont.includes('Montserrat'), `${name}: official typography`);
            assert.equal(result.background, rgb('cream'));
            assert.equal(result.brand, token('brand'));
            assert.equal(result.brokenImages, 0);
            assert(result.fontsLoaded);
            if (result.inputFont) { assert(result.inputFont.includes('Instrument Sans')); assert.equal(result.inputSize, '16px'); assert(result.inputHeight >= 48); }
            if (result.cardRadius) assert.equal(result.cardRadius, '22px');
            if (result.primaryForeground) { assert.equal(result.primaryForeground, rgb('palette-light-surface-raised')); assert.equal(result.primaryBackground, rgb('brand')); assert(parseFloat(result.primarySize) >= 19); }
            if (name === 'landing') assert.equal(result.registrationDisabled, true);
            if (name === 'registration') assert.equal(result.registrationDisabled, false);
            checks.push({ name, width, fixture, ...result });
            if ([390, 1440].includes(width) && ['landing', 'login', 'dashboard', 'registered', 'privacy'].includes(name)) await screenshot(`${name}-${width}`);
        }
        await navigate('https://jakawi.com/');
        const official = await evaluate(`({ font: getComputedStyle(document.querySelector('[data-marketing-theme]')).fontFamily, background: getComputedStyle(document.querySelector('[data-marketing-theme]')).backgroundColor, overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth })`);
        assert(official.font.includes('Montserrat')); assert.equal(official.background, rgb('cream')); assert(!official.overflow);
        checks.push({ name: 'official-jakawi-com', width, ...official });
        if ([390, 1440].includes(width)) await screenshot(`official-${width}`);
    }
    await navigate('https://jakawi.club/login');
    await evaluate(`document.querySelector('#auth-email').focus()`);
    await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    const focus = await evaluate(`({ element: document.activeElement.id, visible: document.activeElement.matches(':focus-visible'), outline: getComputedStyle(document.activeElement).outlineStyle, width: getComputedStyle(document.activeElement).outlineWidth, reduced: matchMedia('(prefers-reduced-motion: reduce)').matches, scroll: getComputedStyle(document.documentElement).scrollBehavior, transition: getComputedStyle(document.querySelector('.btn')).transitionDuration })`);
    assert.equal(focus.outline, 'solid'); assert.equal(focus.width, '2px'); assert(focus.reduced); assert.equal(focus.scroll, 'auto'); assert.equal(focus.transition, '0s');
    await navigate('https://jakawi.club/__visual/registration');
    for (const [selector, text] of [['#name', 'Persona de prueba'], ['#phone', '+591 70000000'], ['#email', 'visual@example.invalid']]) {
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);
        await call('Input.insertText', { text });
    }
    await evaluate(`document.querySelector('input[name="privacy"]').click(); document.querySelector('#registro form').requestSubmit()`);
    for (let i = 0; !heldSubmission && i < 50; i++) await pause(100);
    assert(heldSubmission, 'Submission intercepted before reaching LIVE');
    assert(await evaluate(`document.querySelector('#registro fieldset').disabled && document.querySelector('#registro button').getAttribute('aria-busy') === 'true'`), 'Loading locks registration controls');
    const responsePage = { ...original, component: 'Welcome', url: '/__visual/registration', props: { ...original.props, ...fixtures.registration.props, errors: { email: 'Revisa tu correo electrónico.' } } };
    await call('Fetch.fulfillRequest', { requestId: heldSubmission, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'X-Inertia', value: 'true' }], body: Buffer.from(JSON.stringify(responsePage)).toString('base64') });
    for (let i = 0; i < 50; i++) {
        if (await evaluate(`!!document.querySelector('#registro [role="alert"]')`)) break;
        await pause(100);
    }
    assert(await evaluate(`document.querySelector('#email').getAttribute('aria-invalid') === 'true' && !document.querySelector('#registro fieldset').disabled`), 'Error unlocks form and marks the field');
    assert.deepEqual(runtimeErrors, [], 'No JavaScript runtime errors'); assert.deepEqual(failedAssets, [], 'No failed assets');
    await writeFile(`${output}/visual-validation.json`, JSON.stringify({ checks, focus, browser_only_registration_loading_and_error: true, live_registration_closed: true, runtime_errors: runtimeErrors, failed_assets: failedAssets }, null, 2) + '\n');
    console.log(`${checks.length} responsive page checks passed; focus, reduced motion, registration loading/error and all assets passed. Protected states are browser-only fixtures.`);
} catch (error) {
    console.error(browserMessages.find(message => message.includes('FATAL')) || 'Browser check failed; see assertion below.');
    throw error;
} finally {
    socket?.close(); chrome.kill('SIGTERM');
}
