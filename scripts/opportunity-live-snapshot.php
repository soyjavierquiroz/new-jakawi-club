<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows=Illuminate\Support\Facades\DB::table('marketing_landings')->orderBy('id')->get(['id','configuration','published_configuration','revision','status','campaign','published_campaign','published_at']);
echo json_encode(['landings'=>count($rows),'configuration_sha256'=>hash('sha256',$rows->toJson())],JSON_PRETTY_PRINT).PHP_EOL;
