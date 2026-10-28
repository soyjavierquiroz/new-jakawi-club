#!/usr/bin/env python3
"""Prepare only jakawi.club data; create credentials once without displaying them."""
import base64
import os
from pathlib import Path
import secrets
import subprocess

root = Path('/srv/sites/jakawi.club')
root.mkdir(mode=0o700, parents=True, exist_ok=True)
os.chmod(root, 0o700)
secret_dir = root / 'secrets'
secret_dir.mkdir(mode=0o700, exist_ok=True)
os.chmod(secret_dir, 0o700)

for relative in ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
                 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap-cache']:
    target = root / relative
    target.mkdir(parents=True, exist_ok=True)
    for parent in [target, *target.parents]:
        if parent == root:
            break
        os.chown(parent, 82, 82)
        os.chmod(parent, 0o770)

# Nginx traverses only this separately mounted, intentionally public directory.
os.chmod(root / 'storage/app/public', 0o755)
Path('/opt/sites/jakawi.club/app/public/storage').mkdir(parents=True, exist_ok=True)

(root / 'nginx-logs').mkdir(mode=0o755, exist_ok=True)
(root / 'postgres').mkdir(mode=0o700, exist_ok=True)
os.chown(root / 'postgres', 70, 70)

files = [secret_dir / name for name in ['db-admin-password', 'db-app-password', 'app.env']]
if any(p.exists() for p in files) and not all(p.exists() for p in files):
    raise SystemExit('Incomplete existing credentials: inspect manually; refusing to regenerate.')
if not any(p.exists() for p in files):
    if any((root / 'postgres').iterdir()):
        raise SystemExit('Existing database without credentials; refusing to regenerate.')
    admin_password = secrets.token_hex(32)
    app_password = secrets.token_hex(32)
    app_key = base64.b64encode(secrets.token_bytes(32)).decode()
    env = f'''APP_NAME="JAKAWI Launch Engine"
APP_ENV=production
APP_KEY=base64:{app_key}
APP_DEBUG=false
APP_URL=https://jakawi.club
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_ES
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=jakawi_club
DB_USERNAME=jakawi_club
DB_PASSWORD={app_password}
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=jakawi_club_session
QUEUE_CONNECTION=database
CACHE_STORE=database
CACHE_PREFIX=jakawi_club_
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@jakawi.club
MAIL_FROM_NAME="JAKAWI Launch Engine"
VITE_APP_NAME="JAKAWI Launch Engine"
'''
    old_umask = os.umask(0o077)
    try:
        files[0].write_text(admin_password + '\n')
        files[1].write_text(app_password + '\n')
        files[2].write_text(env)
    finally:
        os.umask(old_umask)
    for path in files[:2]:
        os.chown(path, 70, 70)
        os.chmod(path, 0o400)
    os.chmod(files[2], 0o600)

exists = subprocess.run(['docker', 'network', 'inspect', 'jakawi-club-egress'],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
if not exists:
    subprocess.run(['docker', 'network', 'create', '--driver', 'bridge',
                    '--label', 'com.jakawi.project=jakawi-club', 'jakawi-club-egress'], check=True)
print('jakawi.club persistence and independent credentials prepared; secrets not displayed.')
