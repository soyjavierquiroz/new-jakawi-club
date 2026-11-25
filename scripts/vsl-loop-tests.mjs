import assert from 'node:assert/strict';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import ts from '/workspace/node_modules/typescript/lib/typescript.js';
const tmp=await mkdtemp('/tmp/vsl-loop-');
try {
 for(const name of ['types','youtube-crop','youtube-engine','landing-video-props']){
  const source=await readFile(`/workspace/resources/js/components/media/jakawi-vsl-player/${name}.ts`,'utf8');
  const code=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText.replaceAll("'./types'","'./types.mjs'").replaceAll("'./youtube-crop.js'","'./youtube-crop.mjs'");
  await writeFile(`${tmp}/${name}.mjs`,code);
 }
 const {createYoutubeEngine}=await import(`${tmp}/youtube-engine.mjs`);
 const {landingVideoProps}=await import(`${tmp}/landing-video-props.mjs`);
 const video={youtube_url:'https://youtu.be/M7lc1UVf-VE',source_aspect_ratio:'9:16'};
 assert.equal(landingVideoProps(video,video.youtube_url,null).loop,true);
 assert.equal(landingVideoProps({...video,loop:false},video.youtube_url,null).loop,false);
 globalThis.document={createElement:()=>({})};
 const host={append(){},querySelector:()=>null,replaceChildren(){}};
 for(const loop of [undefined,true,false]) for(const audible of [false,true]) {
  let callbacks,time=0,muted=true,volume=100,plays=0;
  const events=[];
  const player={playVideo(){plays++},pauseVideo(){},seekTo(t){time=t},mute(){muted=true},unMute(){muted=false},isMuted:()=>muted,getVolume:()=>volume,setVolume(v){volume=v},getCurrentTime:()=>time,getDuration:()=>20,destroy(){}};
  const engine=createYoutubeEngine(host,{source:{type:'youtube',url:video.youtube_url},origin:'https://jakawi.club',autoplay:true,loop,onState(){},onEvent:e=>events.push(e.type),loadApi:async()=>({Player:class{constructor(_,options){callbacks=options.events;return player}}})});
  await Promise.resolve();callbacks.onReady({target:player});assert.equal(muted,true);assert.equal(plays,1);
  callbacks.onStateChange({data:1});
  if(audible){engine.activateAudio();callbacks.onStateChange({data:1});}
  engine.setVolume(.6);const starts=events.filter(e=>e==='playing').length;
  for(let i=0;i<2;i++){
   time=20;const previous=plays;callbacks.onStateChange({data:0});
   if(loop===false){assert.equal(engine.getState().status,'ended');assert.equal(plays,previous);engine.play();}
   else {assert.notEqual(engine.getState().status,'ended');assert.equal(time,0);assert.equal(plays,previous+1);}
   callbacks.onStateChange({data:1});assert.equal(muted,!audible);assert.equal(volume,60);
  }
  if(loop!==false){assert(!events.includes('ended'));assert.equal(events.filter(e=>e==='playing').length,starts);}
  engine.pause();assert.equal(engine.getState().status,'paused');engine.play();callbacks.onStateChange({data:1});assert.equal(engine.getState().status,'playing');engine.dispose();
 }
 console.log('PASS: default/explicit loop, manual completion/replay, muted autoplay, audio retention, pause/resume, no repeated loop start/end events');
}finally{await rm(tmp,{recursive:true,force:true});}
