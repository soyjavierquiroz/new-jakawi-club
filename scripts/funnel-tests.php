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
$schema = 'funnel_test_'.bin2hex(random_bytes(6));
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
    config(['database.connections.pgsql.search_path'=>$schema, 'jakawi.video_url'=>null, 'jakawi.confirmation_video_url'=>null,
        'jakawi.whatsapp_group_url'=>'https://chat.whatsapp.com/DefaultGroupFixture1234', 'jakawi.registration_open'=>true,
        'jakawi.transactional_mail_ready'=>true, 'mail.default'=>'smtp', 'mail.mailers.smtp.host'=>'smtp.example.test', 'mail.from.address'=>'equipo@jakawi.club']);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'PostgreSQL temporal exclusivo CLUB');
    Artisan::call('migrate', ['--force'=>true]); Notification::fake();
    $home=App\Models\MarketingLanding::where('slug','principal')->firstOrFail();
    $content=App\Support\LandingContent::defaults();
    $old='https://www.youtube.com/watch?v=aqz-KE-bpKQ'; $new='https://www.youtube.com/watch?v=M7lc1UVf-VE';
    $content['video']['youtube_url']=$old; $content['confirmation_video']['youtube_url']=$new;
    $content['whatsapp_group']=['mode'=>'custom','url'=>'https://chat.whatsapp.com/LegacyCampaignFixture12'];
    $content['confirmation_whatsapp_group']=['mode'=>'inherit','url'=>null];
    $home->update(['configuration'=>$content,'published_configuration'=>$content,'campaign'=>'funnel_test','published_campaign'=>'funnel_test']);
    $admin=new User(['name'=>'Editor aislado','email'=>'editor@example.test','password'=>'PasswordEditor123']);
    $admin->email_verified_at=now();$admin->activated_at=now();$admin->is_marketing_admin=true;$admin->save();
    http('POST','/login',['email'=>$admin->email,'password'=>'PasswordEditor123']);
    $base='/admin/landings/'.$home->id;
    $fixtures=['editor'=>payload(http('GET',$base.'/editar',[],true)), 'preview'=>payload(http('GET',$base.'/preview',[],true)),
        'confirmation_preview'=>payload(http('GET',$base.'/preview/confirmado',[],true)), 'landings'=>[]];
    $content['video']['youtube_url']=$new;
    $save=['title'=>$home->title,'slug'=>'principal','campaign'=>'funnel_test','configuration'=>$content,'revision'=>$home->revision];
    check(http('PUT',$base,$save)->isRedirect(), 'guardar borrador aceptado');$home->refresh();
    check($home->configuration['video']['youtube_url']===$new && $home->published_configuration['video']['youtube_url']===$old,'guardar no publica el video');
    check(payload(http('GET',$base.'/preview',[],true))['props']['launch']['video_url']===$new,'preview usa nuevo video guardado');
    check(payload(http('GET','/principal',[],true))['props']['launch']['video_url']===$old,'público conserva snapshot antes de publicar');
    $fixtures['saved_editor']=payload(http('GET',$base.'/editar',[],true));
    check(http('POST',$base.'/publicar',['revision'=>$home->revision])->isRedirect(),'publicación explícita aceptada');$home->refresh();
    check($home->configuration===$home->published_configuration,'publicar copia snapshot validado');
    check(payload(http('GET','/principal',[],true))['props']['launch']['video_url']===$new,'video efectivo /principal coincide tras publicar');
    check(payload(http('GET','/',[],true))['props']['launch']['video_url']===$new,'portada comparte snapshot público');
    $fixtures['published_editor']=payload(http('GET',$base.'/editar',[],true));
    foreach (['LEAD_VSL','WEBINAR','WAITLIST'] as $template) {
        $c=$content;$c['presentation']['template']=$template;
        $l=App\Models\MarketingLanding::create(['title'=>'Fixture '.$template,'slug'=>str_replace('_','-',strtolower($template)),'template'=>$template,
            'configuration'=>$c,'published_configuration'=>$c,'campaign'=>'funnel_test','published_campaign'=>'funnel_test','status'=>'published','published_at'=>now()]);
        $p=payload(http('GET','/'.$l->slug,[],true));
        check($p['props']['launch']['whatsapp_url']===null && !isset($p['props']['landing']['content']['whatsapp_group'],$p['props']['landing']['content']['confirmation_whatsapp_group']),'sin destinos WhatsApp en captación '.$template);
        $fixtures['landings'][$template]=$p;
    }
    foreach (['default','custom','none'] as $mode) {
        $c=$content;$c['confirmation_whatsapp_group']=['mode'=>$mode,'url'=>$mode==='custom'?'https://chat.whatsapp.com/ConfirmGroupFixture1234':null];
        $expected=match($mode){'default'=>config('jakawi.whatsapp_group_url'),'custom'=>$c['confirmation_whatsapp_group']['url'],default=>null};
        $validated=App\Support\LandingContent::validate($c);
        check(App\Support\RegistrationNextStep::confirmationGroup($validated)===$expected,'grupo confirmación '.$mode);
    }
    check(App\Support\RegistrationNextStep::confirmationGroup($content)===$content['whatsapp_group']['url'],'herencia previa conserva grupo específico de campaña');
    // Saved state, publication revision and media remain independent in browser fixtures.
    freshBrowser();
    foreach (['gracias','confirmado'] as $suffix) check(http('GET','/principal/'.$suffix)->isRedirect('https://jakawi.club/principal'),'sin recibo no hay éxito ficticio '.$suffix);
    check(http('GET','/no-existe/gracias')->getStatusCode()===404,'confirmación inexistente 404');
    $first=User::create(['name'=>'Referente original','email'=>'first@example.test']);
    $second=User::create(['name'=>'Segundo referente','email'=>'second@example.test']);
    http('GET','/r/'.$first->referral_code);http('GET','/r/'.$second->referral_code);
    $request=registration('funnel@example.test',['landing_id'=>$home->id,'referral_code'=>$second->referral_code]);
    check(http('POST','/registro',$request)->isRedirect('https://jakawi.club/principal/gracias'),'POST conserva registro y usa URL propia gracias');
    $u=User::where('email','funnel@example.test')->firstOrFail();
    check($u->name==='Persona Prueba' && $u->email==='funnel@example.test' && $u->phone==='+59170000000' && (bool)$u->uuid,'lead nombre email teléfono UUID en users');
    check($u->referred_by_id===$first->id && !$u->activated_at && !$u->email_verified_at,'primer referente y activación pendiente preservados');
    $receipt=app('session')->get('registration_receipt');
    check($receipt['slug']==='principal' && $receipt['campaign']==='funnel_test','landing y campaña fijadas en recibo');
    $current=payload(http('GET','/principal/gracias',[],true));$legacy=payload(http('GET','/principal/confirmado',[],true));
    check($current['props']['confirmation']===$legacy['props']['confirmation'],'recibos anteriores funcionan en ambas rutas');
    check($current['props']['confirmation']['whatsapp_group_url']===$content['whatsapp_group']['url'],'grupo efectivo de recibo conserva campaña original');
    $fixtures['receipt']=$current;
    $other=App\Models\MarketingLanding::where('slug','webinar')->first();
    // WEBINAR fixture uses the published slug webinar; a different landing cannot consume receipt.
    if ($other) check(http('GET','/'.$other->slug.'/gracias')->isRedirect('https://jakawi.club/'.$other->slug),'recibo ligado a landing original');
    $before=(int)DB::table('marketing_event_totals')->where('event','registration_completed')->sum('total');
    http('GET','/principal/gracias');http('GET','/principal/confirmado');
    check((int)DB::table('marketing_event_totals')->where('event','registration_completed')->sum('total')===$before && $before===1,'visitas no suman conversiones');
    $changed=$content;$changed['confirmation_whatsapp_group']=['mode'=>'none','url'=>null];$home->update(['published_configuration'=>$changed]);
    check(payload(http('GET','/principal/gracias',[],true))['props']['confirmation']['whatsapp_group_url']===$receipt['whatsapp_group_url'],'republicar no altera grupo del recibo');
    $activation=Notification::sent($u,ActivateAccount::class)->first();
    $credentials=['email'=>$u->email,'token'=>$activation->token,'password'=>'ActivatedPassword123','password_confirmation'=>'ActivatedPassword123'];
    check(http('POST','/activar',$credentials)->isRedirect('https://jakawi.club/bienvenida'),'activación existente funciona');
    check($u->fresh()->activated_at && $u->fresh()->hasVerifiedEmail(),'activación guarda activated_at y email_verified_at');
    check(http('GET','/bienvenida')->isRedirect('https://jakawi.club/principal/gracias'),'activación vuelve a confirmación canónica');
    freshBrowser();app('cache')->flush();
    $users=User::count();
    check(http('POST','/registro',$request)->isRedirect('https://jakawi.club/principal/gracias') && User::count()===$users,'email repetido no duplica lead');
    check((int)DB::table('marketing_event_totals')->where('event','registration_completed')->sum('total')===$before,'email repetido no duplica conversión');
    Carbon::setTestNow(now()->addMinutes(16));
    check(http('GET','/principal/gracias')->isRedirect('https://jakawi.club/principal'),'recibo vencido no muestra éxito');Carbon::setTestNow();
    file_put_contents('/evidence/funnel-fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['passed'=>count($passed),'database'=>'isolated PostgreSQL schema','notifications'=>'fake','live_test_users'=>0],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,($e instanceof RuntimeException && str_starts_with($e->getMessage(),'FAILED:')?$e->getMessage():'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);$failed=true;
} finally { DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); }
exit(isset($failed)?1:0);
