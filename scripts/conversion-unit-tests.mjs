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
    const { parseYoutubeId, createYoutubeEngine, loadYoutubeApi } = await compile('media/jakawi-vsl-player/youtube-engine.ts', 'engine', code => code.replaceAll("'./types'", "'./types.mjs'"));
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
    const { normalizePhone } = await compile('forms/phone.ts', 'phone', code => code.replaceAll("'libphonenumber-js/max'", "'/workspace/node_modules/libphonenumber-js/max/index.js'"));
    for (const [country, number, expected] of [['BO', '70000000', '+59170000000'], ['BO', '+591 (700) 000-00', '+59170000000'], ['ES', '612 345 678', '+34612345678'], ['US', '2133734253', '+12133734253'], ['PE', '987654321', '+51987654321']]) check(normalizePhone(number, country) === expected, `E.164 normalization ${country}`);
    for (const [country, number] of [['BO', '+34612345678'], ['BO', '12'], ['BO', 'Llama al 70000000'], ['ES', '+59170000000'], ['BO', '+59170000000123456'], ['BO', '70000000 ext 2']]) check(normalizePhone(number, country) === '', 'Invalid/mismatched country and extensions are rejected without auto-switch');
    console.log(JSON.stringify({ passed: checks, engine: 'official YouTube-only adapter', phone: 'libphonenumber-js/max', live_video_playback: 'not evaluated by unit tests; see browser evidence' }, null, 2));
} finally { await rm(tmp, { recursive: true, force: true }); }
