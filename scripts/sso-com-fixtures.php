<?php
chdir('/var/www/html');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(! $app->environment('identity_contract_testing') || config('database.connections.pgsql.database')!=='jakawi_club_identity_contract_test')throw new RuntimeException('Disposable COM DB required');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Artisan::call('oidc:register-club');
foreach(['existing-com@example.test'=>true,'other-com@example.test'=>false] as $email=>$admin){$u=App\Models\User::create(['name'=>'COM fixture','email'=>$email,'password'=>'FixtureComPassword123']);$u->forceFill(['is_admin'=>$admin,'email_verified_at'=>now()])->save();}
$rows=Illuminate\Support\Facades\DB::table('users')->orderBy('id')->get()->all();file_put_contents(storage_path('com-fixture-before.json'),json_encode(['digest'=>hash('sha256',json_encode($rows))]));echo 'Disposable COM fixtures ready'.PHP_EOL;
