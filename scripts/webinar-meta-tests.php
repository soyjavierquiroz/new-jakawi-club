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

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema, 'jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,
        'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'test@jakawi.club',
        'ads.privacy_ready'=>true,'ads.webinar_enabled'=>true,'ads.meta.enabled'=>false,'ads.tiktok.enabled'=>false,
        'ads.meta.pixel_id'=>'123456789','ads.meta.token'=>'fake-test-only','cache.default'=>'array']);
    DB::purge(); check(DB::selectOne('select current_schema() as name')->name===$schema,'isolated PostgreSQL schema');
    Artisan::call('migrate',['--force'=>true]); Notification::fake();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $calls=[];
    Illuminate\Support\Facades\Http::fake(function($r) use (&$calls) { $calls[]=$r->data(); return Illuminate\Support\Facades\Http::response(['events_received'=>1]); });
    $home=App\Models\MarketingLanding::where('slug','principal')->firstOrFail();
    $content=App\Support\LandingContent::webinarDefaults();
    $home->update(['configuration'=>$content,'published_configuration'=>$content,'status'=>'published','published_at'=>now()]);
    $first=User::create(['name'=>'Original','email'=>'original@example.test']);
    $second=User::create(['name'=>'Campaign','email'=>'campaign@example.test']);
    check(!app(App\Services\Advertising::class)->ready('meta') && app(App\Services\Advertising::class)->ready('meta',true),'webinar switch leaves membership disabled');
    $organic=payload(http('GET','/?utm_source=organic',[],true));
    check(!$organic['props']['ads']['allowed'] && App\Models\AdVisit::count()===0,'organic has no advertising runtime');
    http('GET','/r/'.$first->referral_code);
    $entry='/ads/meta/webinar?utm_source=facebook&utm_campaign=launch&fbclid=click123&ref='.$second->referral_code;
    $noConsent=payload(http('GET',$entry,[],true));
    check(!$noConsent['props']['ads']['allowed'] && App\Models\AdVisit::count()===0,'entry without advertising consent stores no click visit');
    check($noConsent['props']['landing']['slug']==='principal' && $noConsent['props']['ads']['funnel']==='webinar','Meta renders the actual published webinar');
    check(http('GET','/ads/meta/webinar/gracias')->isRedirect('https://jakawi.club/principal'),'no thanks or conversion without receipt');
    http('POST','/preferencias',['allow_ads'=>true]);
    $landing=payload(http('GET',$entry,[],true));
    check($landing['props']['ads']['allowed'] && $landing['props']['ads']['provider']==='meta','consent enables Meta webinar');
    $visit=App\Models\AdVisit::firstOrFail();
    check($visit->context['fbclid']==='click123' && $visit->context['utm_campaign']==='launch' && $visit->context['funnel']==='webinar','click and campaign survive in encrypted visit');
    check(!app('session')->has('ad_visit_id') && !app('session')->has('ads_provider'),'webinar does not contaminate membership session');
    http('POST','/medicion',['event'=>'landing_visit','placement'=>'landing','landing_id'=>$home->id,'channel'=>'meta']);
    http('POST','/medicion',['event'=>'landing_visit','placement'=>'landing','landing_id'=>$home->id,'channel'=>'meta']);
    check((int)DB::table('marketing_landing_event_totals')->where('event','webinar_visit')->where('placement','meta')->sum('total')===1,'reuses aggregate analytics and deduplicates visits');
    $data=registration('realregistration@example.test',['landing_id'=>$home->id,'referral_code'=>$second->referral_code]);
    $result=http('POST','/ads/meta/webinar/registro',$data);
    check($result->isRedirect('https://jakawi.club/ads/meta/webinar/gracias'),'saved registration stays in webinar Meta funnel');
    $user=User::where('email','realregistration@example.test')->firstOrFail();
    check($user->referred_by_id===$first->id && $user->registration_origin['channel']==='meta_webinar' && $user->registration_origin['utms']['utm_source']==='facebook','original referral protected and Meta origin frozen');
    $event=App\Models\AdEvent::firstOrFail();
    check(App\Models\AdEvent::count()===1 && $event->event_name==='CompleteRegistration' && $event->club_reference===$user->uuid,'only one principal conversion from persisted new account');
    check($event->payload['email']===hash('sha256',$user->email) && !str_contains(DB::table('ad_events')->value('payload'),$user->email),'hashed identifiers encrypted at rest');
    $thanks=payload(http('GET','/ads/meta/webinar/gracias',[],true));
    check($thanks['props']['ads']['events']===[['id'=>$event->id,'name'=>'CompleteRegistration']],'browser receives same persisted event_id');
    check(payload(http('GET','/ads/meta/webinar/gracias',[],true))['props']['ads']['events']===$thanks['props']['ads']['events'],'reload preserves conversion ID');
    (new App\Jobs\DeliverAdEvent($event->id))->handle();
    check(count($calls)===1 && $calls[0]['data'][0]['event_id']===$event->id && $calls[0]['data'][0]['event_name']==='CompleteRegistration' && $calls[0]['data'][0]['user_data']['fbc']===$visit->context['fbc'],'CAPI uses the same event_id and preserved Meta click');
    check($event->fresh()->status==='accepted','CAPI fake accepts matching browser event');
    (new App\Jobs\DeliverAdEvent($event->id))->handle();
    check(count($calls)===1,'no extra provider deliveries');
    check(App\Models\MembershipPurchase::count()===0,'no purchases created or queried');
    file_put_contents('/evidence/webinar-fixtures.json',json_encode(['landing'=>$landing,'thanks'=>$thanks,'organic'=>$organic,'no_consent'=>$noConsent]));
    app('cache')->flush();
    $again=http('POST','/ads/meta/webinar/registro',$data);
    check($again->isRedirect('https://jakawi.club/ads/meta/webinar/gracias') && App\Models\AdEvent::count()===1,'existing email or repeated submit creates no conversion');
    check(payload(http('GET','/ads/meta/webinar/gracias',[],true))['props']['ads']['events']===[],'existing-account receipt sends no false browser conversion');
    http('POST','/preferencias',['allow_ads'=>false]);
    check(!payload(http('GET','/ads/meta/webinar',[],true))['props']['ads']['allowed'] && !app('session')->has('ad_webinar_visit_id'),'revocation removes webinar permission and session');
    freshBrowser();app('cache')->flush();
    http('GET','/ads/meta/webinar?fbclid=never');
    http('POST','/ads/meta/webinar/registro',registration('noconsent@example.test',['landing_id'=>$home->id]));
    check(User::where('email','noconsent@example.test')->exists() && App\Models\AdEvent::count()===1,'real registration works without ad consent and sends nothing');
    freshBrowser();app('cache')->flush();
    http('GET','/principal?utm_source=referral');
    http('POST','/registro',registration('organicregistration@example.test',['landing_id'=>$home->id]));
    check(User::where('email','organicregistration@example.test')->first()->registration_origin['channel']==='organic' && App\Models\AdEvent::count()===1,'organic signup remains independent');
    $first->forceFill(['password'=>'FixturePassword123','activated_at'=>now(),'email_verified_at'=>now(),'is_marketing_admin'=>true])->save();
    http('POST','/login',['email'=>$first->email,'password'=>'FixturePassword123']);
    $panel=payload(http('GET','/admin/landings/'.$home->id.'/editar',[],true));
    check(count($panel['props']['webinar_metrics']['totals'])>0 && count($panel['props']['webinar_metrics']['origins'])>0 && $panel['props']['webinar_metrics']['meta_events'][0]['status']==='accepted','existing landing panel exposes source counts and CAPI receipts');
    freshBrowser();app('cache')->flush();
    http('GET','/ads/meta/webinar');http('POST','/preferencias',['allow_ads'=>true]);http('GET','/ads/meta/webinar');app('cache')->flush();
    http('POST','/ads/meta/webinar/registro',registration('revoked@example.test',['landing_id'=>$home->id]));
    $pending=App\Models\AdEvent::where('status','pending')->firstOrFail();
    http('POST','/preferencias',['allow_ads'=>false]);
    (new App\Jobs\DeliverAdEvent($pending->id))->handle();
    check($pending->fresh()->status==='rejected','revocation blocks pending CAPI');
    check(count($calls)===1,'no extra provider deliveries');
    $home->update(['status'=>'archived']);
    check(http('GET','/ads/meta/webinar',[],true)->getStatusCode()===404,'unpublished webinar is blocked');
    file_put_contents('/evidence/tests.json',json_encode(['passed'=>count($passed),'checks'=>$passed,'live_fixtures'=>0,'provider_requests'=>'fake only','schema_removed_after_run'=>true],JSON_PRETTY_PRINT));
} catch(Throwable $e) {
    fwrite(STDERR,($e instanceof RuntimeException && str_starts_with($e->getMessage(),'FAILED:')?$e->getMessage():'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);$failed=true;
} finally { DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); }
exit(isset($failed)?1:0);
