// Focused browser contract: all writes intercepted; real provider smoke is read only.
const {chromium}=require('/tools/node_modules/playwright');
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const live=process.argv.includes('--live'),origin='https://jakawi.club',build=live?'/live-build':'/workspace/public/build-next';
const fixture=`window.__calls=[];window.YT={Player:class{constructor(mount,options){this.time=0;this.muted=true;this.volume=100;window.__player=this;window.__options=options;this.iframe=document.createElement('iframe');this.iframe.src='about:blank';mount.replaceWith(this.iframe);setTimeout(()=>options.events.onReady({target:this}),10);}playVideo(){window.__calls.push('play');setTimeout(()=>window.__options.events.onStateChange({data:1}),10);}pauseVideo(){window.__options.events.onStateChange({data:2});}seekTo(t){this.time=t;}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return this.volume;}setVolume(v){this.volume=v;}getCurrentTime(){return this.time;}getDuration(){return 20;}destroy(){this.iframe.remove();}}};window.onYouTubeIframeAPIReady?.();`;
(async()=>{
 const browser=await chromium.launch({executablePath:'/browser/chrome',args:['--no-sandbox','--disable-dev-shm-usage','--renderer-process-limit=1']});const checks=[],errors=[];
 try{
 const baseCtx=await browser.newContext();const response=await baseCtx.request.get(origin+'/');assert.equal(response.status(),200);let html=await response.text();await baseCtx.close();
 const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
 const original=JSON.parse(html.match(pattern)[2]);
 if(!live){const old=JSON.parse(fs.readFileSync('/live-build/manifest.json')),next=JSON.parse(fs.readFileSync(build+'/manifest.json'));for(const [key,entry]of Object.entries(old)){if(next[key]){html=html.replaceAll(entry.file,next[key].file);(entry.css||[]).forEach((f,i)=>{if(next[key].css?.[i])html=html.replaceAll(f,next[key].css[i]);});}}}
 for(const width of (live?[]:[390,1440])){
  const ctx=await browser.newContext({viewport:{width,height:900},isMobile:width===390,hasTouch:width===390,serviceWorkers:'block'});const metrics=[];
  await ctx.addCookies([{name:'XSRF-TOKEN',value:'browser-only',domain:'jakawi.club',path:'/'}]);
  await ctx.route('**/*',async r=>{
   const req=r.request(),url=new URL(req.url());
   if(!['GET','HEAD'].includes(req.method())){if(url.pathname==='/medicion')metrics.push(req.postDataJSON());return r.fulfill({status:204});}
   if(url.href==='https://www.youtube.com/iframe_api')return r.fulfill({contentType:'application/javascript',body:fixture});
   if(url.hostname==='jakawi.club'&&url.pathname.startsWith('/__vsl-loop/')){
    const data=structuredClone(original);data.url=url.pathname;data.props.landing.preview=false;data.props.landing.content.presentation.template='LEAD_VSL';data.props.landing.content.presentation.show_video=true;data.props.launch.video_url='https://youtu.be/M7lc1UVf-VE';
    if(url.pathname.endsWith('manual'))data.props.landing.content.video.loop=false;else delete data.props.landing.content.video.loop;
    return r.fulfill({contentType:'text/html',body:html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(data).replaceAll('<','\\u003c')+c)});
   }
   if(!live&&url.hostname==='jakawi.club'&&url.pathname.startsWith('/build/')){const file=build+'/'+url.pathname.slice(7);if(fs.existsSync(file))return r.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined});}
   return r.continue();
  });
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/__vsl-loop/default',{waitUntil:'domcontentloaded'});await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();
  const finish=async(muted)=>{const starts=metrics.filter(x=>x.event==='video_start').length;await page.evaluate(()=>{window.__player.time=20;window.__options.events.onStateChange({data:0});});await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.__player.time),0);assert.equal(await page.evaluate(()=>window.__player.muted),muted);assert.equal(await page.getByRole('button',{name:'Volver a ver',exact:true}).count(),0);assert.equal(metrics.filter(x=>x.event==='video_start').length,starts);};
  await finish(true);await finish(true);await page.locator('[data-vsl-sound]').click();await page.waitForFunction(()=>!window.__player.muted);await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();await finish(false);await finish(false);
  await page.getByRole('button',{name:'Pausar video',exact:true}).click();await page.getByRole('button',{name:'Continuar',exact:true}).click();await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();
  await page.goto(origin+'/__vsl-loop/manual',{waitUntil:'domcontentloaded'});await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();await page.evaluate(()=>window.__options.events.onStateChange({data:0}));await page.getByRole('button',{name:'Volver a ver',exact:true}).waitFor();await page.getByRole('button',{name:'Volver a ver',exact:true}).click();await page.getByRole('button',{name:'Pausar video',exact:true}).waitFor();
  checks.push({width,mutedLoop:true,audibleLoop:true,manualReplay:true,pauseResume:true,noDuplicateLoopMetrics:true});await ctx.close();
 }
 assert.deepEqual(errors,[]);
 if(live){
  const ctx=await browser.newContext({viewport:{width:390,height:844}});await ctx.route('**/*',r=>['GET','HEAD'].includes(r.request().method())?r.continue():r.fulfill({status:204}));
  await ctx.addInitScript(()=>{window.onYouTubeIframeAPIReady=()=>{const Native=window.YT.Player;window.YT.Player=function(mount,options){const p=new Native(mount,options);window.__realPlayer=p;return p;};};});
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));assert.equal((await page.goto(origin+'/',{waitUntil:'domcontentloaded'})).status(),200);
  await page.waitForFunction(()=>window.__realPlayer?.getPlayerState?.()===1,{},{timeout:30000});
  const first=await page.evaluate(()=>({time:window.__realPlayer.getCurrentTime(),muted:window.__realPlayer.isMuted()}));await page.waitForTimeout(1200);assert(await page.evaluate(t=>window.__realPlayer.getCurrentTime()>t,first.time));assert.equal(first.muted,true);
  checks.push({actualLiveLanding:true,realYoutubePlayback:true,mutedAutoplay:true});await ctx.close();
 }
 assert.deepEqual(errors,[]);fs.writeFileSync('/evidence/'+(live?'live-browser':'browser')+'.json',JSON.stringify({result:'PASS',checks,errors,no_live_mutations:true,manifest_sha256:crypto.createHash('sha256').update(fs.readFileSync(build+'/manifest.json')).digest('hex')},null,2));console.log('VSL browser PASS',checks);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
