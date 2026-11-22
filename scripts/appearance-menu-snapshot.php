<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$result=['flags'=>['registration_open'=>config('jakawi.registration_open'),'mail'=>config('mail.default'),'transactional_mail_ready'=>config('jakawi.transactional_mail_ready'),'debug'=>config('app.debug')],'tables'=>[]];
$result['mail_configuration_sha256']=hash('sha256',json_encode(config('mail'),JSON_THROW_ON_ERROR));
$result['launch_configuration_sha256']=hash('sha256',json_encode(config('jakawi'),JSON_THROW_ON_ERROR));
foreach(['users','marketing_landings','membership_purchases','acquisition_people','acquisition_aliases','acquisition_activations','commission_rules','commission_ledger','commission_audit','commercial_events','affiliate_partners','affiliate_partner_access'] as $table){
 $columns=Schema::getColumnListing($table);
 $rows=DB::table($table)->orderBy('id')->get($columns);
 $result['tables'][$table]=['count'=>$rows->count(),'sha256'=>hash('sha256',$rows->toJson())];
}
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
