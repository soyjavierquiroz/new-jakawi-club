// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const providerOnly = process.argv.includes('--real-provider-only');
const landingBuilder = process.argv.includes('--landing-builder');
const widths = [390, 430, 1024, 1440];
const live = process.argv.includes('--live') || providerOnly;
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
        assert(!response.exceptionDetails, response.exceptionDetails?.exception?.description || response.exceptionDetails?.text || 'Evaluation succeeds');
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
    const original = JSON.parse(html.match(pattern)[2]);
    assert.equal(original.props.launch.registration_open, false);
    const approvedUrl = 'https://www.youtube.com/watch?v=M7lc1UVf-VE'; // browser contract fixture only
    if (!live) {
        const oldManifest = JSON.parse(await readFile('/workspace/public/build/manifest.json', 'utf8'));
        const nextManifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json', 'utf8'));
        for (const [name, entry] of Object.entries(oldManifest)) {
            const next = nextManifest[name]; if (!next) continue;
            html = html.replaceAll(`/build/${entry.file}`, `/build/${next.file}`);
            for (let i=0;i<Math.min((entry.css||[]).length,(next.css||[]).length);i++) html=html.replaceAll(`/build/${entry.css[i]}`,`/build/${next.css[i]}`);
        }
    }
    const metrics=[],submissions=[],checks=[];
    let realProbe=false, apiRequests=0, navigation=0;
    const apiFixture = `window.__vslFixture=true;window.__instances=0;window.__calls=[];window.__vslEvents=[];window.YT={Player:class{constructor(mount,options){window.__instances++;this.muted=true;this.volume=100;this.time=0;window.__vslOptions=options;window.__vslPlayer=this;for(const [name,callback] of Object.entries(options.events))options.events[name]=event=>{window.__vslEvents.push({name,data:event?.data??null});callback(event);};const iframe=document.createElement('iframe');iframe.src='about:blank';mount.replaceWith(iframe);this.iframe=iframe;setTimeout(()=>options.events.onReady({target:this}),30);}playVideo(){window.__calls.push('play');}pauseVideo(){window.__calls.push('pause');window.__vslOptions.events.onStateChange({data:2});}seekTo(time){this.time=time;window.__calls.push('seek:'+time);}mute(){this.muted=true;window.__calls.push('mute');}unMute(){this.muted=false;window.__calls.push('unmute');}isMuted(){return this.muted;}getVolume(){return this.volume;}setVolume(volume){this.volume=volume;}getCurrentTime(){return this.time;}getDuration(){return 90;}destroy(){this.iframe.remove();window.__calls.push('dispose');}}};window.onYouTubeIframeAPIReady?.();`;
    await call('Page.addScriptToEvaluateOnNewDocument',{source:`if(location.pathname.startsWith('/__vsl/')){window.__clockOffset=0;const nativeNow=performance.now.bind(performance);performance.now=()=>nativeNow()+window.__clockOffset;window.__testConnection=new EventTarget();window.__testConnection.saveData=location.pathname.endsWith('/save-data');Object.defineProperty(navigator,'connection',{configurable:true,value:window.__testConnection});}window.__realEvents=[];window.onYouTubeIframeAPIReady=()=>{if(window.__vslFixture)return;const Native=window.YT.Player;window.YT.Player=function(element,options){for(const [name,callback] of Object.entries(options.events))options.events[name]=event=>{window.__realEvents.push({name,data:event?.data??null});callback(event);};const player=new Native(element,options);window.__realPlayer=player;return player;};};`});
    const fulfill=(event,status,type,body='')=>call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(body).toString('base64')});
    on('Fetch.requestPaused',async event=>{
        try {
            const url=new URL(event.request.url);
            if(url.hostname==='jakawi.club'&&url.pathname==='/medicion'){metrics.push(JSON.parse(event.request.postData));await fulfill(event,204,'application/json');return;}
            if(url.hostname==='jakawi.club'&&url.pathname==='/registro'){submissions.push(JSON.parse(event.request.postData));const page=structuredClone(original);page.url='/__vsl/registration'+url.search;page.props.launch.registration_open=true;page.props.errors={email:'Revisa tu correo electrónico.'};await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'X-Inertia',value:'true'}],body:Buffer.from(JSON.stringify(page)).toString('base64')});return;}
            if(!realProbe&&url.href==='https://www.youtube.com/iframe_api'){
                apiRequests++;
                if(await evaluate(`location.pathname.endsWith('/api-error')&&!window.__allowAPIRetry`))await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'ConnectionFailed'});
                else await fulfill(event,200,'application/javascript',apiFixture);
                return;
            }
            if(url.hostname==='jakawi.club'&&url.pathname.startsWith('/build/')&&!live){
                const filename='/workspace/public/build-next/'+url.pathname.slice('/build/'.length);
                try{const body=await readFile(filename);await call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:filename.endsWith('.css')?'text/css':filename.endsWith('.woff2')?'font/woff2':'application/javascript'}],body:body.toString('base64')});return;}catch{}
            }
            if(url.hostname==='jakawi.club'&&url.pathname.startsWith('/__vsl/')){
                const name=url.pathname.split('/').at(-1),page=structuredClone(original);
                page.url=url.pathname+url.search;page.props.landing.preview=name==='preview';
                const content=page.props.landing.content;
                content.presentation.template=name==='waitlist'?'WAITLIST':name.startsWith('webinar')?'WEBINAR':'LEAD_VSL';
                page.props.launch.video_url=approvedUrl;
                content.presentation.show_video=!['webinar-off','waitlist'].includes(name);
                if(name==='horizontal'){content.video.aspect_ratio='16:9';content.video.source_aspect_ratio='3:4';}
                if(name==='no-video')page.props.launch.video_url=null;
                if(name==='registration')page.props.launch.registration_open=true;
                if(name==='changed')page.props.launch.video_url='https://www.youtube.com/watch?v=M7lc1UVf-VE';
                await fulfill(event,200,'text/html; charset=utf-8',html.replace(pattern,(_,open,_body,close)=>open+JSON.stringify(page).replaceAll('<','\\u003c')+close));return;
            }
            await call('Fetch.continueRequest',{requestId:event.requestId});
        }catch(error){runtimeErrors.push(error.message);await call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'});}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'https://jakawi.club/*'},{urlPattern:'https://www.youtube.com/iframe_api'}]});
    const until=async(expression,label)=>{for(let i=0;i<120;i++){try{if(await evaluate(expression))return;}catch{}await pause(75);}throw Error(label);};
    const navigate=async(name)=>{const url=`https://jakawi.club/__vsl/${name}?probe=${++navigation}`;await call('Page.navigate',{url});await until(`location.href===${JSON.stringify(url)}&&document.readyState==='complete'&&!!document.querySelector('h1')`,'Navigate '+name);await evaluate('document.fonts.ready');};
    const state=()=>evaluate(`document.querySelector('[data-vsl-state]')?.dataset.vslState`);
    const progress=()=>evaluate(`new DOMMatrix(getComputedStyle(document.querySelector('[data-vsl-progress] > div')).transform).a`);
    const emit=async value=>{await evaluate(`window.__vslOptions.events.onStateChange({data:${value}})`);};
    const click=async selector=>{await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);await pause(50);};
    const screenshot=async name=>{const result=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(`${output}/${name}.png`,Buffer.from(result.data,'base64'));};
    await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});
    if(!providerOnly){
    for(const width of widths){
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
        await navigate('lead');await until(`window.__vslOptions&&document.querySelector('[data-vsl-state]').dataset.vslState==='requesting'`,'Autoplay request');
        assert(await evaluate(`window.__calls.includes('play')&&window.__vslPlayer.muted&&!window.__vslEvents.some(e=>e.name==='onStateChange'&&e.data===1)`),'No invented playback before provider event');
        assert.equal(await state(),'requesting');assert(await evaluate(`!document.querySelector('[data-vsl-sound]')`));
        await emit(1);await until(`!!document.querySelector('[data-vsl-sound]')`,'Sound overlay');
        const geometry=await evaluate(`(()=>{const r=e=>{const b=e.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,right:b.right,bottom:b.bottom}};const f=r(document.querySelector('.vsl-frame')),i=r(document.querySelector('.vsl-host iframe')),h=r(document.querySelector('.hero-heading')),c=r(document.querySelector('.hero-copy')),m=r(document.querySelector('.hero-media'));return {frame:f,iframe:i,heading:h,copy:c,media:m,centerOffset:Math.abs((h.y+c.bottom)/2-(m.y+m.height/2)),overflow:document.documentElement.scrollWidth>innerWidth,players:document.querySelectorAll('.vsl-player').length,closed:document.querySelector('#registro fieldset').disabled,country:document.querySelector('.phone-country').textContent};})()`);
        assert(!geometry.overflow&&geometry.players===1&&geometry.closed&&geometry.country.includes('+591'));
        assert(Math.abs(geometry.frame.width/geometry.frame.height-.75)<.001);
        assert(Math.abs(geometry.iframe.width/geometry.frame.width-3.16049383)<.001&&Math.abs(geometry.iframe.height/geometry.frame.height-4/3)<.001,'Official cover crop');
        assert(Math.abs(geometry.iframe.width/geometry.iframe.height-16/9)<.001,'Iframe retains its canvas proportion');
        if(width>=1024){assert(geometry.heading.right<geometry.media.x&&geometry.centerOffset<2,'Desktop copy centered beside right video');}
        else assert(geometry.heading.bottom<=geometry.media.y&&geometry.media.bottom<=geometry.copy.y,'Mobile heading/video/copy order');
        if(width<768)await until(`document.querySelector('.mobile-sticky-cta').hidden`,'Active video protects controls');
        await screenshot(`vsl-muted-${width}`);
        const eventsBefore=metrics.filter(e=>e.event==='video_start').length;
        await evaluate(`window.__clockOffset+=10000`);await pause(100);assert(Math.abs(await progress()-.29767)<.04);
        await click('[aria-label="Pausar video"]');assert.equal(await state(),'paused');const frozen=await progress();await evaluate('window.__clockOffset+=20000');await pause(100);assert(Math.abs(await progress()-frozen)<.001,'Pause freezes decorative clock');
        await click('.vsl-play');assert.equal(await state(),'requesting');await emit(1);await until(`!!document.querySelector('[data-vsl-sound]')`,'Continue muted');
        await emit(3);await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='buffering'`,'Buffering state');await pause(50);const buffered=await progress();await evaluate('window.__clockOffset+=20000');await pause(100);assert(Math.abs(await progress()-buffered)<.001,'Buffering freezes clock');await emit(1);
        await evaluate(`window.__calls.length=0;window.__vslPlayer.time=7;window.__vslPlayer.volume=0;document.querySelector('[data-vsl-sound]').click();document.querySelector('[data-vsl-sound]').click();`);
        await pause(100);assert.deepEqual(await evaluate('window.__calls'),['pause','seek:0','unmute','play']);assert.equal(await state(),'requesting');assert(await progress()<.02);
        assert(await evaluate(`!window.__vslPlayer.muted&&window.__vslPlayer.volume===100&&!!document.querySelector('[data-vsl-sound]')`),'Audio awaits actual playback');
        await emit(1);await until(`!document.querySelector('[data-vsl-sound]')`,'Provider confirms audible state');
        assert(await evaluate(`document.querySelector('[aria-live]').textContent.includes('Reproduciendo con sonido')`));
        await evaluate('window.__clockOffset+=90000');await pause(100);assert(Math.abs(await progress()-.97)<.0001,'Dummy stops at 97%');
        await emit(0);await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='ended'`,'Provider ended');assert.equal(await progress(),1);
        await click('.vsl-play');assert(await progress()<.02);assert(await evaluate(`!window.__vslPlayer.muted&&window.__vslPlayer.time===0`),'Replay retains audio and restarts');await emit(1);
        assert(!metrics.some(e=>['progress','retention','audio-activated'].includes(e.event)),'Dummy is not analytics');
        await evaluate('window.__vslOptions.events.onError({data:150})');await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='error'`,'Provider 150');
        assert(await evaluate(`document.querySelector('[role=status] a')?.href===${JSON.stringify(approvedUrl)}&&document.querySelector('[role=status]').textContent.includes('150')`),'Visible normal YouTube fallback');
        await screenshot(`vsl-error-${width}`);await click('.vsl-play');await until('window.__instances===2','Retry replaces failed engine');
        console.log('VSL lifecycle/crop passed',width);
        checks.push({name:'official-vsl-lifecycle',width,fixture:'YouTube API contract + virtual decorative clock',geometry,passed:true,analytics_before_clock:eventsBefore});
    }
    // Initial preferences and changes during playback, including audio rollback.
    for(const scenario of ['reduced','save-data','blocked','audio-blocked','preview']){
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:scenario==='reduced'?'reduce':'no-preference'}]});
        const count=metrics.length;await navigate(scenario);await until('!!window.__vslOptions','API fixture ready');await pause(100);
        if(['reduced','save-data'].includes(scenario)){assert.equal(await state(),'ready');assert(!(await evaluate('window.__calls.includes("play")')));await click('.vsl-play');await emit(1);}
        if(scenario==='blocked'){await evaluate('window.__vslOptions.events.onAutoplayBlocked()');assert.equal(await state(),'blocked');await click('.vsl-play');assert.equal(await state(),'requesting');await emit(1);}
        if(scenario==='audio-blocked'){await emit(1);await click('[data-vsl-sound]');await evaluate('window.__vslOptions.events.onAutoplayBlocked()');await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='blocked'`,'Audio blocked');assert(await evaluate('window.__vslPlayer.muted'));assert(await evaluate(`!document.querySelector('[aria-live]').textContent.includes('con sonido')`));}
        if(scenario==='preview'){await emit(1);await pause(200);assert.equal(metrics.length,count,'Preview emits no analytics');assert(await evaluate('document.querySelector("#registro fieldset").disabled'));}
        checks.push({name:scenario,passed:true,fixture:true});
    }
    await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});await navigate('preferences-change');await until('!!window.__vslOptions','Preference fixture');await emit(1);await evaluate('window.__testConnection.saveData=true;window.__testConnection.dispatchEvent(new Event("change"))');await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='paused'`,'Preference change pauses');
    await navigate('changed');await until('!!window.__vslOptions','Changed source');assert(await progress()<.02);assert(await evaluate('window.__vslOptions.videoId==="M7lc1UVf-VE"'));
    for(const name of ['waitlist','webinar-off','no-video','webinar']){
        const before=apiRequests;await navigate(name);await pause(150);
        if(name==='webinar'){await until('!!window.__vslOptions','Webinar mounts player');assert.equal(await evaluate('document.querySelectorAll(".vsl-player").length'),1);}
        else{assert.equal(apiRequests,before,'Hidden/missing video never loads YouTube');assert(await evaluate('!document.querySelector("iframe,script[src*=youtube]")'));}
        checks.push({name,passed:true,fixture:true});
    }
    await navigate('api-error');await until(`document.querySelector('[data-vsl-state]').dataset.vslState==='error'`,'API load failure becomes error');assert(await evaluate(`document.querySelector('.vsl-play').textContent.includes('Intentar de nuevo')&&!!document.querySelector('[role=status] a')`));
    await evaluate('window.__allowAPIRetry=true');await click('.vsl-play');await until('!!window.__vslOptions','API failure retry loads again');
    await navigate('horizontal');await until('!!window.__vslOptions','Horizontal frame');await emit(1);await pause(100);assert(await evaluate(`(()=>{const f=document.querySelector('.vsl-frame').getBoundingClientRect(),i=document.querySelector('iframe').getBoundingClientRect();return Math.abs(f.width/f.height-3/4)<.001&&Math.abs(i.width/i.height-3/4)<.001})()`),'Legacy horizontal settings render in a fixed 3:4 frame without distortion');
    // Existing sticky and international phone behavior uses intercepted form requests.
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await navigate('registration');
    await evaluate(`scrollTo({top:document.querySelector('#como-funciona').offsetTop,behavior:'instant'})`);await until(`!document.querySelector('.mobile-sticky-cta').hidden`,'Sticky outside player/form');await click('[data-marketing-cta="sticky"]');await until(`location.hash==='#registro'&&document.activeElement.id==='registro'&&document.querySelector('.mobile-sticky-cta').hidden`,'Sticky focuses existing form');
    await click('.phone-country');await until(`document.querySelector('dialog').open`,'Country picker');await evaluate(`(()=>{const e=document.querySelector('#country-search');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,'espana');e.dispatchEvent(new Event('input',{bubbles:true}));})()`);await until(`document.querySelectorAll('[role=option]').length===1`,'Country search');await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await evaluate(`(()=>{const e=document.querySelector('#phone');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,'612345678');e.dispatchEvent(new Event('input',{bubbles:true}));})()`);await until(`document.querySelector('input[name=phone]').value==='+34612345678'`,'International phone E.164');
    const key=async(key,code=key,n=0)=>{await call('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode:n});await call('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode:n});};
    const fill=async(selector,value)=>{await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.focus();e.select();})()`);await call('Input.insertText',{text:value});};
    await click('.phone-country');await fill('#country-search','+591');await key('Enter','Enter',13);
    await fill('#phone','+34612345678');assert(await evaluate(`document.querySelector('.phone-country').textContent.includes('+591')&&document.querySelector('input[name=phone]').value===''`),'Pasted foreign number never changes selected country');
    await fill('#phone','70000000');assert(await evaluate(`document.querySelector('input[name=phone]').value==='+59170000000'`));
    await click('.phone-country');await fill('#country-search','zzzz-no-country');await until(`document.querySelectorAll('[role=option]').length===0`,'Empty country search');
    await fill('#country-search','');await key('ArrowDown','ArrowDown',40);assert(await evaluate(`document.querySelector('#country-search').getAttribute('aria-activedescendant')==='country-option-1'`));await key('Tab','Tab',9);assert(await evaluate('document.querySelector("dialog").contains(document.activeElement)'),'Dialog traps focus');await key('Escape','Escape',27);assert(await evaluate('!document.querySelector("dialog").open&&document.activeElement.matches(".phone-country")'));
    await fill('#name','Persona de prueba');await fill('#email','browser@example.invalid');await evaluate(`if(!document.querySelector('#privacy').checked)document.querySelector('#privacy').click()`);await evaluate('document.querySelector("#registro form").requestSubmit()');await until(`document.querySelector('#email').getAttribute('aria-invalid')==='true'&&!document.querySelector('#registro fieldset').disabled`,'Form error unlocks fields');await until('document.activeElement.id==="email"','Form error focuses email');assert.equal(submissions.at(-1).phone,'+59170000000');assert.equal(submissions.at(-1).privacy,true);
    checks.push({name:'sticky-form-international-phone',passed:true,fixture:true});
    }
    if(providerOnly)checks.push(...JSON.parse(await readFile(`${output}/live-browser.json`,'utf8')).checks);
    const realPlayback=[];
    if(live && original.props.launch.video_url){
        realProbe=true;await call('Network.clearBrowserCache');await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});
        for(const width of [390,1440]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});const url='https://jakawi.club/?vsl_check='+width;await call('Page.navigate',{url});await until(`location.href===${JSON.stringify(url)}&&!!document.querySelector('[data-vsl-state]')`,'Real landing');
            for(let i=0;i<120;i++){if(['playing','error','blocked'].includes(await state()))break;await pause(250);}
            assert(await evaluate('!window.__vslFixture&&!window.__testConnection&&window.__clockOffset===undefined'),'Real probe contains no provider, connection or clock fixture');
            const read=`(()=>{const p=window.__realPlayer;return p?.getCurrentTime?{time:p.getCurrentTime(),muted:p.isMuted(),volume:p.getVolume(),state:p.getPlayerState(),videoId:p.getVideoData()?.video_id}:null})()`;
            const before=await evaluate(read);await pause(1500);const after=await evaluate(read);const events=await evaluate('window.__realEvents');
            const verified=!!before&&!!after&&events.some(e=>e.name==='onStateChange'&&e.data===1)&&after.state===1&&after.time>before.time&&before.muted&&after.muted;
            const row={width,state:await state(),fixture:false,native_clock_and_connection:true,source:approvedUrl,events,before,after,verified_muted_playback:verified,audio_verified:false,fallback_visible:await evaluate(`!!document.querySelector('[role=status] a')`)};
            if(verified&&await evaluate('!!document.querySelector("[data-vsl-sound]")')){
                const point=await evaluate(`(()=>{const r=document.querySelector('[data-vsl-sound]').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});await pause(1000);row.audio_before=await evaluate(read);await pause(1000);row.audio_after=await evaluate(read);row.audio_events=await evaluate('window.__realEvents');row.audio_verified=row.audio_events.slice(events.length).some(e=>e.name==='onStateChange'&&e.data===1)&&!!row.audio_before&&!!row.audio_after&&!row.audio_after.muted&&row.audio_after.state===1&&row.audio_after.time>row.audio_before.time;
            }
            assert(!['initializing','requesting','buffering'].includes(row.state),'Real loading terminates honestly');realPlayback.push(row);await screenshot(`real-provider-${width}`);console.log('Real provider',JSON.stringify(row));
        }
    }
    if(live && !original.props.launch.video_url) realPlayback.push({configured:false,verified_muted_playback:false,audio_verified:false,reason:'No approved video configured; no provider probe invented'});
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert(!metrics.some(e=>e.event==='registration_completed'));
    await writeFile(`${output}/${live?'live-browser':'browser'}.json`,JSON.stringify({stage:live?'LIVE':'build-next intercepted only in browser',checks,viewports:widths,provider_contract_fixtures:true,real_playback:realPlayback,runtime_errors:runtimeErrors,failed_assets:failedAssets,no_live_accounts:true},null,2)+'\n');
    console.log(JSON.stringify({passed_groups:checks.length,viewports:widths,live,real_playback:realPlayback.map(r=>({width:r.width,state:r.state,verified:r.verified_muted_playback}))}));
}catch(error){console.error(browserMessages.find(m=>m.includes('FATAL'))||'Browser assertion failed');throw error;}finally{socket?.close();chrome.kill('SIGTERM');}
