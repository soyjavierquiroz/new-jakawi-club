<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$result=['app_debug'=>config('app.debug'),'tables'=>[]];
foreach(['users','acquisition_people','acquisition_aliases','acquisition_activations','commission_ledger'] as $table){
 $rows=DB::table($table)->orderBy('id')->get();
 $result['tables'][$table]=['count'=>$rows->count(),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
}
$result['drex_local_accounts']=App\Models\User::whereRaw('lower(name) like ?', ['%drex%'])->count();
$result['dossis_partner_1_certified_people']=DB::table('acquisition_people')->where('beneficiary_type','PARTNER')->where('beneficiary_id','1')->count();
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
