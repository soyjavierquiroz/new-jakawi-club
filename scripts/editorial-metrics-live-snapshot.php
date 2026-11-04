<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows=Illuminate\Support\Facades\DB::table('marketing_landings')->orderBy('id')->get(['id','configuration','published_configuration','revision','status','campaign','published_campaign','published_at']);
$result=['landings'=>count($rows),'configuration_sha256'=>hash('sha256',$rows->toJson()),'economics'=>[]];
foreach (['commission_rules','commission_ledger','commission_audit','migrations'] as $table) {
    $digest=Illuminate\Support\Facades\DB::selectOne('select count(*) as count, md5(coalesce(jsonb_agg(to_jsonb(t) order by to_jsonb(t)::text)::text,\'[]\')) as digest from public."'.$table.'" t');
    $result['economics'][$table]=(array)$digest;
}
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
