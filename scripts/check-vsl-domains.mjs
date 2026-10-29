// Observe the real production pages and provider. No provider fixtures or source changes.
import { spawn } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import { lookup } from 'node:dns/promises';
import assert from 'node:assert/strict';

const stage = process.argv.includes('--before') ? 'before' : 'live';
const selectedWidth = Number(process.argv.find(x => x.startsWith('--width='))?.split('=')[1]) || null;
const selectedTarget = process.argv.find(x => x.startsWith('--target='))?.split('=')[1] ?? null;
const outputName = selectedTarget ? `${stage}-${selectedTarget}-${selectedWidth}.json` : `${stage}-domains.json`;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const addresses = await Promise.all(['jakawi.com', 'jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', ['--headless=new', '--hide-scrollbars', '--no-sandbox', '--single-process', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', '--disable-extensions', '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/vsl-domains', '--no-first-run', '--no-default-browser-check'], { stdio: 'ignore' });
let socket;
const report = { recorded_at: new Date().toISOString(), stage, provider: 'real YouTube IFrame API; no provider requests intercepted', first_party_mutations: 'blocked in browser', browser: null, pages: [] };
try {
    let target;
    for (let i = 0; i < 80; i++) {
        try { target = await (await fetch('http://127.0.0.1:9222/json/new?about:blank', { method: 'PUT' })).json(); break; } catch { await pause(100); }
    }
    assert(target, 'Chromium started');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let id = 0;
    const pending = new Map(), listeners = new Map();
    const call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
        const key = ++id;
        const timer = setTimeout(() => { pending.delete(key); reject(Error('CDP timeout: ' + method)); }, 20000);
        pending.set(key, { resolve, reject, timer });
        socket.send(JSON.stringify({ id: key, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
    socket.onmessage = ({ data }) => {
        const m = JSON.parse(data);
        if (m.id) {
            const p = pending.get(m.id); if (!p) return;
            clearTimeout(p.timer); pending.delete(m.id);
            m.error ? p.reject(Error(m.error.message)) : p.resolve(m.result);
        } else for (const f of listeners.get(m.method) ?? []) f(m.params);
    };
    const on = (name, fn) => listeners.set(name, [...(listeners.get(name) ?? []), fn]);
    const evaluate = async expression => {
        const r = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert(!r.exceptionDetails, r.exceptionDetails?.text);
        return r.result.value;
    };
    report.browser = await call('Browser.getVersion');
    // Instrument callbacks and commands transparently, forwarding every call to the real API.
    await call('Page.addScriptToEvaluateOnNewDocument', { source: `
        window.__vslProbe={events:[],commands:[],instances:[],samples:[]};
        window.onYouTubeIframeAPIReady=()=>{
            const Native=window.YT.Player;
            window.YT.Player=function(element,options){
                const probe=window.__vslProbe;
                probe.instances.push({videoId:options.videoId,host:options.host,playerVars:{...options.playerVars}});
                const sample=player=>{try{return {time:player.getCurrentTime(),duration:player.getDuration(),muted:player.isMuted(),volume:player.getVolume(),state:player.getPlayerState()}}catch{return null}};
                for(const [name,callback] of Object.entries(options.events)) options.events[name]=event=>{
                    // YouTube installs control methods during readiness, not construction.
                    if(name==='onReady')for(const control of ['playVideo','pauseVideo','seekTo','mute','unMute','setVolume','destroy']){
                        const original=event.target[control];
                        if(typeof original==='function')event.target[control]=function(...args){probe.commands.push({name:control,args,at:performance.now(),before:sample(this)});return original.apply(this,args)};
                    }
                    const entry={name,data:typeof event?.data==='number'?event.data:null,at:performance.now(),before:sample(event?.target??window.__realPlayer)};
                    probe.events.push(entry); const result=callback(event); entry.after=sample(event?.target??window.__realPlayer); return result;
                };
                const player=new Native(element,options); window.__realPlayer=player;
                return player;
            };
            window.YT.Player.prototype=Native.prototype;
        };
        setInterval(()=>{const p=window.__realPlayer;if(p){try{window.__vslProbe.samples.push({at:performance.now(),time:p.getCurrentTime(),muted:p.isMuted(),volume:p.getVolume(),state:p.getPlayerState()})}catch{}}},500);
    ` });
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    await call('Emulation.setFocusEmulationEnabled', { enabled: true });
    let current;
    on('Fetch.requestPaused', async e => {
        const u = new URL(e.request.url);
        if (['jakawi.com', 'jakawi.club'].includes(u.hostname) && !['GET', 'HEAD', 'OPTIONS'].includes(e.request.method)) {
            current?.blocked_mutations.push({ path: u.pathname, method: e.request.method });
            await call('Fetch.fulfillRequest', { requestId: e.requestId, responseCode: 204 });
        } else await call('Fetch.continueRequest', { requestId: e.requestId });
    });
    await call('Fetch.enable', { patterns: [{ urlPattern: 'https://jakawi.com/*' }, { urlPattern: 'https://jakawi.club/*' }] });
    on('Network.requestWillBeSent', e => {
        if (!current) return;
        const u = new URL(e.request.url);
        if (u.hostname.endsWith('youtube-nocookie.com') && u.pathname.startsWith('/embed/')) {
            const headers = Object.fromEntries(Object.entries(e.request.headers).filter(([k]) => ['referer', 'origin'].includes(k.toLowerCase())));
            current.embed_requests.push({ url: u.href, headers });
        }
    });
    on('Network.responseReceived', e => {
        if (!current) return;
        const u = new URL(e.response.url);
        if (e.type === 'Document' && ['jakawi.com', 'jakawi.club'].includes(u.hostname)) current.headers = Object.fromEntries(Object.entries(e.response.headers).filter(([k]) => ['content-security-policy', 'referrer-policy', 'permissions-policy', 'x-frame-options'].includes(k.toLowerCase())));
        if (e.response.status >= 400) current.http_errors.push({ host: u.hostname, path: u.pathname, status: e.response.status, type: e.type });
    });
    on('Network.loadingFailed', e => { if (current) current.network_errors.push({ type: e.type, error: e.errorText, blockedReason: e.blockedReason }); });
    on('Runtime.exceptionThrown', e => { if (current) current.runtime_errors.push({text:e.exceptionDetails.text,description:e.exceptionDetails.exception?.description?.slice(0,2000)}); });
    const snapshot = () => evaluate(`(()=>{
        const data=document.querySelector('script[data-page="app"]')?.textContent;
        const page=data?JSON.parse(data):JSON.parse(document.querySelector('[data-page]')?.getAttribute('data-page')??'null');
        const region=document.querySelector('[data-marketing-video-frame]')??document.querySelector('[data-vsl-state]');
        const frame=region?.querySelector('[class*="frame"]');const iframe=region?.querySelector('iframe');
        const box=el=>{if(!el)return null;const b=el.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height}};
        return {source:page?.props.launch?.video_url??page?.props.vsl?.source?.url??null,show_video:page?.props.landing?.content?.presentation?.show_video??null,
            registration_open:page?.props.launch?.registration_open??null,frame:box(frame),region:box(region),iframe:iframe?{src:iframe.src,allow:iframe.allow,referrerPolicy:iframe.referrerPolicy,box:box(iframe)}:null,
            state:region?.dataset.vslState??null,overflow:document.documentElement.scrollWidth>innerWidth,
            posters:region?.querySelectorAll('img').length??0,error:region?.querySelector('[class*="error"]')?.textContent??null,
            active_youtube_iframes:document.querySelectorAll('iframe[src*="youtube-nocookie.com/embed/"]').length,
            external_video_links:[...document.querySelectorAll('a[href]')].filter(a=>/youtu(?:be|\.be)/.test(a.href)).map(a=>a.textContent),
            sound_overlay:!!document.querySelector('[data-vsl-sound]'),probe:window.__vslProbe};
    })()`);
    const click = async selector => {
        const p = await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)return null;e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);
        if (!p) return false;
        await call('Input.dispatchMouseEvent', { type: 'mousePressed', ...p, button: 'left', clickCount: 1 });
        await call('Input.dispatchMouseEvent', { type: 'mouseReleased', ...p, button: 'left', clickCount: 1 });
        return true;
    };
    for (const width of (selectedWidth ? [selectedWidth] : stage === 'before' ? [1440] : [390, 430, 1440])) {
        const targets = { com: 'https://jakawi.com/l/elmeromero-padel-desde-cero', club: 'https://jakawi.club/principal', home: 'https://jakawi.club/' };
        for (const url of (selectedTarget ? [targets[selectedTarget]] : [targets.com, targets.club, ...(stage === 'live' && width === 390 ? [targets.home] : [])])) {
            current = { url, width, blocked_mutations: [], embed_requests: [], http_errors: [], network_errors: [], runtime_errors: [] };
            report.pages.push(current);
            await call('Network.clearBrowserCookies');
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
            await call('Page.navigate', { url });
            for (let i = 0; i < 70; i++) {
                await pause(500);
                const s = await snapshot();
                if (s.probe?.events.some(e => e.name === 'onError') || s.probe?.samples.filter(e => e.state === 1).length >= 10 || (i >= 15 && !s.source)) break;
            }
            current.autoplay = await snapshot();
            // Read only the provider's visible diagnostic, without changing its response.
            const tree = await call('Page.getFrameTree');
            const frames = node => [node.frame, ...(node.childFrames ?? []).flatMap(frames)];
            const providerFrame = frames(tree.frameTree).find(f => f.url.startsWith('https://www.youtube-nocookie.com/embed/P42gDOTL6Ys'));
            if (providerFrame) {
                try {
                    const world = await call('Page.createIsolatedWorld', { frameId: providerFrame.id, worldName: 'read-provider-diagnostic' });
                    const result = await call('Runtime.evaluate', { contextId: world.executionContextId, expression: `document.querySelector('.ytp-error-content-wrap-reason')?.textContent ?? document.querySelector('.ytp-error-content-wrap')?.textContent?.slice(0,300) ?? null`, returnByValue: true });
                    current.provider_visible_diagnostic = result.result.value;
                } catch { current.provider_diagnostic_readable = false; }
            }
            if (!current.provider_visible_diagnostic) {
                const targets = await call('Target.getTargets');
                const target = targets.targetInfos.find(t => t.type === 'iframe' && t.url.startsWith('https://www.youtube-nocookie.com/embed/P42gDOTL6Ys'));
                if (target) {
                    const child = await call('Target.attachToTarget', { targetId: target.targetId, flatten: true });
                    const result = await call('Runtime.evaluate', { expression: `document.querySelector('.ytp-error-content-wrap-reason')?.textContent ?? document.querySelector('.ytp-error')?.textContent?.slice(0,300) ?? null`, returnByValue: true }, child.sessionId);
                    current.provider_visible_diagnostic = result.result.value;
                    await call('Target.detachFromTarget', { sessionId: child.sessionId });
                }
            }
            if (current.autoplay.sound_overlay) {
                current.sound_gesture_dispatched = await click('[data-vsl-sound]');
                await pause(5500); current.after_sound = await snapshot();
                current.pause_dispatched = await click('button[aria-label="Pausar video"]');
                await pause(750); current.after_pause = await snapshot();
                current.resume_dispatched = await click('button[class*="start"]');
                await pause(2000); current.after_resume = await snapshot();
            } else if (current.autoplay.state === 'blocked' || current.autoplay.state === 'ready') {
                current.manual_dispatched = await click('button[class*="start"]');
                await pause(5000); current.after_manual = await snapshot();
            }
            const probe = current.autoplay.probe;
            const times = (probe?.samples ?? []).filter(e => e.state === 1).map(e => e.time);
            current.verdict = { mounted: !!current.autoplay.iframe, onReady: !!probe?.events.some(e => e.name === 'onReady'), playing: !!probe?.events.some(e => e.name === 'onStateChange' && e.data === 1), time_advances: times.length >= 2 && Math.max(...times) - Math.min(...times) > 1, starts_muted: probe?.events.find(e => e.name === 'onStateChange' && e.data === 1)?.after?.muted ?? null, onError: probe?.events.filter(e => e.name === 'onError').map(e => e.data) ?? [] };
            if (stage === 'live') {
                assert(current.autoplay.source?.includes('P42gDOTL6Ys'), 'Published official source');
                assert(current.verdict.mounted && current.verdict.onReady, 'Real iframe mounts and reports ready');
                assert.equal(current.autoplay.active_youtube_iframes, 1, 'One active player');
                assert.equal(current.autoplay.overflow, false, 'No page overflow');
                assert(Math.abs(current.autoplay.frame.width / current.autoplay.frame.height - 3 / 4) < .001, 'Visible 3:4 frame');
                assert.deepEqual(current.runtime_errors, [], 'No runtime exceptions');
                if (url.startsWith('https://jakawi.club')) assert.deepEqual(current.autoplay.external_video_links, [], 'Playback stays on landing');
            }
            const name = new URL(url).hostname === 'jakawi.com' ? 'com' : new URL(url).pathname === '/' ? 'club-home' : 'club';
            const picture = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            await writeFile(`/evidence/${stage}-${name}-${width}.png`, Buffer.from(picture.data, 'base64'));
            console.log(JSON.stringify({ url, width, ...current.verdict }));
            await writeFile(`/evidence/${outputName}`, JSON.stringify(report, null, 2) + '\n');
        }
    }
} finally { socket?.close(); chrome.kill('SIGTERM'); }
