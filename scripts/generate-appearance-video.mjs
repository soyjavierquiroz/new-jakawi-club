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

    const response=await call('Runtime.evaluate',{userGesture:true,returnByValue:true,awaitPromise:true,expression:`(async()=>{
        const canvas=document.createElement('canvas');canvas.width=320;canvas.height=180;document.body.append(canvas);
        const ctx=canvas.getContext('2d');let frame=0;
        const draw=()=>{ctx.fillStyle='#111111';ctx.fillRect(0,0,320,180);ctx.fillStyle='#ff5a4f';ctx.fillRect((frame*3)%280,30,40,90);ctx.fillStyle='#fff8f2';ctx.font='18px sans-serif';ctx.fillText('QA real media '+frame++,20,155);};draw();
        const audio=new AudioContext();await audio.resume();const oscillator=audio.createOscillator();const gain=audio.createGain();const dest=audio.createMediaStreamDestination();oscillator.frequency.value=440;gain.gain.value=.02;oscillator.connect(gain).connect(dest);oscillator.start();
        const stream=new MediaStream([...canvas.captureStream(10).getVideoTracks(),...dest.stream.getAudioTracks()]);
        const recorder=new MediaRecorder(stream,{mimeType:'video/webm;codecs=vp8,opus',videoBitsPerSecond:180000,audioBitsPerSecond:24000});const chunks=[];
        recorder.ondataavailable=e=>chunks.push(e.data);const stopped=new Promise(resolve=>recorder.onstop=resolve);const timer=setInterval(draw,100);recorder.start();
        await new Promise(resolve=>setTimeout(resolve,12000));recorder.stop();await stopped;clearInterval(timer);oscillator.stop();stream.getTracks().forEach(t=>t.stop());await audio.close();
        const bytes=new Uint8Array(await new Blob(chunks,{type:recorder.mimeType}).arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=16384)binary+=String.fromCharCode(...bytes.subarray(i,i+16384));return{base64:btoa(binary),mime:recorder.mimeType,bytes:bytes.length};
    })()`});
    assert(!response.exceptionDetails,JSON.stringify(response.exceptionDetails));const clip=response.result.value;
    await writeFile(output+'/qa-video.webm',Buffer.from(clip.base64,'base64'));
    await writeFile(output+'/qa-video-generation.json',JSON.stringify({mime:clip.mime,bytes:clip.bytes,source:'Canvas + oscillator recorded by native Chromium MediaRecorder',simulated_clock:false},null,2));
    console.log(JSON.stringify({generated:true,bytes:clip.bytes}));
}finally{closing=true;socket?.close();chrome.kill('SIGTERM');}
