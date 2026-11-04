<?php
// Separate PHP process: two real requests contend on the same isolated user row.
chdir('/var/www/html');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! preg_match('/^activation_test_[a-f0-9]{12}$/', $data['schema'])) exit(2);
config(['database.connections.pgsql.search_path'=>$data['schema'], 'identity.enabled'=>false]);
Illuminate\Support\Facades\DB::purge();
$request = Illuminate\Http\Request::create('https://jakawi.club/activar', 'POST', $data['credentials'], [], [], ['HTTP_ACCEPT'=>'application/json', 'REMOTE_ADDR'=>'192.0.2.12']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo $response->isRedirect('https://jakawi.club/login') ? 'consumed' : 'rejected';
