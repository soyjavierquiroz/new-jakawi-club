<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

chdir('/var/www/html');
foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $dir) {
    @mkdir('storage/'.$dir, 0777, true);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'jakawi_club') {
    throw new RuntimeException('Test environment isolation required.');
}
$schema = 'global_identity_test_'.bin2hex(random_bytes(6));
$passed = [];

function check(bool $condition, string $name): void
{
    global $passed;
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$name);
    }
    $passed[] = $name;
    echo 'PASS '.$name.PHP_EOL;
}

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge();
    Notification::fake();
    Http::preventStrayRequests();
    $migration = 'database/migrations/2026_10_29_000003_add_global_subject_id_to_users.php';
    $previous = array_values(array_diff(glob('database/migrations/*.php'), [$migration, 'database/migrations/2026_10_29_000004_create_club_identity_operations.php']));
    Artisan::call('migrate', ['--path' => $previous, '--force' => true]);
    $admin = User::create(['name' => 'Existing admin', 'email' => 'admin@example.test', 'password' => 'ExistingPassword123']);
    $admin->forceFill(['activated_at' => now(), 'email_verified_at' => now(), 'is_marketing_admin' => true])->save();
    $lead = new User(['name' => 'Existing lead', 'email' => 'lead@example.test']);
    $lead->referred_by_id = $admin->id;
    $lead->registration_origin = ['landing_slug' => 'principal', 'campaign' => 'schema-test'];
    $lead->save();
    $before = DB::table('users')->orderBy('id')->get()->map(fn ($u) => (array) $u)->all();
    Artisan::call('migrate', ['--path' => [$migration], '--force' => true]);
    $columns = collect(DB::select('SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, 'users']))->keyBy('column_name');
    check($columns['global_subject_id']->data_type === 'uuid' && $columns['global_subject_id']->is_nullable === 'YES', 'global_subject_id UUID nullable');
    check($columns['id']->data_type === 'bigint' && $columns['uuid']->data_type === 'uuid', 'identificadores locales conservan sus tipos');
    $after = DB::table('users')->orderBy('id')->get()->map(function ($u) { $row = (array) $u; unset($row['global_subject_id']); return $row; })->all();
    check($before === $after, 'migración conserva todos los datos y credenciales existentes');
    check(User::whereNull('global_subject_id')->count() === 2, 'varias cuentas existentes permanecen sin vínculo');
    $subject = (string) Str::uuid();
    DB::table('users')->where('id', $admin->id)->update(['global_subject_id' => $subject]);
    check($admin->fresh()->global_subject_id === $subject, 'UUID emitido por COM se conserva al recargar');
    try {
        DB::table('users')->where('id', $lead->id)->update(['global_subject_id' => $subject]);
        throw new RuntimeException('Duplicate subject accepted');
    } catch (Illuminate\Database\UniqueConstraintViolationException) {
        check(true, 'índice único rechaza compartir sujeto entre cuentas');
    }
    check($lead->fresh()->global_subject_id === null, 'conflicto no modifica el vínculo de otra cuenta');
    $admin->refresh()->update(['name' => 'Updated admin']);
    check($admin->fresh()->global_subject_id === $subject, 'actualizar perfil conserva el sujeto global');
    check($admin->fresh()->is_marketing_admin && ! $lead->fresh()->is_marketing_admin, 'vínculo no cambia permisos administrativos');
    check($lead->fresh()->referred_by_id === $admin->id && $lead->fresh()->registration_origin === json_decode($before[1]['registration_origin'], true), 'referente y atribución preservados');
    check(! (new User)->isFillable('global_subject_id'), 'formularios no pueden asignar el sujeto global');
    check(Http::recorded()->count() === 0, 'migración y modelos no provisionan usuarios COM');
    $result = ['passed' => count($passed), 'isolated_postgresql' => true, 'live_users_created' => 0, 'identity_handoff_tested' => false];
    file_put_contents('/evidence/schema-tests.json', json_encode($result, JSON_PRETTY_PRINT).PHP_EOL);
    echo json_encode($result).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' '.$e->getMessage().' at '.$e->getLine().PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
