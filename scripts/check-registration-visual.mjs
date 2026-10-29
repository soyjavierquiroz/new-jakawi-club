// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Template/open states use browser-only fixtures; every browser mutation is intercepted.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const widths = [390, 1440];
const live = process.argv.includes('--live');
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
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });


    let html = await (await fetch('https://jakawi.club/')).text();
    const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const original = JSON.parse(html.match(pattern)[2]);
    assert.equal(original.props.launch.registration_open, false, 'Real registration remains closed without SMTP');
    let nextBuildHtml = value => value;
    if (!live) {
        const before = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
        const after = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
        nextBuildHtml = value => {
            for (const [name, entry] of Object.entries(before)) {
                const next = after[name];
                if (!next) continue;
                value = value.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
                for (let i = 0; i < (entry.css || []).length; i++) value = value.replaceAll(`/build/${entry.css[i]}`, `/build/${next.css[i]}`);
            }
            return value;
        };
        html = nextBuildHtml(html);
    }
    const submissions = [], checks = [], metrics = [];
    const referral = 'BROWSERTEST1A';
    let heldSubmission, fixturePage, navigation = 0;
    const fulfill = (event, status, type, body = '') => call('Fetch.fulfillRequest', {
        requestId: event.requestId, responseCode: status,
        responseHeaders: [{ name: 'Content-Type', value: type }], body: Buffer.from(body).toString('base64'),
    });
    on('Fetch.requestPaused', async event => {
        if (stopping) return;
        try {
            const url = new URL(event.request.url);
            if (event.request.method !== 'GET') {
                if (url.pathname === '/registro') {
                    submissions.push(JSON.parse(event.request.postData));
                    heldSubmission = event.requestId;
                    return; // Hold to inspect processing before returning accessible errors.
                }
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
    await call('Fetch.enable', { patterns: [{ urlPattern: 'https://jakawi.club/*' }] });
    const until = async (expression, label) => {
        for (let i = 0; i < 100; i++) {
            try { if (await evaluate(expression)) return; } catch {}
            await pause(75);
        }
        console.error('Navigation diagnostics', await evaluate(`({path:location.pathname,ready:document.readyState,title:document.title,policy:!!document.querySelector('.privacy-copy'),formDisabled:document.querySelector('#registro fieldset')?.disabled})`), runtimeErrors, failedAssets);
        throw Error(label);
    };
    const navigate = async (template = 'LEAD_VSL', open = false, actual = false) => {
        fixturePage = structuredClone(original);
        fixturePage.props.errors = {};
        fixturePage.props.launch.registration_open = open;
        fixturePage.props.landing.content.presentation.template = template;
        fixturePage.props.referral_code = referral;
        const url = actual ? `https://jakawi.club/?registration_check=${++navigation}` : `https://jakawi.club/__registration/${template}?check=${++navigation}`;
        await call('Page.navigate', { url });
        await until(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && !!document.querySelector('#registro')`, 'Landing loads');
        await evaluate('document.fonts.ready');
    };
    const focusForm = async () => {
        await evaluate(`document.querySelector('#registro').scrollIntoView({block:'center',behavior:'instant'})`);
        await pause(100);
    };
    const screenshot = async name => {
        const { data } = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        await writeFile(`${output}/${name}.png`, Buffer.from(data, 'base64'));
    };
    const fill = async (selector, value) => {
        await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.focus();e.select()})()`);
        await call('Input.insertText', { text: value });
    };
    const click = async selector => { await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); await pause(50); };
    const key = async (key, code = key, n = 0) => {
        await call('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: n });
        await call('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: n });
    };
    const inspectForm = async () => {
        const data = await evaluate(`(()=>{
            const fields=['name','phone','email'].map(id=>{const e=document.getElementById(id),l=e.labels[0],r=l.getBoundingClientRect();return {id,placeholder:e.placeholder,label:l.textContent,associated:l.control===e,hidden:r.width<=1&&r.height<=1,autocomplete:e.autocomplete,inputMode:e.inputMode,required:e.required}});
            const button=document.querySelector('#registro button[type=submit]');
            return {fields,formCount:document.querySelectorAll('#registro form').length,overflow:document.documentElement.scrollWidth>innerWidth,
                privacyChecked:document.querySelector('#privacy').checked,privacyRequired:document.querySelector('#privacy').required,
                privacyText:document.querySelector('.checkbox-label span').textContent,privacyUrl:document.querySelector('.checkbox-label a').href,
                country:document.querySelector('.phone-country').getAttribute('aria-label'),flag:document.querySelector('.phone-country img').getAttribute('src'),
                cta:button.textContent,color:getComputedStyle(button).backgroundColor,
                warnings:/PRÓXIMAMENTE|preparando la apertura|registros (todavía|aún) están cerrados|formulario se habilitará|Cuando abramos/i.test(document.querySelector('.registration-section').textContent+document.querySelector('.hero-copy').textContent)};
        })()`);
        assert.deepEqual(data.fields.map(f => f.placeholder), ['Tu nombre completo', 'WhatsApp', 'Tu correo electrónico']);
        assert(data.fields.every(f => f.associated && f.hidden && f.required), 'Associated labels are visually hidden; required rules remain');
        assert.deepEqual(data.fields.map(f => f.autocomplete), ['name', 'tel-national', 'email']);
        assert.equal(data.fields[1].inputMode, 'tel');
        assert.equal(data.formCount, 1); assert(!data.overflow && !data.warnings);
        assert(!data.privacyChecked && data.privacyRequired, 'Required consent needs affirmative acceptance');
        assert.equal(data.privacyText, 'Acepto el aviso de privacidad');
        assert.equal(data.privacyUrl, 'https://jakawi.club/privacidad');
        assert(data.country.includes('Bolivia +591') && data.flag.endsWith('/BO.svg'));
        assert.equal(data.cta, 'QUIERO SER PARTE DE JAKAWI');
        assert.equal(data.color, rgb('brand'));
        const ax = await call('Accessibility.getFullAXTree');
        for (const label of ['Nombre', 'Teléfono / WhatsApp', 'Correo electrónico']) {
            assert(ax.nodes.some(node => node.role?.value === 'textbox' && node.name?.value === label), `Accessible name: ${label}`);
        }
        return data;
    };
    for (const width of widths) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
        for (const template of ['LEAD_VSL', 'WEBINAR', 'WAITLIST']) {
            await navigate(template);
            const data = await inspectForm();
            assert(await evaluate(`document.querySelector('#registro fieldset').disabled&&document.querySelector('#registro button[type=submit]').disabled&&document.querySelector('#registration-status').textContent==='Registro no disponible.'`));
            const before = submissions.length;
            await evaluate(`document.querySelector('#registro form').requestSubmit()`);
            await pause(100);
            assert.equal(submissions.length, before, 'Closed form cannot send a registration');
            if (template === 'WAITLIST') assert.equal(await evaluate(`document.querySelectorAll('.vsl-player').length`), 0);
            else assert.equal(await evaluate(`document.querySelectorAll('.vsl-player').length`), 1, 'VSL preserved');
            await focusForm(); await screenshot(`closed-${template.toLowerCase()}-${width}`);
            checks.push({ name: 'compact-accessible-closed-form', width, template, fixture: true, fields: data.fields });
        }
        if (live) {
            await navigate('LEAD_VSL', false, true);
            await inspectForm();
            assert(await evaluate(`document.querySelector('#registro fieldset').disabled&&document.querySelector('#registro button[type=submit]').matches(':disabled')`));
            await focusForm(); await screenshot(`live-${width}`);
            checks.push({ name: 'actual-https-form', width, fixture: false, registration_open: false });
        }
    }
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
    await navigate('LEAD_VSL', true);
    await inspectForm();
    await evaluate(`scrollTo({top:document.querySelector('#como-funciona').offsetTop,behavior:'instant'})`);
    await until(`!document.querySelector('.mobile-sticky-cta').hidden`, 'Sticky CTA remains available outside the form');
    await click('[data-marketing-cta=sticky]');
    await until(`location.hash==='#registro'&&document.activeElement.id==='registro'&&document.querySelector('.mobile-sticky-cta').hidden`, 'Sticky focuses the shared form');
    await fill('#name', 'Persona de prueba'); await fill('#email', 'browser@example.invalid'); await fill('#phone', '1');
    await click('#privacy');
    const count = submissions.length;
    await evaluate(`document.querySelector('#registro form').requestSubmit()`);
    await until(`document.querySelector('#phone').getAttribute('aria-invalid')==='true'&&document.activeElement.id==='phone'`, 'Invalid national number gets an accessible local error');
    assert.equal(submissions.length, count);
    assert(await evaluate(`document.querySelector('#phone').getAttribute('aria-describedby').includes('phone-error')&&!!document.querySelector('#phone-error').textContent`));
    await click('.phone-country'); await until(`document.querySelector('dialog').open`, 'Country selector opens');
    await fill('#country-search', 'espana'); await until(`document.querySelectorAll('[role=option]').length===1`, 'Country search ignores accents');
    await key('Enter', 'Enter', 13); await fill('#phone', '612345678');
    assert.equal(await evaluate(`document.querySelector('input[name=phone]').value`), '+34612345678');
    assert.equal(await evaluate(`document.querySelector('#phone').placeholder`), 'WhatsApp');
    await click('.phone-country'); await fill('#country-search', '+591'); await key('Enter', 'Enter', 13);
    await fill('#phone', '+34612345678');
    assert(await evaluate(`document.querySelector('.phone-country').textContent.includes('+591')&&document.querySelector('input[name=phone]').value===''`), 'Foreign paste respects selected country');
    await fill('#phone', '70000000');
    assert.equal(await evaluate(`document.querySelector('input[name=phone]').value`), '+59170000000');
    await click('.phone-country'); await fill('#country-search', 'zzzz-no-country');
    await until(`document.querySelectorAll('[role=option]').length===0`, 'No-country result');
    await fill('#country-search', ''); await key('ArrowDown', 'ArrowDown', 40);
    assert.equal(await evaluate(`document.querySelector('#country-search').getAttribute('aria-activedescendant')`), 'country-option-1');
    await key('Tab', 'Tab', 9);
    assert(await evaluate(`document.querySelector('dialog').contains(document.activeElement)`), 'Dialog traps focus');
    await key('Escape', 'Escape', 27);
    assert(await evaluate(`!document.querySelector('dialog').open&&document.activeElement.matches('.phone-country')`));
    await click('#privacy'); // Withdraw the explicit acceptance made above.
    await evaluate(`document.querySelector('#registro form').requestSubmit()`); await pause(100);
    assert.equal(submissions.length, count, 'Unchecked required consent blocks submission');
    assert(await evaluate(`!document.querySelector('#privacy').checked&&!document.querySelector('#privacy').validity.valid`));
    await click('#privacy');
    await evaluate(`document.querySelector('#registro form').requestSubmit()`);
    await until(`document.querySelector('#registro button[type=submit]').getAttribute('aria-busy')==='true'`, 'Processing exposes busy state');
    assert(await evaluate(`document.querySelector('#registro button[type=submit]').disabled&&document.querySelector('#registro button[type=submit]').textContent==='Un momento…'&&document.querySelector('#registro fieldset').disabled`));
    assert(heldSubmission, 'Registration was intercepted before reaching LIVE');
    const payload = submissions.at(-1);
    assert.equal(payload.phone, '+59170000000'); assert.equal(payload.privacy, true);
    assert.equal(payload.referral_code, referral); assert.equal(payload.landing_id, original.props.landing.id);
    assert.deepEqual(Object.keys(payload).sort(), ['email', 'landing_id', 'name', 'phone', 'privacy', 'referral_code'], 'No marketing subscription is bundled into consent');
    const errors = { name: 'Escribe tu nombre.', email: 'Escribe un correo electrónico válido.', privacy: 'Debes aceptar el aviso de privacidad para continuar.' };
    const responsePage = { ...fixturePage, url: '/__registration/LEAD_VSL', props: { ...fixturePage.props, errors } };
    await call('Fetch.fulfillRequest', { requestId: heldSubmission, responseCode: 200,
        responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'X-Inertia', value: 'true' }],
        body: Buffer.from(JSON.stringify(responsePage)).toString('base64') });
    heldSubmission = undefined;
    await until(`!document.querySelector('#registro fieldset').disabled&&document.activeElement.id==='name'`, 'Server errors restore controls and focus the first invalid field');
    for (const id of ['name', 'email', 'privacy']) {
        assert(await evaluate(`document.getElementById('${id}').getAttribute('aria-invalid')==='true'&&document.getElementById('${id}').getAttribute('aria-describedby').includes('${id}-error')&&!!document.getElementById('${id}-error').textContent`));
    }
    await focusForm(); await screenshot('validation-errors-390');
    checks.push({ name: 'phone-country-search-keyboard-e164', fixture: true });
    checks.push({ name: 'consent-required-loading-errors-referral-attribution', fixture: true, submissions_intercepted: submissions.length });
    checks.push({ name: 'sticky-shared-form-navigation', fixture: true });
    await navigate('WAITLIST', true);
    await click('.checkbox-label a');
    await until(`location.pathname==='/privacidad'&&!!document.querySelector('.privacy-copy')`, 'Privacy link opens local policy');
    await call('Page.navigate', { url: 'https://jakawi.club/login' });
    await until(`location.pathname==='/login'&&document.readyState==='complete'&&!!document.querySelector('input[type=password]')`, 'Existing login loads');
    assert(await evaluate(`!!document.querySelector('input[type=email]')&&!!document.querySelector('button[type=submit]')`));
    checks.push({ name: 'privacy-link-and-existing-login', fixture: false });
    assert.deepEqual(runtimeErrors, []); assert.deepEqual(failedAssets, []);
    assert(!metrics.some(event => event.event === 'registration_completed'));
    const report = { stage: live ? 'LIVE assets and HTTPS page; open/template cases use browser fixtures' : 'build-next intercepted only in browser', viewports: widths, checks,
        runtime_errors: runtimeErrors, failed_assets: failedAssets, no_live_accounts: true, all_browser_mutations_intercepted: true, real_registration_open: false, smtp_delivery_tested: false };
    await writeFile(`${output}/${live ? 'live-browser' : 'browser'}.json`, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ passed_groups: checks.length, viewports: widths, live, real_registration_open: false, no_live_accounts: true }));
} catch (error) {
    console.error(browserMessages.find(message => message.includes('FATAL')) || 'Browser assertion failed');
    throw error;
} finally { stopping = true; socket?.close(); chrome.kill('SIGTERM'); }
