// Uses the existing Chromium through CDP and Node's native WebSocket; no npm dependencies.
// Protected pages use browser-only fixtures. No account or registration is created.
import { spawn } from 'node:child_process';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { lookup } from 'node:dns/promises';

const output = '/evidence';
const live = false;
await mkdir(output, { recursive: true });
const addresses = await Promise.all(['jakawi.club'].map(async host => `MAP ${host} ${(await lookup(host, { family: 4 })).address}`));
const chrome = spawn('/browser/chrome', ['--headless=new', '--hide-scrollbars', '--no-sandbox', '--single-process', '--no-zygote', '--num-raster-threads=1', '--disable-background-networking', '--disable-extensions', '--disable-gpu', '--disable-dev-shm-usage', '--no-proxy-server', `--host-resolver-rules=${addresses.join(',')}`, '--remote-debugging-port=9222', '--user-data-dir=/tmp/jakawi-opportunity-visual', '--no-first-run', '--no-default-browser-check'], { stdio: ['ignore', 'ignore', 'pipe'] });
const browserMessages = [];
chrome.stderr.on('data', data => browserMessages.push(data.toString()));
chrome.on('exit', (code, signal) => { if (code !== 0 && signal !== 'SIGTERM') console.error('Browser exit', code, signal); });
let socket, closing = false;
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
        pending.set(id, { resolve, reject, timer, method, expression: params.expression });
        socket.send(JSON.stringify({ id, method, params }));
    });
    socket.onclose = () => { for (const item of pending.values()) { clearTimeout(item.timer); item.reject(new Error('Browser connection closed')); } };
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id) {
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer); pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message + " for " + item.method + " " + item.expression)); else item.resolve(message.result);
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

    const fixtures = JSON.parse(await readFile('/evidence/fixtures.json','utf8'));
    fixtures.support=structuredClone(fixtures.admin);fixtures.support.url='/admin/soporte';fixtures.support.page.component='admin/Support';Object.assign(fixtures.support.page.props,{expired:true,recent:false,view:null,accounts:[]});
    const oldManifest = JSON.parse(await readFile('/live-build/manifest.json','utf8'));
    const manifest = JSON.parse(await readFile('/workspace/public/build-next/manifest.json','utf8'));
    let active='login', serverTheme='dark', failSave=false, holdSaves=false, realVideo=false;
    const saved=[], checks=[], writes=[];
    const pendingSaves=[];
    const fulfill=(e,type,bytes,status=200)=>call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(bytes).toString('base64')});
    const htmlFor = name => {
        const fixture=fixtures[name];let html=fixture.html;
        for(const [key,entry] of Object.entries(oldManifest)) {
            const next=manifest[key];if(!next)continue;
            html=html.replaceAll('/build/'+entry.file,'/build/'+next.file);
            for(let i=0;i<(entry.css||[]).length;i++)if(next.css?.[i])html=html.replaceAll('/build/'+entry.css[i],'/build/'+next.css[i]);
        }
        const page=structuredClone(fixture.page);
        if(page.props.appearance_identity) {
            page.props.theme_preference=serverTheme;
            html=html.replace(/window.__appearance = .*?;/, 'window.__appearance = '+JSON.stringify({identity:page.props.appearance_identity,preference:serverTheme})+';');
        }
        html=html.replace(/(<script\b[^>]*data-page="app"[^>]*>)[\s\S]*?(<\/script>)/,(_,a,b)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+b);
        return html;
    };
    on('Fetch.requestPaused',async e=>{
        try {
            const url=new URL(e.request.url);
            if(realVideo && url.hostname!=='jakawi.club'){await call('Fetch.continueRequest',{requestId:e.requestId});return;}
            if(url.pathname==='/settings/appearance') {
                saved.push(JSON.parse(e.request.postData).theme_preference);
                if(holdSaves){pendingSaves.push(e);return;}
                if(!failSave)serverTheme=saved.at(-1);
                await fulfill(e,'application/json','{}',failSave?500:200);return;
            }
            if(e.request.method!=='GET') { writes.push(url.pathname);await fulfill(e,'application/json','{}');return; }
            if(url.hostname==='jakawi.club' && e.resourceType==='Document'){await fulfill(e,'text/html',htmlFor(url.pathname.includes('/preview') && active==='editor'?'preview':active));return;}
            if(e.request.headers['X-Inertia'] || e.request.headers['x-inertia']){const page=structuredClone(fixtures[active].page);page.props.theme_preference=serverTheme;await call('Fetch.fulfillRequest',{requestId:e.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'X-Inertia',value:'true'}],body:Buffer.from(JSON.stringify(page)).toString('base64')});return;}
            if(url.pathname.startsWith('/build/')) {const f='/workspace/public/build-next/'+url.pathname.slice(7);await fulfill(e,f.endsWith('.css')?'text/css':f.endsWith('.woff2')?'font/woff2':'application/javascript',await readFile(f));return;}
            if(url.hostname==='jakawi.club' && ['/jakawi-logo.svg','/jakawi-mark.svg'].includes(url.pathname)){await fulfill(e,'image/svg+xml',await readFile('/workspace/public'+url.pathname));return;}
            if(url.hostname==='jakawi.club' && url.pathname.startsWith('/marketing/')){await fulfill(e,url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',await readFile('/workspace/public'+url.pathname));return;}
            await fulfill(e,e.resourceType==='Script'?'application/javascript':'application/json',e.resourceType==='Script'?'':'{}');
        }catch(error){if(!closing)runtimeErrors.push(String(error));await fulfill(e,'text/plain','fixture error',500).catch(()=>{});}
    });
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    const until=async expr=>{for(let i=0;i<100;i++){if(await evaluate(expr))return;await pause(50);}throw Error('Timed out '+expr);};
    const navigate=async(name,width=1440,suffix='')=>{const previous=await evaluate('window.__qaDocument');active=name;console.log('Checking',name,width);await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await call('Page.navigate',{url:'https://jakawi.club'+fixtures[name].url+suffix});await until('window.__qaDocument!=='+JSON.stringify(previous??null)+' && !!document.querySelector("select[aria-label=Apariencia]")');await evaluate('document.fonts.ready');await pause(150);};
    const set=async mode=>{await evaluate(`(()=>{const e=document.querySelector('select[aria-label=Apariencia]');e.value=${JSON.stringify(mode)};e.dispatchEvent(new Event('change',{bubbles:true}));})()`);await until('document.documentElement.dataset.appearance==='+JSON.stringify(mode));};
    const reload=async()=>{const previous=await evaluate('window.__qaDocument');await call('Page.reload');await until('window.__qaDocument!=='+JSON.stringify(previous)+' && !!document.querySelector("select[aria-label=Apariencia]")');};
    const resolved=()=>evaluate('document.documentElement.dataset.accountTheme');
    const shot=async name=>{const png=await call('Page.captureScreenshot',{format:'png'});await writeFile(output+'/'+name+'.png',Buffer.from(png.data,'base64'));};
    await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.__qaDocument=crypto.randomUUID();new MutationObserver((_,observer)=>{if(document.body){window.__atBody={mode:document.documentElement.dataset.accountTheme,appearance:document.documentElement.dataset.appearance};observer.disconnect();}}).observe(document,{subtree:true,childList:true});`});
    const videoOnly=process.argv.includes('--video-only');
    let contrast=[];
    if(!videoOnly){
    if(!process.argv.includes('--behavior-only'))
    for(const width of (process.argv.includes('--390')?[390]:process.argv.includes('--1440')?[1440]:[390,1440])) {
        for(const mode of (process.argv.includes('--light')?['light']:process.argv.includes('--dark')?['dark']:['light','dark'])) {
            for(const name of ['LEAD_VSL','WEBINAR','WAITLIST','login','recovery','activation','reset','confirmation','membership','account','affiliates','admin','support','editor','preview']) {
                await navigate(name,width);await set(mode);await pause(180);
                const result=await evaluate(`(()=>{const root=getComputedStyle(document.documentElement);return{overflow:document.documentElement.scrollWidth>innerWidth+1,bg:root.getPropertyValue('--background').trim(),fg:root.getPropertyValue('--foreground').trim(),selected:document.querySelector('select[aria-label=Apariencia]').value,inputs:[...document.querySelectorAll('.input')].slice(0,3).map(e=>({bg:getComputedStyle(e).backgroundColor,fg:getComputedStyle(e).color}))};})()`);
                assert(!result.overflow,JSON.stringify({name,width,mode,result}));assert.equal(result.selected,mode);assert.equal(await resolved(),mode);
                await shot(name+'-'+width+'-'+mode);
                if(['LEAD_VSL','WEBINAR','WAITLIST'].includes(name)){await evaluate('document.querySelector("#registro").scrollIntoView({block:"start",behavior:"instant"})');await shot(name+'-form-'+width+'-'+mode);}
                checks.push({name,width,mode,overflow:false,inputs:result.inputs});
            }
        }
    }
    if(!process.argv.includes('--matrix-only')){
    await navigate('login');await set('dark');await reload();assert.equal(await resolved(),'dark');assert.equal(await evaluate('window.__atBody.mode'),'dark');
    await navigate('LEAD_VSL');assert.equal(await resolved(),'dark');checks.push('guest reload, cross-page persistence and prepaint');
    await set('system');
    for(const value of ['light','dark','light']) {await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value},{name:'prefers-reduced-motion',value:'reduce'}]});await until('document.documentElement.dataset.accountTheme==='+JSON.stringify(value));assert.equal(await evaluate('document.documentElement.dataset.appearance'),'system');}
    checks.push('system reacts without changing canonical selection');
    await navigate('account');await set('light');await until('!document.querySelector("[role=alert]")');await pause(200);assert.equal(serverTheme,'light');
    await reload();assert.equal(await resolved(),'light');assert.equal(await evaluate('window.__atBody.mode'),'light');
    await evaluate("localStorage.setItem('appearance','dark')");await reload();assert.equal(await resolved(),'light');checks.push('account wins over guest browser storage on new document');
    holdSaves=true;const count=saved.length;await set('dark');await set('system');await set('light');await pause(100);assert.equal(saved.length,count+1,'requests serialized');
    for(let i=0;i<3;i++){await until('true');for(let j=0;j<50&&!pendingSaves.length;j++)await pause(20);const e=pendingSaves.shift();serverTheme=JSON.parse(e.request.postData).theme_preference;await fulfill(e,'application/json','{}');await pause(100);}
    holdSaves=false;assert.deepEqual(saved.slice(count),['dark','system','light']);checks.push('serialized account PATCH requests retain order');
    failSave=true;await set('dark');await until('!!document.querySelector(".appearance-error")');assert.equal(await resolved(),'dark');await shot('save-error');
    await reload();assert.equal(await resolved(),'light');failSave=false;checks.push('failed save announces error; next document restores server authority');
    await evaluate("window.appearanceStore.sync('second-account','system')");assert.equal(await evaluate('document.documentElement.dataset.appearance'),'system');
    await evaluate("dispatchEvent(new StorageEvent('storage',{key:'appearance:'+"+JSON.stringify(fixtures.account.page.props.appearance_identity)+",newValue:'dark'}))");assert.equal(await evaluate('document.documentElement.dataset.appearance'),'system');checks.push('identity switch ignores previous-account storage events');
    await evaluate("window.appearanceStore.sync(null,'system')");assert.equal(await resolved(),'dark');checks.push('logout restores guest preference');
    await navigate('editor');await set('dark');
    await evaluate(`[...document.querySelectorAll('button')].find(e=>e.textContent.trim()==='IMPORTAR JSON').click()`);await until('!!document.querySelector("dialog[open]")');
    assert.equal(await evaluate('getComputedStyle(document.querySelector("dialog[open]")).backgroundColor'),'rgb(28, 28, 28)');await shot('editor-dialog-dark');
    await set('light');assert.equal(await evaluate('getComputedStyle(document.querySelector("dialog[open]")).backgroundColor'),'rgb(255, 253, 249)');
    await evaluate('document.querySelector("dialog[open]").close()');
    await until('!!document.querySelector("iframe")?.contentDocument?.querySelector("select[aria-label=Apariencia]")');
    await set('dark');await until('document.querySelector("iframe").contentDocument.documentElement.dataset.accountTheme==="dark"');
    checks.push('editor modal and real iframe preview follow appearance without reload');
    await navigate('LEAD_VSL',390);await evaluate('window.appearanceStore.sync('+JSON.stringify(fixtures.account.page.props.appearance_identity)+',"light")');failSave=true;await set('dark');await until('!!document.querySelector(".appearance-error")');
    assert(await evaluate('(()=>{const r=document.querySelector(".appearance-error").getBoundingClientRect();return r.x>=0&&r.right<=innerWidth&&r.bottom<=innerHeight})()'),'save error remains inside mobile public viewport');await shot('public-save-error-390');failSave=false;
    checks.push('account save error remains readable on a public mobile landing');
    // Native storage events between two independent tabs in the same browser context.
    const tab=await (await fetch('http://127.0.0.1:9222/json/new?about:blank',{method:'PUT'})).json();
    const tabSocket=new WebSocket(tab.webSocketDebuggerUrl);await new Promise((r,j)=>{tabSocket.onopen=r;tabSocket.onerror=j;});
    let tabId=0;const tabPending=new Map();
    const tabCall=(method,params={})=>new Promise((resolve,reject)=>{const id=++tabId;tabPending.set(id,{resolve,reject});tabSocket.send(JSON.stringify({id,method,params}));});
    tabSocket.onmessage=async({data})=>{const m=JSON.parse(data);if(m.id){const p=tabPending.get(m.id);if(p){tabPending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}}else if(m.method==='Fetch.requestPaused'){await tabCall('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from('<!doctype html><script>window.events=[];addEventListener("storage",e=>events.push({key:e.key,value:e.newValue}));</script>').toString('base64')});}};
    await tabCall('Page.enable');await tabCall('Runtime.enable');await tabCall('Fetch.enable',{patterns:[{urlPattern:'*'}]});await tabCall('Page.navigate',{url:'https://jakawi.club/__appearance-tab'});await pause(250);
    await navigate('login');await set('light');await tabCall('Runtime.evaluate',{expression:"localStorage.setItem('appearance','dark')"});await until("document.documentElement.dataset.accountTheme==='dark'");
    await set('light');await pause(100);const tabEvents=(await tabCall('Runtime.evaluate',{expression:'window.events',returnByValue:true})).result.value;assert(tabEvents.some(e=>e.key==='appearance'&&e.value==='light'));
    await navigate('account');await set('light');const owner=fixtures.account.page.props.appearance_identity;
    await tabCall('Runtime.evaluate',{expression:'localStorage.setItem('+JSON.stringify('appearance:'+owner)+',"dark")'});await until("document.documentElement.dataset.accountTheme==='dark'");
    await tabCall('Runtime.evaluate',{expression:"localStorage.setItem('appearance:other-user','light')"});await pause(100);assert.equal(await resolved(),'dark');
    tabSocket.close();await call('Target.closeTarget',{targetId:tab.id});checks.push('native two-tab guest and scoped account synchronization');
    // Storage-denied guest: host-only cookie remains the reload authority.
    const blocked=await call('Page.addScriptToEvaluateOnNewDocument',{source:`Object.defineProperty(window,'localStorage',{get(){throw new Error('Storage denied for QA');}});`});
    await navigate('login',1440,'?storage-blocked');await set('dark');await reload();assert.equal(await resolved(),'dark');assert.equal(await evaluate('window.__atBody.mode'),'dark');await call('Page.removeScriptToEvaluateOnNewDocument',{identifier:blocked.identifier});checks.push('cookie fallback when localStorage is denied');
    }
    // Token contrast is measured in the browser, after resolving CSS custom properties.
    contrast=[];
    for(const mode of ['light','dark']){
      await set(mode);
      const rows=await evaluate(`(()=>{const pairs=[['--foreground','--background'],['--text-secondary','--surface'],['--text-muted','--surface'],['--primary-foreground','--primary'],['--action-text','--surface'],['--success-text','--success-surface'],['--danger-text','--danger-surface'],['--warning-text','--warning-surface'],['--input','--surface'],['--ring','--background']];const color=t=>{const e=document.createElement('span');e.style.color='var('+t+')';document.body.append(e);const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d');ctx.fillStyle=getComputedStyle(e).color;ctx.fillRect(0,0,1,1);const c=[...ctx.getImageData(0,0,1,1).data].slice(0,3);e.remove();return c;};const lum=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((a,v,i)=>a+v*[.2126,.7152,.0722][i],0);return pairs.map(([a,b])=>{const x=lum(color(a)),y=lum(color(b));return{a,b,ratio:(Math.max(x,y)+.05)/(Math.min(x,y)+.05),minimum:a==='--input'||a==='--ring'?3:4.5};});})()`);
      for(const row of rows)assert(row.ratio>=row.minimum,JSON.stringify({mode,...row}));contrast.push({mode,rows});
    }
    }
    let videoReport=[];
    if(videoOnly){
        fixtures.video=structuredClone(fixtures.LEAD_VSL);
        fixtures.video.url='/__appearance-video';
        fixtures.video.page.props.launch.video_url='https://www.youtube.com/watch?v=M7lc1UVf-VE';
        fixtures.video.page.props.landing.content.presentation.show_video=true;
        fixtures.video.page.props.landing.preview=true;
        realVideo=true;
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});
        await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.__players=[];window.__videoEvents=[];window.onYouTubeIframeAPIReady=()=>{const Native=window.YT.Player;window.YT.Player=function(element,options){for(const [name,callback] of Object.entries(options.events))options.events[name]=event=>{window.__videoEvents.push({name,data:event?.data??null});callback(event);};const player=new Native(element,options);window.__players.push(player);return player;};};`});
        for(const width of [390,1440]){
            await navigate('video',width);
            for(let i=0;i<120;i++){if(await evaluate('window.__players?.[0]?.getCurrentTime && window.__players[0].getCurrentTime()>0'))break;await pause(250);}
            const read=()=>evaluate(`(()=>{const p=window.__players?.[0];const f=document.querySelector('.vsl-frame');const r=f?.getBoundingClientRect();return{players:window.__players?.length,time:p?.getCurrentTime?.(),state:p?.getPlayerState?.(),muted:p?.isMuted?.(),volume:p?.getVolume?.(),videoId:p?.getVideoData?.()?.video_id,frame:r?{x:r.x,y:r.y,width:r.width,height:r.height}:null,events:window.__videoEvents,status:document.querySelector('[data-vsl-state]')?.dataset.vslState};})()`);
            const before=await read();await pause(1200);const playing=await read();
            await set('dark');await pause(200);const after=await read();await set('light');await pause(300);const light=await read();
            videoReport.push({width,before,playing,after,light,verified:before.state===1&&playing.time>before.time&&after.time>=playing.time&&light.time>=after.time&&before.players===after.players&&JSON.stringify(before.frame)===JSON.stringify(after.frame)&&after.muted===before.muted&&after.videoId===before.videoId});
            await shot('video-'+width);
        }
        await writeFile(output+'/video.json',JSON.stringify({source:fixtures.video.page.props.launch.video_url,isolated_fixture:true,provider_mock:false,videoReport},null,2));
        assert(videoReport.every(r=>r.verified),'Real reproducible playback survives appearance changes');
        checks.push('real YouTube playback retains instance, time, mute, source and geometry at 390/1440');
    }
    assert.deepEqual(runtimeErrors,[]);assert.deepEqual(failedAssets,[]);assert.deepEqual(writes,[]);
    const {createHash}=await import('node:crypto');
    await writeFile(output+(videoOnly?'/video-browser.json':process.argv.includes('--matrix-only')?'/browser-'+(process.argv.includes('--390')?'390':'1440')+'-'+(process.argv.includes('--light')?'light':'dark')+'.json':'/behavior-browser.json'),JSON.stringify({checks,contrast:videoOnly?[]:contrast,no_live_mutations:true,saved_intercepted:saved.length,manifest_sha256:createHash('sha256').update(await readFile('/workspace/public/build-next/manifest.json')).digest('hex')},null,2)+'\n');
    console.log(JSON.stringify({passed:checks.length,no_live_mutations:true}));
    closing=true;await call('Fetch.disable');
}catch(e){console.error('QA failed',e);throw e;}finally{closing=true;socket?.close();chrome.kill('SIGTERM');}
