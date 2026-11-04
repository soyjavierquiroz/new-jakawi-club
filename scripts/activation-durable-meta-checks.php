<?php
use App\Notifications\ActivateAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
// Same Meta registration, receipt and mocked CAPI delivery created by webinar-meta-tests.php.
$activationOrigin = $user->registration_origin;
$activationConversions = DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total');
$oldActivation = Notification::sent($user, ActivateAccount::class)->last()->token;
check(http('POST', '/recuperar-password', ['email'=>$user->email])->isRedirect(), 'Meta account resends activation without registration');
$newActivation = Notification::sent($user, ActivateAccount::class)->last()->token;
check($oldActivation !== $newActivation && ! app(App\Services\ActivationTokens::class)->valid($user, $oldActivation), 'Meta replacement invalidates previous activation');
check(http('POST', '/activar', ['email'=>$user->email, 'token'=>$newActivation, 'password'=>'MetaActivation1234', 'password_confirmation'=>'MetaActivation1234'])->isRedirect('https://jakawi.club/login'), 'Meta account activates and offers normal login');
$activationLogin = payload(http('GET', '/login', [], true));
check(empty($activationLogin['props']['ads']['events']) && App\Models\AdEvent::count() === 1 && $event->fresh()->id === $thanks['props']['ads']['events'][0]['id'] && count($calls) === 1, 'activation and resend emit no browser conversion, CAPI or new event_id');
check($user->fresh()->registration_origin === $activationOrigin && $user->fresh()->referred_by_id === $first->id && DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $activationConversions, 'Meta activation preserves source, attribution and unique registration');
