// Small dependency-free runner for the local TS engine and phone adapter.
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import ts from '/workspace/node_modules/typescript/lib/typescript.js';
const tmp = await mkdtemp('/tmp/jakawi-conversion-');
let checks = 0;
const check = (value, message) => { assert(value, message); checks++; };
const compile = async (path, name, replace = value => value) => {
    const source = await readFile(`/workspace/resources/js/components/${path}`, 'utf8');
    const result = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } });
    await writeFile(`${tmp}/${name}.mjs`, replace(result.outputText));
    return import(`${tmp}/${name}.mjs`);
};
try {
    await compile('media/jakawi-vsl-player/types.ts', 'types');
    await compile('media/jakawi-vsl-player/youtube-crop.ts', 'youtube-crop');
    const { parseYoutubeId, createYoutubeEngine, loadYoutubeApi } = await compile('media/jakawi-vsl-player/youtube-engine.ts', 'engine', code => code.replaceAll("'./types'", "'./types.mjs'").replaceAll("'./youtube-crop.js'", "'./youtube-crop.mjs'"));
    for (const url of ['M7lc1UVf-VE', 'https://youtu.be/M7lc1UVf-VE', 'https://youtube.com/watch?v=M7lc1UVf-VE', 'https://www.youtube-nocookie.com/embed/M7lc1UVf-VE', 'https://m.youtube.com/shorts/M7lc1UVf-VE']) check(parseYoutubeId(url) === 'M7lc1UVf-VE', 'Safe YouTube variants');
    for (const url of ['javascript:alert(1)', 'https://youtube.com.evil.test/watch?v=M7lc1UVf-VE', 'https://user@youtube.com/watch?v=M7lc1UVf-VE', 'https://youtube.com:444/watch?v=M7lc1UVf-VE', 'https://youtube.com/watch?v=invalid']) { assert.throws(() => parseYoutubeId(url)); checks++; }
    globalThis.window = {};
    let script;
    globalThis.document = { createElement: () => ({ addEventListener(type, fn) { if (type === 'error') this.fail = fn; }, removeEventListener() {}, remove() {} }), head: { append: element => { script = element; } } };
    const host = { append() {}, querySelector: () => ({ setAttribute() {} }), replaceChildren() {} };
    let options, plays = 0, destroys = 0, muted = true;
    const events = [];
    const player = { playVideo() { plays++; }, pauseVideo() {}, seekTo() {}, mute() { muted = true; }, unMute() { muted = false; }, isMuted: () => muted, getVolume: () => 100, setVolume() {}, getCurrentTime: () => 4, getDuration: () => 90, destroy() { destroys++; } };
    const api = { Player: class { constructor(element, config) { options = config; return player; } } };
    const engine = createYoutubeEngine(host, { source: { type: 'youtube', url: 'https://youtu.be/M7lc1UVf-VE' }, origin: 'https://jakawi.club', autoplay: false, onState() {}, onEvent: event => events.push(event.type), loadApi: () => Promise.resolve(api) });
    await Promise.resolve();
    options.events.onReady({ target: player });
    check(plays === 0 && engine.getState().status === 'ready', 'Manual playback: ready never plays automatically');
    check(options.host === 'https://www.youtube-nocookie.com' && options.playerVars.autoplay === 0 && options.playerVars.controls === 0, 'Privacy-enhanced VSL API configuration');
    engine.setMuted(false); engine.play();
    check(plays === 1 && !events.includes('playing') && !engine.getState().hasPlayed, 'Play command is not a playback event');
    options.events.onStateChange({ data: 1 });
    check(engine.getState().hasPlayed && events.includes('playing') && !engine.getState().muted, 'Only the provider playing event confirms playback');
    options.events.onStateChange({ data: 3 }); check(engine.getState().status === 'buffering', 'Buffering state');
    options.events.onStateChange({ data: 2 }); check(engine.getState().status === 'paused', 'Paused state');
    options.events.onStateChange({ data: 0 }); check(engine.getState().status === 'ended', 'Ended state');
    engine.play(); options.events.onAutoplayBlocked(); check(engine.getState().status === 'blocked', 'Blocked playback offers a manual recovery');
    options.events.onError({ data: 150 }); check(engine.getState().status === 'error', 'Provider errors are surfaced');
    engine.dispose(); options.events.onStateChange({ data: 1 }); check(destroys === 1 && engine.getState().status === 'error', 'Disposal rejects late events and clears the engine');
    const invalid = createYoutubeEngine(host, { source: { type: 'youtube', url: 'https://evil.test/video' }, origin: '', autoplay: false, onState() {}, loadApi: () => { throw new Error('Must not load'); } });
    check(invalid.getState().error.kind === 'source', 'Invalid source never loads the API'); invalid.dispose();
    const unavailable = loadYoutubeApi(); script.fail(); await assert.rejects(unavailable); checks++;
    const retry = loadYoutubeApi(); window.YT = api; window.onYouTubeIframeAPIReady(); check(await retry === api, 'API network failure permits retry');
    const { shouldAutoplay } = await import(`${tmp}/types.mjs`);
    for (const [autoplay, reducedMotion, saveData, expected] of [[true,false,false,true],[true,true,false,false],[true,false,true,false],[false,false,false,false]]) {
        check(shouldAutoplay({autoplay,reducedMotion,saveData}) === expected, 'Autoplay honors motion/data preferences');
    }
    const { vslProgress, resolveFraming, verticalFraming } = await compile('media/jakawi-vsl-player/marketing-presentation.ts', 'presentation');
    const { landingVideoProps } = await compile('media/jakawi-vsl-player/landing-video-props.ts', 'adapter');
    const config = {youtube_url:'https://youtu.be/M7lc1UVf-VE',poster:null,aspect_ratio:'3:4',source_aspect_ratio:'9:16'};
    const props = landingVideoProps(config, config.youtube_url, null);
    check(props.mode==='vsl' && props.autoplay==='muted' && props.marketingFrame && props.fit==='cover', 'Typed renderer selects official marketing VSL');
    check(props.framing.kind==='embedded-vertical' && props.framing.viewportAspectRatio===9/16 && props.progressSeconds===90 && props.progressAcceleration===3, 'Original crop and dummy 3/90 defaults');
    check(landingVideoProps({...config,aspect_ratio:'16:9'},config.youtube_url,null).marketingFrame===false, 'Editor horizontal frame remains supported');
    check(landingVideoProps({...config,source_aspect_ratio:'3:4'},config.youtube_url,'/marketing/poster.webp').poster==='/marketing/poster.webp', 'Poster and original aspect are independent');
    const cover = verticalFraming({...resolveFraming('youtube',props.framing),viewportAspectRatio:3/4});
    check(Math.abs(cover.widthPercent-316.0493827)<.001 && Math.abs(cover.heightPercent-133.3333333)<.001, 'Official 16:9 canvas crop into 3:4');
    check(Math.abs(vslProgress(30,90,3)-.7037037037)<.0001 && vslProgress(90,90,3)===1, 'Decorative curve uses its own 90 second horizon');
    check(vslProgress(10,0,3)===0 && vslProgress(10,90,100)===vslProgress(10,90,6), 'Curve validates horizon and clamps exponent');
    async function fixture(autoplay=true) {
        let callbacks; const messages=[],calls=[]; let time=6,volume=0,muted=true;
        const p={playVideo(){calls.push('play')},pauseVideo(){calls.push('pause')},seekTo(value){time=value;calls.push('seek:'+value)},mute(){muted=true;calls.push('mute')},unMute(){muted=false;calls.push('unmute')},isMuted:()=>muted,getVolume:()=>volume,setVolume(value){volume=value},getCurrentTime:()=>time,getDuration:()=>90,destroy(){calls.push('dispose')}};
        const e=createYoutubeEngine(host,{source:{type:'youtube',url:config.youtube_url},origin:'https://jakawi.club',autoplay,onState(){},onEvent:event=>messages.push(event),loadApi:()=>Promise.resolve({Player:class{constructor(_mount,options){callbacks=options.events;return p}}})});
        await Promise.resolve();
        return {e,p,calls,messages,get callbacks(){return callbacks}};
    }
    {
        const f=await fixture();f.callbacks.onReady({target:f.p});
        check(f.e.getState().muted && f.e.getState().status==='requesting' && !f.messages.some(e=>e.type==='playing'),'Muted autoplay requests without inventing playing');
        f.callbacks.onStateChange({data:1});f.calls.length=0;f.e.activateAudio();
        check(f.calls.join(',')==='pause,seek:0,unmute,play' && f.e.getState().currentTime===0 && f.e.getState().volume===1,'Audio activation pauses, seeks, unmutes and plays');
        check(!f.messages.some(e=>e.type==='audio-activated'),'Audio command alone does not confirm audio');
        f.callbacks.onStateChange({data:1});check(f.messages.at(-1).type==='audio-activated','Provider confirms audible playing');
        f.e.pause();check(f.e.getState().status==='paused','Pause state');f.e.play();f.callbacks.onStateChange({data:1});
        f.callbacks.onStateChange({data:0});check(f.e.getState().status==='ended','End from provider');f.calls.length=0;f.e.play();check(f.calls.join(',')==='seek:0,play' && !f.e.getState().muted,'Replay seeks zero and retains audio');f.e.dispose();
    }
    {
        const f=await fixture();f.callbacks.onReady({target:f.p});f.callbacks.onAutoplayBlocked();check(f.e.getState().error.kind==='autoplay-blocked','Autoplay blocked distinct from media failure');
        f.e.play();f.callbacks.onStateChange({data:1});f.e.activateAudio();f.callbacks.onAutoplayBlocked();
        check(f.e.getState().muted && f.e.getState().error.kind==='playback-blocked' && !f.messages.some(e=>e.type==='audio-activated'),'Blocked audio remutes without false confirmation');
        f.callbacks.onStateChange({data:2});check(f.e.getState().status==='blocked','Late pause preserves blocked state');f.e.dispose();
    }
    {
        const f=await fixture(false);f.callbacks.onReady({target:f.p});f.callbacks.onError({data:150});check(f.e.getState().error.code===150 && f.e.getState().error.kind==='media' && /no permite/.test(f.e.getState().error.message),'150 preserved with clear embedding error');f.e.dispose();
    }
    {
        const nativeTimeout=globalThis.setTimeout,nativeClear=globalThis.clearTimeout;let deadline;
        globalThis.setTimeout=(callback,delay)=>{if(delay===20000)deadline=callback;return 1};globalThis.clearTimeout=()=>{};
        const f=await fixture(false);deadline();check(f.e.getState().status==='error' && !f.e.getState().ready,'Missing onReady terminates with retryable error');f.callbacks.onReady({target:f.p});check(!f.e.getState().ready,'Expired readiness ignores late callback');f.e.dispose();
        globalThis.setTimeout=nativeTimeout;globalThis.clearTimeout=nativeClear;
    }
    {
        const nativeTimeout=globalThis.setTimeout,nativeClear=globalThis.clearTimeout;const deadlines=[];
        globalThis.setTimeout=(callback,delay)=>{deadlines.push({callback,delay});return deadlines.length};globalThis.clearTimeout=()=>{};
        const f=await fixture();f.callbacks.onReady({target:f.p});f.callbacks.onStateChange({data:3});
        deadlines.find(d=>d.delay===10000).callback();check(f.e.getState().status==='buffering','Buffering is not mislabeled as browser autoplay denial');
        deadlines.filter(d=>d.delay===20000).at(-1).callback();check(f.e.getState().error.kind==='media' && f.e.getState().status==='error','Stalled buffering terminates with honest media error');f.e.dispose();globalThis.setTimeout=nativeTimeout;globalThis.clearTimeout=nativeClear;
    }
    const { normalizePhone } = await compile('forms/phone.ts', 'phone', code => code.replaceAll("'libphonenumber-js/max'", "'/workspace/node_modules/libphonenumber-js/max/index.js'"));
    for (const [country, number, expected] of [['BO', '70000000', '+59170000000'], ['BO', '+591 (700) 000-00', '+59170000000'], ['ES', '612 345 678', '+34612345678'], ['US', '2133734253', '+12133734253'], ['PE', '987654321', '+51987654321']]) check(normalizePhone(number, country) === expected, `E.164 normalization ${country}`);
    for (const [country, number] of [['BO', '+34612345678'], ['BO', '12'], ['BO', 'Llama al 70000000'], ['ES', '+59170000000'], ['BO', '+59170000000123456'], ['BO', '70000000 ext 2']]) check(normalizePhone(number, country) === '', 'Invalid/mismatched country and extensions are rejected without auto-switch');
    console.log(JSON.stringify({ passed: checks, engine: 'official YouTube-only adapter', phone: 'libphonenumber-js/max', live_video_playback: 'not evaluated by unit tests; see browser evidence' }, null, 2));
} finally { await rm(tmp, { recursive: true, force: true }); }
