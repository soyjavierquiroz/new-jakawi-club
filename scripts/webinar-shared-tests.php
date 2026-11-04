<?php

// Focused integration checks using the real HTTP kernel and PostgreSQL, without PHPUnit/dev dependencies.
use App\Models\User;
use App\Notifications\ActivateAccount;
use App\Support\LaunchSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

chdir('/var/www/html');
foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $dir) {
    @mkdir('storage/'.$dir, 0777, true);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'jakawi_club') {
    throw new RuntimeException('Test environment isolation required.');
}
$schema = 'webinar_meta_test_'.bin2hex(random_bytes(6));
$passed = [];
$browserCookies = [];

function check(bool $condition, string $name): void
{
    global $passed;
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$name);
    }
    $passed[] = $name;
    echo 'PASS '.$name.PHP_EOL;
}

function http(string $method, string $path, array $data = [], bool $inertia = false): \Symfony\Component\HttpFoundation\Response
{
    global $app, $browserCookies;
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.10'];
    if ($inertia) {
        $server['HTTP_X_INERTIA'] = 'true';
        $server['HTTP_X_INERTIA_VERSION'] = (new App\Http\Middleware\HandleInertiaRequests)->version(Request::create('/'));
    }
    $request = Request::create('https://jakawi.club'.$path, $method, $data, $browserCookies, [], $server);
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
            unset($browserCookies[$cookie->getName()]);
        } else {
            $browserCookies[$cookie->getName()] = $cookie->getValue();
        }
    }
    foreach (app('cookie')->getQueuedCookies() as $cookie) {
        app('cookie')->unqueue($cookie->getName(), $cookie->getPath());
    }
    return $response;
}

function freshBrowser(): void
{
    global $browserCookies;
    $browserCookies = [];
    Auth::forgetGuards();
    app('session')->flush();
    app('session')->regenerate();
}

