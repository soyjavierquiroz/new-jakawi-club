<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$hashes = [];
foreach (['marketing_landings', 'marketing_landing_snapshots', 'affiliate_short_links', 'affiliate_link_daily_clicks', 'commercial_events', 'users', 'club_identity_links', 'commission_ledger', 'commission_rules'] as $table) {
    $rows = Illuminate\Support\Facades\DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
    sort($rows);
    $hashes[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}
echo json_encode($hashes, JSON_PRETTY_PRINT).PHP_EOL;
