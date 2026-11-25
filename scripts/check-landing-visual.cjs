const {chromium}=require('/tools/node_modules/playwright');
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const platform=process.env.PLATFORM,live=process.argv.includes('--live');
const origin='https://jakawi.'+(platform==='com'?'com':'club'),pathname=platform==='com'?'/l/gluteo-perfecto':'/';
const build=live?'/live-build':(platform==='com'?'/source/public/build':'/source/public/build-next');
const pattern=/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
(async()=>{
 const browser=await chromium.launch({executablePath:'/browser/chrome',headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--renderer-process-limit=1']});
 const checks=[],errors=[];
 try{
 const request=await browser.newContext();const response=await request.request.get(origin+pathname);assert.equal(response.status(),200);let html=await response.text();await request.close();
 const original=JSON.parse(html.match(pattern)[2]);
 if(!live){const old=JSON.parse(fs.readFileSync('/live-build/manifest.json')),next=JSON.parse(fs.readFileSync(build+'/manifest.json'));for(const[key,entry]of Object.entries(old)){if(next[key]){html=html.replaceAll(entry.file,next[key].file);(entry.css||[]).forEach((f,i)=>{if(next[key].css?.[i])html=html.replaceAll(f,next[key].css[i]);});}}}
 for(const width of [390,1440]){
 const ctx=await browser.newContext({viewport:{width,height:900},serviceWorkers:'block'});
 await ctx.route('**/*',async r=>{
  const req=r.request(),url=new URL(req.url());
  if(!['GET','HEAD'].includes(req.method()))return r.fulfill({status:204});
  if(!live&&url.hostname===new URL(origin).hostname&&url.pathname===pathname){
   const data=structuredClone(original);
   if(platform==='com'){
    const presets=JSON.parse(fs.readFileSync('/source/resources/content/gluteo-perfecto-visual.json'));
    data.props.blocks=data.props.blocks.map(b=>({...b,...presets[b.type]}));
   }else{
    data.props.landing.content.presentation.webinar.sections=data.props.landing.content.presentation.webinar.sections.map(b=>({...b,background:b.id==='opportunity'?'dark':b.id==='early'?'soft':b.background}));
   }
   return r.fulfill({contentType:'text/html',body:html.replace(pattern,(_,a,_b,c)=>a+JSON.stringify(data).replaceAll('<','\\u003c')+c)});
  }
  if(!live&&url.hostname===new URL(origin).hostname&&url.pathname.startsWith('/build/')){const file=build+'/'+url.pathname.slice(7);if(fs.existsSync(file))return r.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined});}
  return r.continue();
 });
 const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await page.goto(origin+pathname,{waitUntil:'domcontentloaded'})).status(),200);
 await page.locator('h1').first().waitFor();await page.locator('[data-marketing-video-frame] iframe').first().waitFor({timeout:30000});
 for(const mode of ['light','dark']){
  await page.evaluate(m=>{document.documentElement.classList.toggle('dark',m==='dark');document.documentElement.dataset.appearance=m;},mode);
  await page.waitForTimeout(150);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow '+width+' '+mode);
  const selector=platform==='com'?'[data-editorial-section="problem"]':'[data-webinar-section="opportunity"]';
  if(platform==='com'||!live){assert.equal(await page.locator(selector).getAttribute('data-section-preset'),'dark');const color=await page.locator(selector).evaluate(el=>({bg:getComputedStyle(el).backgroundColor,fg:getComputedStyle(el).color}));assert.notEqual(color.bg,'rgba(0, 0, 0, 0)');assert.notEqual(color.bg,color.fg);}
  if(platform==='com'){
   const textColors=await page.locator('[data-section-preset] p.text-muted-foreground').evaluateAll(elements=>elements.map(el=>{let parent=el;let bg='rgba(0, 0, 0, 0)';while(parent&&bg==='rgba(0, 0, 0, 0)'){bg=getComputedStyle(parent).backgroundColor;parent=parent.parentElement;}return{fg:getComputedStyle(el).color,bg};}));
   const lum=c=>{const channels=c.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return channels[0]*.2126+channels[1]*.7152+channels[2]*.0722;};
   for(const c of textColors){const a=lum(c.fg),b=lum(c.bg);assert((Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5,'body contrast '+JSON.stringify(c));}
  }
  if((width===390&&mode==='light')||(width===1440&&mode==='dark'))await page.screenshot({path:`/evidence/${live?'live':'candidate'}-${width}-${mode}.png`,fullPage:false, animations:'disabled', timeout:15000});
  checks.push({width,mode,overflow:false,vsl:true});
 }
 if(platform==='com'){
  assert.equal(original.props.canBuy,false);assert.equal(original.props.prelaunch,true);
  const link=page.locator('#oferta a').first();assert.equal(await link.getAttribute('href'),'#oferta');
  await page.locator('details summary').first().click();
 }else{
  assert.equal(await page.locator('form').count()>=1,true);
  assert.equal(await page.locator('form input[type="email"]').count(),1);
 }
 // Browser-only layout probe: use an authorized existing asset, never persist fixture images.
 if(!live){
  if(platform==='com')await page.evaluate(()=>{const host=document.querySelector('[data-editorial-section="problem"] .editorial-composition');const f=document.createElement('figure');f.className='editorial-support-image';const img=document.createElement('img');img.alt='QA layout only';img.src='/jakawi-mark.svg';img.style.height='250px';f.append(img);host.append(f);});
  const grid=page.locator(platform==='com'?'[data-editorial-section="problem"] .editorial-composition':'.webinar-opportunity-with-image');
  if(await grid.count())for(const position of ['left','right']){await grid.evaluate((el,p)=>el.dataset.imagePosition=p,position);const positions=await grid.evaluate(el=>{const text=el.querySelector('div').getBoundingClientRect(),img=el.querySelector('figure,img').getBoundingClientRect();return{tx:text.x,ty:text.y,ix:img.x,iy:img.y};});if(width===390)assert(positions.iy>positions.ty,'mobile stack');else assert(position==='left'?positions.ix<positions.tx:positions.ix>positions.tx,'desktop image position');}
 }
 await ctx.close();
 }
 assert.deepEqual(errors,[]);fs.writeFileSync('/evidence/'+(live?'live-ux':'browser')+'.json',JSON.stringify({result:'PASS',checks,errors,no_live_mutations:true,manifest_sha256:crypto.createHash('sha256').update(fs.readFileSync(build+'/manifest.json')).digest('hex')},null,2));console.log(platform+' visual browser PASS');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
