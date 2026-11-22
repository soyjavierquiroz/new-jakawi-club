// Browser-only HTTP fixtures: no LIVE users, submissions or preference writes.
const {chromium}=require('/tools/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const out='/evidence',root='/workspace',origin='https://jakawi.club',live=process.argv.includes('--live');
const fixtures=JSON.parse(fs.readFileSync(out+'/fixtures.json'));
const old=JSON.parse(fs.readFileSync('/live-build/manifest.json'));
const build=live?'/live-build':root+'/public/build-next';
const manifest=JSON.parse(fs.readFileSync(build+'/manifest.json'));
const checks=[],errors=[],contrasts=[];let active='login',serverTheme='system',saves=[];
const labels={light:'Claro',dark:'Oscuro',system:'Sistema'};
const trigger=p=>p.getByRole('button',{name:/^Apariencia:/}).filter({visible:true}).first();
async function ready(p){await trigger(p).waitFor();await p.waitForLoadState('networkidle');}
async function choose(p,mode){await trigger(p).click();await p.getByRole('menuitemradio',{name:labels[mode],exact:true}).click();await p.waitForFunction(v=>document.documentElement.dataset.appearance===v,mode);}
async function shot(p,label,width,mode){
 await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(200);
 assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' overflow');
 const contrast=await p.evaluate(()=>{
  const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d',{willReadFrequently:true});
  const rgb=color=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=color;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data];};
  const lum=c=>c.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
  const failures=[];
  for(const el of document.querySelectorAll('.appearance-control button,.appearance-menu span')){
   const r=el.getBoundingClientRect(),s=getComputedStyle(el);if(!r.width||!r.height)continue;
   let bg=[255,255,255,255],layers=[];for(let n=el;n;n=n.parentElement)layers.push(rgb(getComputedStyle(n).backgroundColor));
   for(const c of layers.reverse()){const a=c[3]/255;bg=bg.map((v,i)=>i<3?c[i]*a+v*(1-a):255);}
   const fg=rgb(s.color),[a,b]=[lum(fg),lum(bg)].sort((a,b)=>a-b),ratio=(b+.05)/(a+.05);
   if(ratio<4.5)failures.push({text:el.textContent,ratio});
  }return failures;
 });contrasts.push({label,width,mode,failures:contrast});assert.equal(contrast.length,0,JSON.stringify(contrast));
 await p.screenshot({path:`${out}/${live?'live':'qa'}-${label}-${mode}-${width}.png`});
}
(async()=>{
 const browser=await chromium.launch({executablePath:'/browser/chrome',args:['--no-sandbox','--disable-dev-shm-usage','--renderer-process-limit=1']});
 try{for(const width of [Number(process.argv.find(a=>/^--width=/.test(a))?.split('=')[1]||390)]){
  const c=await browser.newContext({viewport:{width,height:900},colorScheme:'light',serviceWorkers:'block'});
  c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
  await c.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());
   const fulfill=(body,type='text/html',status=200)=>route.fulfill({body,contentType:type,status});
   if(req.method()!=='GET'){
    assert.equal(url.pathname,'/settings/appearance','Unexpected write');assert.equal(req.method(),'PATCH');serverTheme=req.postDataJSON().theme_preference;saves.push(serverTheme);return fulfill('{}','application/json');
   }
   if(url.pathname.startsWith('/build/')){const file=path.join(build,url.pathname.slice(7));assert(file.startsWith(build+'/'));return fulfill(fs.readFileSync(file),file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'application/javascript');}
   if(req.isNavigationRequest()){
    const fixture=fixtures[active],page=structuredClone(fixture.page);let html=fixture.html;
    for(const [key,entry]of Object.entries(old)){const next=manifest[key];if(!next)continue;html=html.replaceAll('/build/'+entry.file,'/build/'+next.file);for(let i=0;i<(entry.css||[]).length;i++)if(next.css?.[i])html=html.replaceAll('/build/'+entry.css[i],'/build/'+next.css[i]);}
    if(page.props.appearance_identity){page.props.theme_preference=serverTheme;html=html.replace(/window.__appearance = .*?;/,'window.__appearance = '+JSON.stringify({identity:page.props.appearance_identity,preference:serverTheme})+';');}
    html=html.replace(/(<script\b[^>]*data-page="app"[^>]*>)[\s\S]*?(<\/script>)/,(_,a,b)=>a+JSON.stringify(page).replaceAll('<','\\u003c')+b);
    return fulfill(html);
   }
   if(url.pathname==='/__qa-video.webm')return fulfill(fs.readFileSync('/checks/fixtures/appearance-video.webm'),'video/webm');
   const file=path.join(root,'public',url.pathname);if(url.hostname==='jakawi.club'&&fs.existsSync(file)&&fs.statSync(file).isFile())return fulfill(fs.readFileSync(file),file.endsWith('.svg')?'image/svg+xml':'image/png');
   if(req.resourceType()==='image')return fulfill(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9l0AAAAASUVORK5CYII=','base64'),'image/png');
   return fulfill(req.resourceType()==='script'?'':'{}',req.resourceType()==='script'?'application/javascript':'application/json');
  });
  const p=await c.newPage();
  for(const name of ['LEAD_VSL','login','account','affiliates','admin']){
   active=name;serverTheme='system';assert(fixtures[name],name+' fixture');await p.goto(origin+fixtures[name].url);await ready(p);
   assert.equal(await p.locator('select[aria-label="Apariencia"]').count(),0);
   const size=await trigger(p).boundingBox();assert(size.width>=44&&size.height>=44);
   for(const mode of ['light','dark','system']){
    await choose(p,mode);assert.equal(await trigger(p).getAttribute('aria-label'),'Apariencia: '+labels[mode]);
    assert.equal(await trigger(p).locator('[data-appearance-icon='+mode+']').count(),1);
    await trigger(p).focus();await p.keyboard.press('Enter');
    assert.equal(await p.getByRole('menuitemradio',{name:labels[mode],exact:true}).getAttribute('aria-checked'),'true');
    await shot(p,name,width,mode);await p.keyboard.press('Escape');assert(await trigger(p).evaluate(e=>e===document.activeElement));
    await p.reload();await ready(p);assert.equal(await trigger(p).getAttribute('aria-label'),'Apariencia: '+labels[mode]);
   }
   await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.classList.contains('dark'));await p.emulateMedia({colorScheme:'light'});await p.waitForFunction(()=>!document.documentElement.classList.contains('dark'));
   await trigger(p).focus();await p.keyboard.press('ArrowDown');await p.keyboard.press('End');assert.equal(await p.getByRole('menuitemradio',{name:'Sistema',exact:true}).evaluate(e=>e===document.activeElement),true);await p.keyboard.press('Home');await p.keyboard.press('Enter');await p.waitForFunction(()=>document.documentElement.dataset.appearance==='light');
   await trigger(p).click();await p.keyboard.press('Tab');assert.equal(await p.getByRole('menu').count(),0);
   const tab=await c.newPage();await tab.goto(origin+fixtures[name].url);await ready(tab);await choose(p,'dark');await tab.waitForFunction(()=>document.documentElement.dataset.appearance==='dark');await tab.close();
   checks.push({width,name,allModes:true,persistenceReload:true,keyboard:true,crossTab:true,systemMedia:true,touch44:true,contrast:true});
  }
  active='login';await p.goto(origin+fixtures.login.url);await ready(p);const input=p.locator('input[name=email]');await input.fill('local-unsaved@qa.invalid');await choose(p,'dark');assert.equal(await input.inputValue(),'local-unsaved@qa.invalid');
  // Real native media probe tests menu changes without modifying any VSL implementation.
  await p.evaluate(()=>{const v=document.createElement('video');v.src='/__qa-video.webm';v.muted=true;v.loop=true;v.dataset.qa='same';document.body.append(v);});await p.waitForFunction(()=>document.querySelector('video[data-qa]')?.readyState>=2);await p.locator('video[data-qa]').evaluate(v=>v.play());
  for(const mode of ['light','dark','system']){await choose(p,mode);assert(await p.locator('video[data-qa]').evaluate(v=>!v.paused&&v.dataset.qa==='same'));}
  await p.locator('video[data-qa]').evaluate(v=>v.pause());const time=await p.locator('video[data-qa]').evaluate(v=>v.currentTime);await choose(p,'dark');assert.equal(await p.locator('video[data-qa]').evaluate(v=>v.currentTime),time);
  checks.push({width,formPreserved:true,nativeVideoNodePlaybackAndPausePreserved:true});await c.close();
 }
 assert.deepEqual(errors,[]);assert(saves.length>0);
 fs.writeFileSync(out+'/'+(live?'live-browser':'browser')+'-'+process.argv.find(a=>/^--width=/.test(a)).split('=')[1]+'.json',JSON.stringify({result:'PASS',checks,contrasts,errors,no_live_mutations:true,accountSavesIntercepted:saves.length,manifest_sha256:crypto.createHash('sha256').update(fs.readFileSync(build+'/manifest.json')).digest('hex')},null,2));console.log('PASS',checks.length,'cases');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