function payload(\Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function registration(string $email, array $extra = []): array
{
    return array_merge(['name' => '  Persona   Prueba  ', 'email' => $email, 'phone' => '+591 (700) 000-00', 'privacy' => true], $extra);
}

function editorPublish(array $content): void {
    global $home;
    $home->refresh();$publishedBefore=$home->published_configuration;
    $r=http('PUT','/admin/landings/'.$home->id,['title'=>$home->title,'slug'=>$home->slug,'campaign'=>'shared_webinar','revision'=>$home->revision,'configuration'=>$content]);
    if(!$r->isRedirect()) fwrite(STDERR,'editor status '.$r->getStatusCode().' errors '.json_encode(json_decode($r->getContent(),true)['errors']??[]).PHP_EOL);
    check($r->isRedirect(),'shared editor accepts current webinar configuration');
    $home->refresh();
    check($home->published_configuration===$publishedBefore,'draft save preserves public source until publication');
    check(http('POST','/admin/landings/'.$home->id.'/publicar',['revision'=>$home->revision])->isRedirect(),'shared editor publishes once for both routes');
    $home->refresh();
}
try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,
        'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'test@jakawi.club',
        'ads.privacy_ready'=>true,'ads.webinar_enabled'=>true,'ads.meta.enabled'=>false,'ads.tiktok.enabled'=>false,
        'ads.meta.pixel_id'=>'1429220108808185','ads.meta.token'=>'fake-test-only','cache.default'=>'array']);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name===$schema && User::getConnectionResolver()->connection()->selectOne('select current_schema() as name')->name===$schema,'Query Builder and Eloquent isolated before fixtures');
    Artisan::call('migrate',['--force'=>true]);Notification::fake();
    Illuminate\Support\Facades\Http::preventStrayRequests();$calls=[];
    Illuminate\Support\Facades\Http::fake(function($r)use(&$calls){$calls[]=$r->data();return Illuminate\Support\Facades\Http::response(['events_received'=>1]);});
    $home=App\Models\MarketingLanding::where('slug',config('jakawi.home_landing_slug'))->firstOrFail();
    $sourceFile='/evidence/published-source.json';
    if (is_file($sourceFile)) {
        // Read-only snapshot of the current public source; all registration remains isolated.
        $content=json_decode(file_get_contents($sourceFile),true,512,JSON_THROW_ON_ERROR);
    } else {
        $content=App\Support\LandingContent::webinarDefaults();
        $content['presentation']['show_confirmation_video']=true;
        $content['confirmation_video']['youtube_url']='https://www.youtube.com/watch?v=P42gDOTL6Ys';
        $content['confirmation_whatsapp_group']=['mode'=>'custom','url'=>'https://chat.whatsapp.com/IsolatedWebinarGroup123'];
        $content['presentation']['webinar']['starts_at']='2026-11-12T08:30:00-04:00';
    }
    $home->update(['configuration'=>$content,'published_configuration'=>$content,'status'=>'published','published_at'=>now()]);
    $admin=User::create(['name'=>'Editor fixture','email'=>'editor@example.test','password'=>'FixturePassword123']);
    $admin->forceFill(['is_marketing_admin'=>true,'activated_at'=>now(),'email_verified_at'=>now()])->save();
    check(http('GET','/principal/gracias')->isRedirect('https://jakawi.club/principal') && http('GET','/ads/meta/webinar/gracias')->isRedirect('https://jakawi.club/principal'),'both confirmations require valid receipt');
    $organicLanding=payload(http('GET','/principal',[],true));
    $metaLanding=payload(http('GET','/ads/meta/webinar?utm_source=facebook&utm_campaign=shared',[],true));
    check($organicLanding['component']===$metaLanding['component'] && $organicLanding['props']['landing']['content']===$metaLanding['props']['landing']['content'] && $organicLanding['props']['launch']===$metaLanding['props']['launch'],'both entries share component content date media and registration rules');
    check(!$organicLanding['props']['ads']['allowed'] && $metaLanding['props']['ads']['allowed'],'only Meta entry enables Pixel');
    $r=http('POST','/ads/meta/webinar/registro',registration('meta@example.test',['landing_id'=>$home->id]));
    check($r->isRedirect('https://jakawi.club/ads/meta/webinar/gracias'),'Meta registration keeps public URL');
    $metaThanks=payload(http('GET','/ads/meta/webinar/gracias',[],true));$metaCookies=$browserCookies;
    $origin=User::where('email','meta@example.test')->firstOrFail()->registration_origin;
    $receipt=app('session')->get('registration_receipt');$expiry=app('session')->get('welcome_expires_at');
    check(array_keys($receipt)===['id','slug','campaign'],'new webinar receipt stores only access evidence without copied configuration');
    // Simulate an already issued pre-fix receipt with stale presentation fields.
    $receipt=[...$receipt,'content'=>['title'=>'Old frozen title'],'confirmation_video'=>['youtube_url'=>null],'whatsapp_group_url'=>null];
    app('session')->put('registration_receipt',$receipt);app('session')->save();
    $event=App\Models\AdEvent::sole();
    check($event->event_name==='CompleteRegistration' && $metaThanks['props']['ads']['events']===[['id'=>$event->id,'name'=>'CompleteRegistration']],'new-account conversion shares persisted browser event ID');
    freshBrowser();app('cache')->flush();
    http('GET','/principal');
    check(http('POST','/registro',registration('organic@example.test',['landing_id'=>$home->id]))->isRedirect('https://jakawi.club/principal/gracias'),'organic registration keeps public URL');
    $organicThanks=payload(http('GET','/principal/gracias',[],true));$organicCookies=$browserCookies;
    check($organicThanks['component']===$metaThanks['component'] && $organicThanks['props']['confirmation']===$metaThanks['props']['confirmation'],'both confirmations have identical content video group instructions activation and CTAs');
    check($organicThanks['props']['confirmation']['video']['youtube_url']!==null && $organicThanks['props']['confirmation']['whatsapp_group_url']!==null,'enabled video and group available through shared rules');
    check(!$organicThanks['props']['ads']['allowed'] && App\Models\AdEvent::count()===1,'organic confirmation has no Pixel or extra conversion');
    $fixtures=['organic'=>$organicLanding,'landing'=>$metaLanding,'thanks'=>$metaThanks,'organic_thanks'=>$organicThanks];
    freshBrowser();http('POST','/login',['email'=>$admin->email,'password'=>'FixturePassword123']);$adminCookies=$browserCookies;
    $updated=$content;
    $updated['hero']['title']='Un webinar compartido actualizado';
    $updated['registration']['form_title']='Registro compartido actualizado';
    $updated['presentation']['webinar']['starts_at']='2026-11-13T09:30:00-04:00';
    $updated['video']['youtube_url']='https://www.youtube.com/watch?v=aqz-KE-bpKQ';
    $updated['video']['poster']='/marketing/club-card.png';
    $updated['after_registration']['title']='Confirmación compartida actualizada';
    $updated['after_registration']['instructions']='Conserva estas instrucciones del webinar actualizado.';
    $updated['confirmation_video']['youtube_url']='https://www.youtube.com/watch?v=aqz-KE-bpKQ';
    $updated['confirmation_whatsapp_group']=['mode'=>'custom','url'=>'https://chat.whatsapp.com/UpdatedSharedWebinar123'];
    editorPublish($updated);$adminCookies=$browserCookies;
    $browserCookies=$metaCookies;
    $newMeta=payload(http('GET','/ads/meta/webinar/gracias',[],true));
    check(app('session')->get('registration_receipt')===$receipt && app('session')->get('welcome_expires_at')===$expiry,'editor updates never change receipt identity campaign or extend access');
    check($newMeta['props']['ads']['events']===$metaThanks['props']['ads']['events'],'editor update preserves conversion event ID');
    $newMetaLanding=payload(http('GET','/ads/meta/webinar',[],true));$metaCookies=$browserCookies;
    $browserCookies=$organicCookies;
    $newOrganic=payload(http('GET','/principal/gracias',[],true));
    $newOrganicLanding=payload(http('GET','/principal',[],true));$organicCookies=$browserCookies;
    check($newMeta['props']['confirmation']===$newOrganic['props']['confirmation'] && $newMeta['props']['confirmation']['content']['title']===$updated['after_registration']['title'],'already issued receipts in both routes immediately reflect shared publication');
    check($newMetaLanding['props']['landing']['content']===$newOrganicLanding['props']['landing']['content'] && $newMetaLanding['props']['landing']['content']['hero']['title']===$updated['hero']['title'],'editor changes landing video image text registration button and date once for both entries');
    check($newMeta['props']['confirmation']['video']['youtube_url']===$updated['confirmation_video']['youtube_url'] && $newMeta['props']['confirmation']['whatsapp_group_url']===$updated['confirmation_whatsapp_group']['url'],'editor changes confirmation video group and access instructions once for both receipts');
    check(User::where('email','meta@example.test')->firstOrFail()->registration_origin===$origin && App\Models\AdEvent::count()===1,'content reads do not change account origin or conversions');
    $fixtures['updated_organic']=$newOrganicLanding;$fixtures['updated_landing']=$newMetaLanding;$fixtures['updated_thanks']=$newMeta;$fixtures['updated_organic_thanks']=$newOrganic;
    $browserCookies=$adminCookies;$hidden=$updated;$hidden['presentation']['show_confirmation_video']=false;$hidden['confirmation_whatsapp_group']=['mode'=>'none','url'=>null];editorPublish($hidden);$adminCookies=$browserCookies;
    $browserCookies=$metaCookies;$hiddenMeta=payload(http('GET','/ads/meta/webinar/gracias',[],true));
    $browserCookies=$organicCookies;$hiddenOrganic=payload(http('GET','/principal/gracias',[],true));
    check($hiddenMeta['props']['confirmation']===$hiddenOrganic['props']['confirmation'] && $hiddenMeta['props']['confirmation']['video']['youtube_url']===null && $hiddenMeta['props']['confirmation']['whatsapp_group_url']===null,'disabling media or group removes access from both existing confirmations');
    $fixtures['hidden_thanks']=$hiddenMeta;$fixtures['hidden_organic_thanks']=$hiddenOrganic;
    $browserCookies=$metaCookies;
    (new App\Jobs\DeliverAdEvent($event->id))->handle();(new App\Jobs\DeliverAdEvent($event->id))->handle();
    check(count($calls)===1 && $calls[0]['data'][0]['event_id']===$event->id && $calls[0]['data'][0]['event_name']==='CompleteRegistration','existing Laravel CAPI delivers persisted ID once with simulated HTTP');
    app('cache')->flush();$repeat=http('POST','/ads/meta/webinar/registro',registration('meta@example.test',['landing_id'=>$home->id]));
    check($repeat->isRedirect('https://jakawi.club/ads/meta/webinar/gracias') && !Auth::check(),'restored Meta browser remains unauthenticated and reuses registration endpoint');
    check(App\Models\AdEvent::count()===1 && payload(http('GET','/ads/meta/webinar/gracias',[],true))['props']['ads']['events']===[],'existing account or repeated registration cannot produce another conversion');
    $registered=User::where('email','meta@example.test')->firstOrFail();
    $registered->forceFill(['password'=>'FixturePassword123','activated_at'=>now(),'email_verified_at'=>now()])->save();
    check(http('POST','/login',['email'=>$registered->email,'password'=>'FixturePassword123'])->isRedirect(),'real isolated account login preserves receipt rules');
    $activeMeta=payload(http('GET','/ads/meta/webinar/gracias',[],true));
    $activeOrganic=payload(http('GET','/principal/gracias',[],true));
    check($activeMeta['props']['confirmation']===$activeOrganic['props']['confirmation'] && $activeMeta['props']['confirmation']['activation']==='confirmed' && $activeMeta['props']['referral_url']===$registered->referralUrl(),'verified matching account has identical activation state and referral access');
    $other=$home->replicate();$other->slug='other-webinar';$other->save();
    check(http('GET','/other-webinar/gracias')->isRedirect('https://jakawi.club/other-webinar'),'receipt cannot authorize another landing');
    app('session')->put('welcome_expires_at',now()->subSecond()->timestamp);
    app('session')->save();
    check(http('GET','/principal/gracias')->isRedirect('https://jakawi.club/principal') && http('GET','/ads/meta/webinar/gracias')->isRedirect('https://jakawi.club/principal'),'expired receipt denied identically');
    $home->update(['status'=>'archived']);
    check(http('GET','/principal',[],true)->getStatusCode()===404 && http('GET','/ads/meta/webinar',[],true)->getStatusCode()===404 && http('GET','/principal/gracias',[],true)->getStatusCode()===404 && http('GET','/ads/meta/webinar/gracias',[],true)->getStatusCode()===404,'unpublished source blocks both entries and confirmations');
    file_put_contents('/evidence/webinar-fixtures.json',json_encode($fixtures));
    file_put_contents('/evidence/tests.json',json_encode(['passed'=>count($passed),'checks'=>$passed,'live_accounts_created'=>0,'provider_requests'=>'simulated only','schema_removed_after_run'=>true],JSON_PRETTY_PRINT));
} catch(Throwable $e) {
    fwrite(STDERR,($e instanceof RuntimeException && str_starts_with($e->getMessage(),'FAILED:')?$e->getMessage():'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);$failed=true;
} finally { DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); }
exit(isset($failed)?1:0);
