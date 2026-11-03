// Restart Chromium against its persistent profile, without any authenticated requests.
import { spawn } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
const live=process.argv.includes('--live');
const chrome=spawn('/browser/chrome',['--headless=new','--no-sandbox','--single-process','--no-zygote','--disable-gpu','--disable-dev-shm-usage','--disable-background-networking','--remote-debugging-port=9223','--user-data-dir=/tmp/jakawi-visual','--no-first-run'],{stdio:'ignore'});
let socket;
try {
    let target;
    for(let i=0;i<60;i++){try{target=await(await fetch('http://127.0.0.1:9223/json/new?about:blank',{method:'PUT'})).json();break;}catch{await new Promise(r=>setTimeout(r,100));}}
    assert(target,'reopened browser starts');
    socket=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    let id=0;const pending=new Map();
    const call=(method,params={})=>new Promise((resolve,reject)=>{const requestId=++id;pending.set(requestId,{resolve,reject});socket.send(JSON.stringify({id:requestId,method,params}));});
    const blade=await readFile('/workspace/resources/views/app.blade.php','utf8');
    const boot=blade.match(/<script>[\s\S]*?<\/script>/)[0];
    socket.onmessage=({data})=>{const event=JSON.parse(data);if(event.id){const p=pending.get(event.id);pending.delete(event.id);event.error?p.reject(new Error(event.error.message)):p.resolve(event.result);}else if(event.method==='Fetch.requestPaused'){void call('Fetch.fulfillRequest',{requestId:event.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from(`<html data-account-context><head>${boot}</head><body>Restart probe</body></html>`).toString('base64')});}};
    await call('Page.enable');await call('Runtime.enable');
    await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await call('Page.navigate',{url:'https://jakawi.club/mi-cuenta'});
    let result;
    for(let i=0;i<50;i++) {result=await call('Runtime.evaluate',{expression:'JSON.stringify({stored:localStorage.getItem("jakawi.club.appearance"),resolved:document.documentElement.dataset.accountTheme})',returnByValue:true});if(result.result.value?.includes('resolved'))break;await new Promise(r=>setTimeout(r,100));}
    assert.deepEqual(JSON.parse(result.result.value),{stored:'dark',resolved:'dark'},'closing and reopening browser preserves explicit theme before React');
    const reportPath=`/evidence/browser-${live?'live':'preview'}.json`;
    const report=JSON.parse(await readFile(reportPath,'utf8'));report.checks.push('Actual Chromium close/reopen: durable theme restored by head bootstrap before React');report.browser_restart=true;
    await writeFile(reportPath,JSON.stringify(report,null,2));console.log('PASS browser restart persistence');
} finally {if(socket)socket.close();chrome.kill('SIGTERM');}
