<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$ledger=DB::table('commission_ledger')->orderBy('id')->get();
echo json_encode(['ledger_count'=>$ledger->count(),'ledger_sha256'=>hash('sha256',$ledger->toJson()),
 'automatic_approvals'=>false,'contract_ready'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
