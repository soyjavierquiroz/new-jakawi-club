<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = ['app_debug' => config('app.debug'), 'tables' => []];
foreach (['users', 'membership_purchases', 'acquisition_people', 'acquisition_aliases', 'acquisition_activations', 'commission_ledger', 'commercial_events'] as $table) {
    $rows = Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get();
    $result['tables'][$table] = ['count' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
