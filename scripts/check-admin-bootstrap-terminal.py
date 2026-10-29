#!/usr/bin/env python3
"""Check real hidden console input in a bounded container and temporary DB schema."""
import os
import pty
import secrets
import select
import subprocess
import time

ROOT = '/opt/sites/jakawi.club'
name = 'jakawi-club-admin-bootstrap-tty'
command = [
    'docker', 'run', '--rm', '--interactive', '--tty', '--name', name,
    '--network', 'jakawi-club-private', '--memory', '256m', '--memory-swap', '320m',
    '--cpus', '0.65', '--pids-limit', '96',
    '--env-file', '/srv/sites/jakawi.club/secrets/app.env',
    '--env', 'APP_ENV=testing', '--env', 'APP_DEBUG=false', '--env', 'LOG_CHANNEL=single',
    '--env', 'APP_CONFIG_CACHE=/tmp/jakawi-config.php', '--env', 'APP_ROUTES_CACHE=/tmp/jakawi-routes.php',
    '--env', 'SESSION_DRIVER=database', '--env', 'CACHE_STORE=array', '--env', 'MAIL_MAILER=log',
    '--env', 'BCRYPT_ROUNDS=4',
    '--volume', ROOT+'/app:/var/www/html:ro', '--volume', ROOT+'/scripts:/tests:ro',
    '--tmpfs', '/var/www/html/storage:rw,mode=1777,size=32m',
    '--tmpfs', '/var/www/html/bootstrap/cache:rw,mode=1777,size=8m',
    'jakawi-club-php:8.4-base-v1', 'php', '/tests/admin-bootstrap-tests.php', '--tty-scenario',
]
master, slave = pty.openpty()
process = subprocess.Popen(command, cwd=ROOT, stdin=slave, stdout=slave, stderr=slave, close_fds=True)
os.close(slave)
transcript = bytearray()
password = secrets.token_hex(16)+'A9'

def read_more(timeout=0.2):
    if select.select([master], [], [], timeout)[0]:
        try:
            data = os.read(master, 65536)
        except OSError:
            return False
        transcript.extend(data)
        return bool(data)
    return process.poll() is None

def answer(prompt, value, offset):
    deadline = time.monotonic()+20
    while prompt.encode() not in transcript[offset:]:
        if time.monotonic() > deadline or not read_more():
            raise RuntimeError('Expected interactive prompt unavailable')
    # Let the console disable terminal echo before sending hidden answers.
    time.sleep(0.15)
    offset = len(transcript)
    os.write(master, value.encode()+b'\n')
    return offset

try:
    offset = 0
    for prompt, value in [
        ('Nombre real del administrador', 'Propietario aislado'),
        ('Correo electrónico real del administrador', 'terminal@example.test'),
        ('¿Confirmas que el correo', 'yes'),
        ('Contraseña', password),
        ('Repite la contraseña', password),
        ('¿Crear esta cuenta', 'yes'),
    ]:
        offset = answer(prompt, value, offset)
    deadline = time.monotonic()+20
    while process.poll() is None:
        if time.monotonic() > deadline:
            raise RuntimeError('Interactive test timed out')
        read_more()
    while read_more(0):
        pass
    assert process.returncode == 0 and b'TTY_BOOTSTRAP_OK' in transcript
    assert password.encode() not in transcript
    print('PASS terminal TTY real: contraseña oculta, confirmación y alta aislada; cero usuarios LIVE.')
except Exception:
    # Never print the terminal transcript or an exception containing input.
    print('FAIL terminal TTY: prueba no completada; transcripción privada descartada.')
    raise SystemExit(1)
finally:
    if process.poll() is None:
        process.terminate()
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        process.wait(timeout=10)
    os.close(master)
