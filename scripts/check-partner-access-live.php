<?php
// Backend-only contract probe. Never creates a user, Partner, link or economic fact.
chdir('/var/www/html');
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$subject=getenv('JAKAWI_VERIFIED_SUBJECT');
if(!is_string($subject)||!Illuminate\Support\Str::isUuid($subject))throw new RuntimeException('Verified subject required');
$client=app(App\Services\ClubPurchaseClient::class);
try {
 $response=$client->partnerAccess($subject);$data=$response->json();
 $ok=$response->status()===200 && ($data['global_subject_id']??null)===$subject;
 $owner=collect($data['partners']??[])->contains(fn($p)=>($p['id']??null)===1 && ($p['role']??null)==='owner' && ($p['status']??null)==='published' && ($p['economic_access']??null)===true);
 config(['purchases.secret'=>str_repeat('invalid-isolated-signature-',3)]);
 $rejected=false;try{$client->partnerAccess($subject);}catch(Throwable){$rejected=true;}
 $result=['real_backend_hmac_accepted'=>$ok,'existing_dossis_owner_authorized'=>$owner,'wrong_hmac_denied'=>$rejected,'no_live_fixtures'=>true];
 echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
 if(in_array(false,$result,true))exit(1);
} catch(Throwable) {fwrite(STDERR,"Partner contract validation failed; no provider body or credentials emitted.\n");exit(1);}
