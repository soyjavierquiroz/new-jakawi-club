#!/usr/bin/env python3
"""Verify local preparations and unchanged LIVE state, without applying migrations."""
from datetime import datetime
from hashlib import sha256
import json
from pathlib import Path
import subprocess
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

ROOT = Path('/opt/sites/jakawi.club')
BASE = Path('/srv/sites/jakawi.club/rollback-global-identity/baseline.json')


def command(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)


baseline = json.loads(BASE.read_text())
head = command(['git', 'rev-parse', 'HEAD']).decode().strip()
assert not command(['git', 'status', '--porcelain'])
assert head == command(['git', 'rev-parse', 'origin/main']).decode().strip()
assert head == command(['git', 'ls-remote', 'origin', 'refs/heads/main']).decode().split()[0]
original_app = {path: digest for path, digest in baseline['files'].items() if path.startswith('app/')}
assert all(sha256((ROOT / path).read_bytes()).hexdigest() == digest for path, digest in original_app.items())
assert sha256(Path('/srv/sites/jakawi.club/secrets/app.env').read_bytes()).hexdigest() == baseline['env_sha256']
assert sha256((ROOT / 'app/public/build/manifest.json').read_bytes()).hexdigest() == baseline['manifest_sha256']

php = r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before = json_decode(file_get_contents(storage_path('app/global-identity-users-before.json')), true);
$rows = Illuminate\Support\Facades\DB::table('users')->select($before['columns'])->orderBy('id')->get()->map(fn ($u) => (array) $u)->all();
$pilot = App\Models\User::where('email', 'javierquiroztv@gmail.com')->firstOrFail();
echo json_encode([
    'accounts_original_columns_unchanged' => count($rows) === $before['count'] && hash('sha256', json_encode($rows)) === $before['digest'],
    'production_column_not_added' => ! Illuminate\Support\Facades\Schema::hasColumn('users', 'global_subject_id'),
    'migration_pending' => ! Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_10_29_000003_add_global_subject_id_to_users')->exists(),
    'pilot_activated_verified_admin' => $pilot->activated_at !== null && $pilot->hasVerifiedEmail() && $pilot->is_marketing_admin,
    'registration_open' => App\Support\LaunchSettings::registrationOpen(),
    'transactional_mail_ready' => App\Support\LaunchSettings::mailReady(),
    'migration_file_sha256' => hash_file('sha256', 'database/migrations/2026_10_29_000003_add_global_subject_id_to_users.php'),
]);
'''
state = json.loads(command(['docker', 'compose', 'exec', '-T', '--user', 'www-data', 'app', 'php'], input=php.encode()))
assert all(value is True for key, value in state.items() if key != 'migration_file_sha256'), state
assert state['migration_file_sha256'] == sha256((ROOT / 'app/database/migrations/2026_10_29_000003_add_global_subject_id_to_users.php').read_bytes()).hexdigest()
containers = json.loads(command(['docker', 'inspect', *baseline['containers']]))
unchanged_containers = all(
    c['Id'] == baseline['containers'][c['Name'].lstrip('/')]['id']
    and c['Image'] == baseline['containers'][c['Name'].lstrip('/')]['image']
    and c['State']['StartedAt'] == baseline['containers'][c['Name'].lstrip('/')]['started']
    and c['RestartCount'] == baseline['containers'][c['Name'].lstrip('/')]['restarts']
    for c in containers
)
assert unchanged_containers
health = {}
for url in ['https://jakawi.club/up', 'http://127.0.0.1:18082/up', 'https://jakawi.com/up']:
    with urlopen(url, timeout=20) as response:
        assert response.status == 200
        health[url] = response.status
try:
    with urlopen(Request('https://jakawi.com/internal/identity/handoffs', method='OPTIONS'), timeout=20) as response:
        endpoint_status = response.status
except HTTPError as error:
    endpoint_status = error.code
result = {
    'verified_at': datetime.now(ZoneInfo('America/La_Paz')).isoformat(),
    'preparation_sha': head,
    'previous_live_sha': baseline['sha'],
    'origin_main_synced': True,
    'tree_clean': True,
    'deployment_performed': False,
    'identity_flow_operational': False,
    'com_proposed_endpoint_options_status': endpoint_status,
    'all_original_app_files_unchanged': len(original_app),
    'assets_and_smtp_environment_unchanged': True,
    'all_existing_containers_not_restarted_or_recreated': unchanged_containers,
    'health': health,
    'production_state': state,
    'pilot_linked': False,
    'pilot_native_recovery': 'passwords.sent',
}
(ROOT / 'artifacts/global-identity/LIVE.json').write_text(json.dumps(result, indent=2)+'\n')
print(json.dumps(result, indent=2))
