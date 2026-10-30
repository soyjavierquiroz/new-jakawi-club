<?php

// Real HTTP kernel, real migrations, private PostgreSQL schema; no LIVE fixtures.
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

chdir('/var/www/html');
foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $dir) {
    @mkdir('storage/'.$dir, 0777, true);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'jakawi_club') {
    throw new RuntimeException('Test isolation required.');
}
$schema = 'catalog_test_'.bin2hex(random_bytes(6));
$passed = [];
$cookies = [];
$secret = str_repeat('c', 64);
file_put_contents('/tmp/commercial-test-secret', $secret);

function check(bool $condition, string $name): void {
    global $passed;
    if (! $condition) throw new RuntimeException('FAILED: '.$name);
    $passed[] = $name; echo 'PASS '.$name.PHP_EOL;
}
function kernelRequest(Request $request): Symfony\Component\HttpFoundation\Response {
    global $app;
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}
function send(mixed $body, array $headers = [], string $path = '/internal/commercial/events', ?string $key = null, ?int $time = null): Symfony\Component\HttpFoundation\Response {
    global $secret;
    $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    $time ??= time(); $key ??= $secret;
    $server = array_merge(['HTTP_ACCEPT'=>'application/json', 'CONTENT_TYPE'=>'application/json',
        'HTTP_X_JAKAWI_COM_KEY_ID'=>'com-commercial-v1', 'HTTP_X_JAKAWI_COM_TIMESTAMP'=>(string)$time,
        'HTTP_X_JAKAWI_COM_SIGNATURE'=>'sha256='.hash_hmac('sha256', $time."\nPOST\n/internal/commercial/events\n".hash('sha256', $raw), $key)], $headers);
    return kernelRequest(Request::create('https://jakawi.club'.$path, 'POST', [], [], [], $server, $raw));
}
function payload(Symfony\Component\HttpFoundation\Response $r): array {
    return json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
function web(string $method, string $path, array $data = [], bool $inertia = true): Symfony\Component\HttpFoundation\Response {
    global $cookies;
    Auth::forgetGuards();
    $server = ['HTTP_ACCEPT'=>'text/html', 'REMOTE_ADDR'=>'192.0.2.30'];
    if ($inertia) {
        $server['HTTP_X_INERTIA']='true';
        $server['HTTP_X_INERTIA_VERSION']=(new App\Http\Middleware\HandleInertiaRequests)->version(Request::create('/'));
    }
    $r = kernelRequest(Request::create('https://jakawi.club'.$path, $method, $data, $cookies, [], $server));
    foreach ($r->headers->getCookies() as $cookie) $cookies[$cookie->getName()]=$cookie->getValue();
    foreach (app('cookie')->getQueuedCookies() as $cookie) app('cookie')->unqueue($cookie->getName(),$cookie->getPath());
    return $r;
}
function login(bool $admin, bool $verified = true, bool $activated = true): User {
    global $cookies;
    $cookies=[]; Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate();
    $user=User::create(['name'=>'Commercial fixture', 'email'=>bin2hex(random_bytes(5)).'@example.test', 'password'=>'FixturePassword123']);
    $user->forceFill(['activated_at'=>$activated?now():null,'email_verified_at'=>$verified?now():null,'is_marketing_admin'=>$admin])->save();
    web('POST','/login',['email'=>$user->email,'password'=>'FixturePassword123'],false);
    return $user->fresh();
}

function mockHttp(array $callbacks = []): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($callbacks);
}
function item(int $id, string $type = 'benefit', string $state = 'available'): array {
    return ['id'=>$type.':'.$id,'com_id'=>$id,'type'=>$type,'name'=>$id===1?'Dossis 43 — Café y antojo':'Oportunidad '.$id,
        'short_description'=>'Una oportunidad para compartir.','image_url'=>$id===1?'https://jakawi.club/jakawi-logo.svg':null,
        'partners'=>[['id'=>1,'name'=>'Dossis 43 | Coffee'],['id'=>2,'name'=>'Otro Partner público']],
        'cities'=>['Cochabamba','La Paz'],'category'=>'cafe','landing_url'=>'https://jakawi.com/l/'.($id===1?'dossis-43-cafe-y-antojo':'oferta-'.$id),
        'publication_status'=>'published','landing_status'=>'PUBLISHED','availability_status'=>$state,
        'updated_at'=>'2026-10-14T23:52:24.000000Z','starts_at'=>null,'ends_at'=>null];
}
function feed(array $items, int $size = 2): void {
    $pages=max(1,(int)ceil(count($items)/$size)); $responses=[];
    for($page=1;$page<=$pages;$page++) $responses[]=Http::response([
        'data'=>array_slice($items,($page-1)*$size,$size),
        'meta'=>['current_page'=>$page,'per_page'=>$size,'total'=>count($items),'last_page'=>$pages],
        'links'=>['next'=>$page<$pages?'https://jakawi.com/api/catalog/v1/offers?page='.($page+1).'&per_page='.$size:null,'prev'=>null],
    ]);
    mockHttp(['https://jakawi.com/api/catalog/v1/offers*'=>Http::sequence($responses)]);
}
function publicLink(string $code): Symfony\Component\HttpFoundation\Response {
    Auth::forgetGuards();
    return kernelRequest(Request::create('https://jakawi.club/r/'.$code.'?club_ref=ATTACKER', 'GET', [], [], [], ['HTTP_ACCEPT'=>'text/html','REMOTE_ADDR'=>'192.0.2.71']));
}
function protectedSnapshot(): array {
    $result=[];
    foreach(['users','commercial_events','commercial_event_conflicts','commission_rules','commission_ledger','commission_audit','jobs','affiliate_short_links'] as $table) {
        $rows=DB::table($table)->get()->map(fn($v)=>(array)$v)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
        $result[$table]=hash('sha256',json_encode($rows));
    }
    return $result;
}
function catalogSnapshot(): string { return DB::table('commercial_offers')->orderBy('key')->get()->toJson(); }
function sync(): array { return app(App\Services\ComCatalogSync::class)->run(); }
try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false]);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name===$schema
        && User::resolveConnection()->selectOne('select current_schema() as name')->name===$schema,
        'query and Eloquent isolation verified BEFORE migrations or fixture writes');
    Artisan::call('migrate',['--force'=>true]);
    app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    Notification::fake(); Http::preventStrayRequests(); mockHttp();
    check(web('POST','/afiliados/enlaces/dossis-43')->isRedirect('https://jakawi.club/login'),'guest cannot generate');
    $inactive=login(false,true,false);
    check(in_array(web('POST','/afiliados/enlaces/dossis-43')->getStatusCode(),[302,403],true) && DB::table('affiliate_short_links')->count()===0,'inactive account cannot generate');
    $unverified=login(false,false,true);
    check(web('POST','/afiliados/enlaces/dossis-43')->getStatusCode()===302,'unverified account cannot generate');
    $a=login(false);$aCookies=$cookies;
    // Simulate the existing production pilot in this verified isolated schema only.
    $old=app(App\Services\AffiliateShortLinks::class)->forAffiliate($a,'dossis-43');
    DB::table('affiliate_short_links')->where('code',$old->code)->update(['code'=>'Ms8xvfMr']);
    $old=DB::table('affiliate_short_links')->where('code','Ms8xvfMr')->first();
    $event=['schema_version'=>1,'event_id'=>'catalog:historic','event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T12:00:00Z',
        'com_reference'=>['type'=>'order','id'=>'historic-fixture'],'conversion_status'=>'confirmed','partner'=>['id'=>'fixture-dossis','name'=>'Dossis 43'],
        'referral'=>['club_referral_code'=>$a->referral_code]];
    check(send($event)->getStatusCode()===201,'historical conversion established in isolation');
    $protected=protectedSnapshot();
    $items=[item(1),item(2),item(5,'experience','scheduled')];
    feed($items);$counts=sync();
    check($counts===['offers'=>3,'pages'=>2] && DB::table('commercial_offers')->count()===3,'complete multipage traversal');
    check(DB::table('commercial_offers')->where('source_id','benefit:1')->value('key')==='dossis-43'
        && !DB::table('commercial_offers')->where('key','benefit:1')->exists(),'real Dossis identity attached to existing pilot without duplicate');
    check(protectedSnapshot()===$protected,'sync preserves every link identity conversion and commission byte');
    feed($items);sync();
    check(DB::table('commercial_offers')->count()===3 && protectedSnapshot()===$protected,'repeat sync idempotent with no links generated');
    $offers=app(App\Services\CommercialCatalog::class);
    check($offers->query()->where('source_id','experience:5')->value('effective_state')==='active','scheduled is promotable');
    $cookies=$aCookies;
    $explore=payload(web('GET','/afiliados'));
    check($explore['props']['offers']['total']===3 && DB::table('affiliate_short_links')->count()===1,'GET catalog generates zero links');
    check(payload(web('GET','/afiliados?q=Otro%20Partner'))['props']['offers']['total']===3,'search matches every real partner');
    check(payload(web('GET','/afiliados?type=experience&city=La%20Paz&availability=active'))['props']['offers']['total']===1,'combined type city availability filters');
    check(payload(web('GET','/afiliados?city=Santa%20Cruz'))['props']['offers']['total']===0,'unknown city does not fabricate availability');
    $beforeHttp=Http::recorded()->count();
    check(publicLink($old->code)->headers->get('Location')===$items[0]['landing_url'].'?club_ref='.$a->referral_code,'existing pilot redirect preserves true affiliate attribution');
    $items[0]['landing_url']='https://jakawi.com/l/dossis-nuevo-slug?utm_source=club&club_ref=old';
    feed($items);sync();
    check(publicLink($old->code)->headers->get('Location')==='https://jakawi.com/l/dossis-nuevo-slug?utm_source=club&club_ref='.$a->referral_code,'slug changes keep code and replace stale club_ref');
    check(DB::table('commercial_offers')->where('key','dossis-43')->value('original_url')==='https://jakawi.com/l/dossis-43-cafe-y-antojo','original pilot URL retained');
    foreach(['upcoming'=>'upcoming','paused'=>'paused','unavailable'=>'paused','expired'=>'finished','ended'=>'finished','archived'=>'finished'] as $state=>$effective) {
        $items[0]['availability_status']=$state;feed($items);sync();
        check($offers->query()->where('key','dossis-43')->value('effective_state')===$effective,'map COM '.$state);
        $response=publicLink($old->code);
        check($response->getStatusCode()===200 && !$response->headers->get('Location') && str_contains($response->getContent(),'Dossis'),'informative original offer for '.$state);
        check(web('POST','/afiliados/enlaces/dossis-43')->getStatusCode()===409,'generation blocked for '.$state);
        if($effective==='finished')file_put_contents('/evidence/finished-fixture.html',$response->getContent());
        if($state==='paused')file_put_contents('/evidence/paused-fixture.html',$response->getContent());
    }
    $items[0]['availability_status']='available';$items[0]['ends_at']=now()->addHour()->utc()->toIso8601String();feed($items);sync();
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='active','UTC validity stored without four hour timezone drift');
    $items[0]['ends_at']=now()->subMinute()->utc()->toIso8601String();feed($items);sync();
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='finished','elapsed validity ends promotion between syncs');
    $items[0]['ends_at']=null;$items[0]['publication_status']='paused';feed($items);sync();
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='paused','publication pause wins over available');
    $items[0]['publication_status']='published';feed($items);sync();
    $snapshot=catalogSnapshot();
    foreach(['unreachable','invalid','truncated','duplicates','changed-total','foreign-next','second-page-500','invalid-record'] as $failure) {
        $p=['data'=>[$items[0]],'meta'=>['current_page'=>1,'per_page'=>1,'total'=>2,'last_page'=>2],'links'=>['next'=>'https://jakawi.com/api/catalog/v1/offers?page=2']];
        if($failure==='unreachable')mockHttp(['*'=>Http::failedConnection()]);
        elseif($failure==='invalid')mockHttp(['*'=>Http::response(['data'=>[]])]);
        elseif($failure==='truncated'){$p['links']['next']=null;mockHttp(['*'=>Http::response($p)]);}
        elseif($failure==='foreign-next'){$p['links']['next']='https://example.test/private';mockHttp(['*'=>Http::response($p)]);}
        elseif($failure==='second-page-500')mockHttp(['*'=>Http::sequence()->push($p)->pushStatus(503)]);
        elseif($failure==='invalid-record'){$p['data'][0]['id']='benefit:999';mockHttp(['*'=>Http::response($p)]);}
        else {$second=$p;$second['meta']['current_page']=2;$second['links']['next']=null;if($failure==='changed-total')$second['meta']['total']=3;mockHttp(['*'=>Http::sequence()->push($p)->push($second)]);}
        try {sync();check(false,'failure must throw');} catch(RuntimeException $e) {check(str_contains($e->getMessage(),'catálogo válido'),'safe error for '.$failure);}
        check(catalogSnapshot()===$snapshot && protectedSnapshot()===$protected,'atomic last-valid retention for '.$failure);
    }
    feed(array_slice($items,1));sync();
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='retired','absence after complete traversal retires without deleting');
    $response=publicLink($old->code);file_put_contents('/evidence/retired-fixture.html',$response->getContent());
    check(!$response->headers->get('Location') && str_contains($response->getContent(),'retirada'),'retired link displays its original information');
    $cookies=$aCookies;
    $history=payload(web('GET','/afiliados?tab=history'));
    check($history['props']['offers']['total']===1 && $history['props']['offers']['data'][0]['personal_url']==='https://jakawi.club/r/Ms8xvfMr'
        && $history['props']['conversions']['total']===1,'history retains link and historical results');
    feed($items);sync();
    $cookies=$aCookies;
    check(web('POST','/afiliados/enlaces/benefit:2')->isRedirect('https://jakawi.club/afiliados?tab=links'),'on-demand generation');
    $new=DB::table('affiliate_short_links')->where('affiliate_id',$a->id)->where('offer_key','benefit:2')->first();
    web('POST','/afiliados/enlaces/benefit:2');
    check(DB::table('affiliate_short_links')->count()===2 && $new->referral_code===$a->referral_code,'on-demand reuses pair and real CLUB code');
    $mine=payload(web('GET','/afiliados?tab=links'));
    check($mine['props']['offers']['total']===2 && $mine['props']['conversions']['total']===1,'my links only generated links and personal conversions');
    $b=login(false);$bCookies=$cookies;
    $bPage=payload(web('GET','/afiliados?tab=links'));
    check($bPage['props']['offers']['total']===0 && $bPage['props']['conversions']['total']===0
        && !str_contains(json_encode($bPage),'Ms8xvfMr') && !str_contains(json_encode($bPage),$new->code),'privacy between affiliates links results and quick access');
    check(web('PUT','/admin/ofertas-comerciales/dossis-43',['local_paused'=>true])->getStatusCode()===403,'affiliate cannot pause catalog');
    $admin=login(true);
    web('PUT','/admin/ofertas-comerciales/dossis-43',['local_paused'=>true,'destination_url'=>'https://evil.example/','availability_status'=>'available']);
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='paused'
        && DB::table('commercial_offers')->where('key','dossis-43')->value('destination_url')===$items[0]['landing_url'],'admin only local restriction cannot change COM destination');
    $items[0]['availability_status']='paused';feed($items);sync();
    web('PUT','/admin/ofertas-comerciales/dossis-43',['local_paused'=>false]);
    check($offers->query()->where('key','dossis-43')->value('effective_state')==='paused','local unpause cannot override COM unavailable');
    $adminPage=payload(web('GET','/admin/ofertas-comerciales'));
    check($adminPage['props']['last_success']['offers']===3,'admin displays complete sync origin and effective states');
    $items[0]['availability_status']='available';feed($items);sync();
    $offers->syncClub();
    check(DB::table('commercial_offers')->where('source','club')->count()===1,'only home landing supports current valid CLUB referral mechanism');
    $other=App\Models\MarketingLanding::create(['title'=>'Other public campaign','slug'=>'other-campaign','template'=>'LEAD_VSL','configuration'=>App\Support\LandingContent::defaults(),'published_configuration'=>App\Support\LandingContent::defaults(),'status'=>'published']);
    $offers->syncClub();
    check(DB::table('commercial_offers')->where('source','club')->count()===1,'other published campaigns excluded without attribution mechanism');
    $club=DB::table('commercial_offers')->where('source','club')->first();
    $cookies=$aCookies;
    check(web('POST','/afiliados/enlaces/'.$club->key)->getStatusCode()===302,'CLUB capture generated through same personal action');
    $clubLink=DB::table('affiliate_short_links')->where('affiliate_id',$a->id)->where('offer_key',$club->key)->first();
    check(publicLink($clubLink->code)->headers->get('Location')===$a->referralUrl(),'CLUB short link reuses real existing invitation route with no COM club_ref');
    $cookies=[];app('session')->flush();app('session')->regenerate();$legacy=web('GET','/r/'.$a->referral_code,[],false);
    check($legacy->isRedirect('https://jakawi.club/#registro'),'original invitation route remains intact');
    $home=payload(web('GET','/'));
    check($home['props']['referral_code']===$a->referral_code,'CLUB referral mechanism actually captures attribution on public landing');
    DB::table('marketing_landings')->where('id',(int)substr($club->source_id,8))->update(['status'=>'archived']);
    check(!publicLink($clubLink->code)->headers->get('Location'),'unpublished CLUB campaign stops redirect even before synchronization');
    $offers->syncClub();
    check($offers->query()->where('key',$club->key)->value('effective_state')==='retired','unpublished CLUB campaign kept historically');
    DB::table('marketing_landings')->where('id',(int)substr($club->source_id,8))->update(['status'=>'published']);$offers->syncClub();
    // Capture card release: all campaigns/accounts below remain in this private schema.
    $admin=login(true);
    $landing=App\Models\MarketingLanding::findOrFail((int)substr($club->source_id,8));
    $base='/admin/landings/'.$landing->id;
    $original=$landing->published_configuration;
    $draft=App\Support\LandingContent::withPresentation($landing->configuration);
    $draft['seo']['title']='Descubre tu próximo capítulo con JAKAWI';
    $draft['commercial']=['image'=>'/marketing/poster-fixture.png','description'=>'Comparte experiencias y descubre tu ciudad con JAKAWI.'];
    $oldCard=$offers->present($offers->query()->where('key',$club->key)->first());
    $data=['title'=>'Nombre público nuevo','slug'=>$landing->slug,'campaign'=>$landing->campaign,'configuration'=>$draft,'revision'=>$landing->revision];
    check(web('PUT',$base,$data)->isRedirect(),'commercial fields save through existing landing editor');
    $landing->refresh();
    check($landing->published_configuration===$original,'commercial draft does not mutate published snapshot');
    $offers->syncClub();
    $savedCard=$offers->present($offers->query()->where('key',$club->key)->first());
    check($savedCard===$oldCard || ($savedCard['name']===$oldCard['name'] && $savedCard['short_description']===$oldCard['short_description'] && $savedCard['image_url']===$oldCard['image_url']),'saving and scheduled sync do not leak commercial draft');
    $preview=payload(web('GET',$base.'/preview'));
    check($preview['props']['landing']['social']['name']===$draft['seo']['title'] && $preview['props']['landing']['social']['image_url']===$draft['commercial']['image'],'protected preview resolves draft commercial fields');
    check(!str_contains(web('GET','/',[],false)->getContent(),$draft['seo']['title']),'public HTML social metadata exclude draft');
    check(web('POST',$base.'/publicar',['revision'=>$landing->revision])->isRedirect(),'publish commercial snapshot through existing operation');
    $landing->refresh();
    $card=$offers->present($offers->query()->where('key',$club->key)->first());
    check($card['name']===$draft['seo']['title'] && $card['short_description']===$draft['commercial']['description'] && $card['image_url']===$draft['commercial']['image'],'publishing immediately updates catalog title description image');
    $html=web('GET','/',[],false)->getContent();
    check(str_contains($html,'property="og:title" data-inertia="og:title" content="'.$card['name'].'"') && str_contains($html,'property="og:image" data-inertia="og:image" content="https://jakawi.club/marketing/poster-fixture.png"') && str_contains($html,'name="twitter:description" data-inertia="twitter:description" content="'.$card['short_description'].'"'),'server HTML prepares social metadata without JavaScript');
    $editPath=route('landings.edit',$landing,false);
    $editor=payload(web('GET',$editPath));
    $ownCard=$card;
    $draft['commercial']=['image'=>null,'description'=>null];
    $draft['video']['poster']='/marketing/poster-fixture.png';
    $draft['hero']['description']=str_repeat('Descubre tu ciudad y comparte experiencias. ',12);
    $landing->configuration=$draft;$landing->save();
    check(web('POST',$base.'/publicar',['revision'=>$landing->revision])->isRedirect(),'publish poster fallback case');
    $landing->refresh();
    $posterCard=$offers->present($offers->query()->where('key',$club->key)->first());
    check($posterCard['image_url']==='/marketing/poster-fixture.png' && mb_strlen($posterCard['short_description'])<=201 && str_ends_with($posterCard['short_description'],'…'),'published poster and safely summarized hero fallback');
    $legacy=$landing->published_configuration;unset($legacy['commercial']);$legacy['video']['poster']=null;
    $landing->published_configuration=$legacy;$landing->save();
    $legacyCard=$offers->present($offers->query()->where('key',$club->key)->first());
    check($legacyCard['image_url']===null && $legacyCard['name']===$draft['seo']['title'],'legacy snapshot resolves brand fallback without YouTube extraction');
    $missing=$legacy;$missing['video']['poster']='/marketing/missing.png';$missing['commercial']=['image'=>'/marketing/missing.png'];
    check(App\Support\LandingCommercial::resolve($missing,'Nombre público')['image_url']===null,'deleted or missing local images never produce broken catalog thumbnails');
    $noTitle=$landing->published_configuration;$noTitle['seo']['title']='';$noTitle['commercial']['public_name']='Nombre público publicado';
    $landing->published_configuration=$noTitle;$landing->title='NOMBRE PRIVADO SIN PUBLICAR';$landing->save();
    check(App\Support\LandingCommercial::forLanding($landing)['name']==='Nombre público publicado','public name fallback is frozen in snapshot and never uses draft title');
    check(App\Support\LandingCommercial::text('<b>Hola</b> &amp; ciudad\n segura',200)==='Hola & ciudad\n segura','legacy text is stripped and entities decoded');
    $legacyHtml=web('GET','/',[],false)->getContent();
    check(str_contains($legacyHtml,'https://jakawi.club/marketing/club-card.png'),'historical social image has local brand fallback');
    foreach (['https://i.ytimg.com/vi/anything/default.jpg','/marketing/missing.png','/marketing/../private.png'] as $invalidImage) {
        $invalid=$draft;$invalid['commercial']['image']=$invalidImage;
        $beforeInvalid=$landing->fresh()->configuration;
        web('PUT',$base,[...$data,'revision'=>$landing->revision,'configuration'=>$invalid]);
        $invalidResponse=payload(web('GET',$editPath));
        check(isset($invalidResponse['props']['errors']['content.commercial.image']) && $landing->fresh()->configuration===$beforeInvalid,'commercial image validation reuses verified local media');
    }
    $imported=App\Support\LandingContent::import(json_encode(['schema'=>App\Support\LandingContent::V2_SCHEMA,'content'=>['hero'=>['title'=>'Texto v2']]]),$draft);
    check($imported['commercial']===$draft['commercial'],'editorial v2 patch preserves commercial fields without expanding contract');
    $landing->published_configuration=$original;$landing->save();$offers->syncClub();
    check(publicLink($clubLink->code)->headers->get('Location')===$a->referralUrl(),'commercial publication preserves existing short URL and attribution destination');
    $comAfter=DB::table('commercial_offers')->where('source','com')->orderBy('key')->get()->toJson();
    $offers->syncClub();
    check(DB::table('commercial_offers')->where('source','com')->orderBy('key')->get()->toJson()===$comAfter,'CLUB sync does not modify COM cards');
    $captureCards=['own'=>$ownCard,'poster'=>$posterCard,'legacy'=>$legacyCard,'editor'=>$editor,'preview'=>$preview];
    file_put_contents('/evidence/capture-cards-fixtures.json',json_encode($captureCards,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    // Enough isolated opportunities to exercise actual server pagination.
    $many=$items;for($id=10;$id<=24;$id++)$many[]=item($id);
    feed($many,5);sync();
    $cookies=$aCookies;
    $explore=payload(web('GET','/afiliados'));$second=payload(web('GET','/afiliados?offer_page=2'));
    check(count($explore['props']['offers']['data'])===12 && $second['props']['offers']['current_page']===2
        && !array_intersect(array_column($explore['props']['offers']['data'],'key'),array_column($second['props']['offers']['data'],'key')),'server pagination with disjoint pages');
    $httpBefore=Http::recorded()->count();$linksBefore=DB::table('affiliate_short_links')->count();
    web('GET','/afiliados');publicLink($old->code);
    check(Http::recorded()->count()===$httpBefore && DB::table('affiliate_short_links')->count()===$linksBefore,'panel and public clicks never fetch COM or generate links');
    $fixtures=['explore'=>$explore,'links'=>payload(web('GET','/afiliados?tab=links')),'history'=>$history];
    $fixtures['filter']=payload(web('GET','/afiliados?type=experience'));
    $fixtures['second']=$second;
    $admin=login(true);$fixtures['admin']=payload(web('GET','/admin/ofertas-comerciales'));
    file_put_contents('/evidence/catalog-browser-fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $result=['passed'=>count($passed),'postgresql'=>'schema isolation verified before writes','live_fixtures'=>0,'multipage'=>true,'atomic_failure'=>true,'permanent_dossis_link'=>true,'on_demand'=>true,'affiliate_privacy'=>true,'club_referral_verified'=>true];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
