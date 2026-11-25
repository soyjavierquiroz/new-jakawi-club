// Focused composition smoke. All application writes blocked; provider adapter is stubbed.
const {chromium}=require('/tools/node_modules/playwright');
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const platform=process.env.PLATFORM,live=process.argv.includes('--live');
const origin=platform==='com'?'https://jakawi.com':'https://jakawi.club',pathname=platform==='com'?'/l/gluteo-perfecto':'/';
const build=live?'/live-build':platform==='com'?'/source/public/build':'/source/public/build-next';
const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
const fixture=`window.YT={Player:class{constructor(mount,o){this.time=1;this.muted=true;this.o=o;const f=document.createElement('iframe');f.src='about:blank';mount.replaceWith(f);setTimeout(()=>o.events.onReady({target:this}),10);}playVideo(){this.o.events.onStateChange({data:1});}pauseVideo(){this.o.events.onStateChange({data:2});}seekTo(t){this.time=t;}mute(){this.muted=true;}unMute(){this.muted=false;}isMuted(){return this.muted;}getVolume(){return 100;}setVolume(){}getCurrentTime(){return this.time;}getDuration(){return 20;}destroy(){}}};window.onYouTubeIframeAPIReady?.();`;
(async()=>{
 const browser=await chromium.launch({executablePath:'/browser/chrome',headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--renderer-process-limit=1']});const checks=[],errors=[];
 try{
 const request=await browser.newContext();const response=await request.request.get(origin+pathname);assert.equal(response.status(),200);let html=await response.text();await request.close();
 const original=JSON.parse(html.match(pattern)[2]);
 if(!live){const old=JSON.parse(fs.readFileSync('/live-build/manifest.json')),next=JSON.parse(fs.readFileSync(build+'/manifest.json'));for(const[key,entry]of Object.entries(old))if(next[key]){html=html.replaceAll(entry.file,next[key].file);(entry.css||[]).forEach((f,i)=>{if(next[key].css?.[i])html=html.replaceAll(f,next[key].css[i]);});}}
 for(const width of [390,1440]){
 const ctx=await browser.newContext({viewport:{width,height:900},serviceWorkers:'block'});
 await ctx.route('**/*',async r=>{
  const req=r.request(),url=new URL(req.url());
  if(url.href==='https://www.youtube.com/iframe_api')return r.fulfill({contentType:'application/javascript',body:fixture});
  if(!['GET','HEAD'].includes(req.method()))return r.fulfill({status:204});
  if(!live&&url.origin===origin&&url.pathname===pathname){
   const data=structuredClone(original);
   if(platform==='com'){
    const presets=JSON.parse(fs.readFileSync('/source/resources/content/gluteo-perfecto-visual.json'));data.props.blocks=data.props.blocks.map(b=>({...b,...presets[b.type]}));
   }else{
    const layouts={hero:'spotlight',event:'flow',benefits:'proof',opportunity:'split',early:'flow',steps:'flow',registration:'split',faq:'flow',final:'spotlight'};
    data.props.landing.content.presentation.webinar.sections=data.props.landing.content.presentation.webinar.sections.map(b=>({...b,layout:layouts[b.id],background:b.id==='final'?'dark':['opportunity','early'].includes(b.id)?'soft':'light'}));
   }
   return r.fulfill({contentType:'text/html',body:html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(data).replaceAll('<','\\u003c')+c)});
  }
  if(!live&&url.origin===origin&&url.pathname.startsWith('/build/')){const file=build+'/'+url.pathname.slice(7);if(fs.existsSync(file))return r.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined});}
  return r.continue();
 });
 const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(origin+pathname,{waitUntil:'domcontentloaded'});await page.locator('h1').first().waitFor();
 await page.getByRole('button',{name:'Pausar video',exact:true}).first().waitFor({timeout:20000});
 const mode=width===1440?'dark':'light';await page.evaluate(m=>document.documentElement.classList.toggle('dark',m==='dark'),mode);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'horizontal overflow');
 const sections=page.locator('[data-editorial-layout]');assert((await sections.count())>=9);
 const styles=await sections.evaluateAll(xs=>xs.map(x=>({margin:parseFloat(getComputedStyle(x).marginTop),radius:parseFloat(getComputedStyle(x).borderTopLeftRadius),border:parseFloat(getComputedStyle(x).borderTopWidth)})));
 for(const s of styles){assert.equal(s.margin,0);assert.equal(s.radius,0);assert.equal(s.border,0);}
 const flow=page.locator('[data-editorial-layout="flow"]').first();assert(await flow.count());
 if(platform==='com'){
  assert.equal(original.props.canBuy,false);assert.equal(original.props.prelaunch,true);
  assert.equal(await page.locator('#oferta a').first().getAttribute('href'),'#oferta');
  const cards=page.locator('[data-editorial-section="benefits"] .editorial-card');assert(await cards.count()>0);for(const c of await cards.evaluateAll(xs=>xs.map(x=>({r:getComputedStyle(x).borderRadius,b:getComputedStyle(x).backgroundColor})))){assert.equal(c.r,'0px');assert.equal(c.b,'rgba(0, 0, 0, 0)');}
 }else{assert.equal(await page.locator('form input[type="email"]').count(),1);assert(await page.locator('[data-marketing-cta]').count()>0);}
 await page.locator('details summary').first().click();
 if(!live || process.env.CAPTURE_LIVE==='1'){await page.evaluate(()=>{document.querySelectorAll('details[open]').forEach(x=>x.removeAttribute('open'));window.scrollTo(0,0);});await page.waitForTimeout(100);await page.screenshot({path:`/evidence/${platform}-${width}.png`,fullPage:true,animations:'disabled',timeout:20000});}
 checks.push({width,mode,flow:true,exterior_boxes:false,vsl_adapter:true,action:true});await ctx.close();
 }
 assert.deepEqual(errors,[]);fs.writeFileSync('/evidence/'+(live?'live-ux':'browser')+'.json',JSON.stringify({result:'PASS',checks,errors,no_live_mutations:true,provider_fixture:true,manifest_sha256:crypto.createHash('sha256').update(fs.readFileSync(build+'/manifest.json')).digest('hex')},null,2));console.log(platform+' editorial flow PASS');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
