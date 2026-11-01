#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$@" <<'PYCODE'
import hashlib, json, subprocess, sys, tarfile
from pathlib import Path
source = Path.cwd().resolve()
assert source == Path('/srv/repos/jakawi.club') or source.is_relative_to('/srv/workspaces/jakawi/club')
assert len(sys.argv) == 2, 'Usage: bash scripts/package-source-release.sh /srv/workspaces/jakawi/club/<new-package>'
dest = Path(sys.argv[1]).resolve()
assert dest.is_relative_to('/srv/workspaces/jakawi/club') and dest != Path('/srv/workspaces/jakawi/club')
assert not dest.exists(), 'Destination must be new'
assert not subprocess.check_output(['git', 'status', '--porcelain']), 'Commit reviewed source first'
build = source / 'app/public/build-next'
manifest = build / 'manifest.json'
assert manifest.is_file(), 'Run the source build first'
# Include every generated asset, including dynamically imported chunks.
files = sorted(p for p in build.rglob('*') if p.is_file())
assert all(not p.is_symlink() for p in build.rglob('*'))
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
dest.mkdir(parents=True)
subprocess.run(['git', 'archive', '--format=tar', '-o', str(dest/'source.tar'), sha], check=True)
with tarfile.open(dest/'frontend.tar', 'w') as archive:
    for p in files:
        archive.add(p, arcname=str(Path('app/public/build-next')/p.relative_to(build)), recursive=False)
hashes = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in [dest/'source.tar', dest/'frontend.tar']}
(dest/'release.json').write_text(json.dumps({'sha': sha, 'archives_sha256': hashes,
    'manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest(),
    'deployed': False}, indent=2)+'\n')
print(f'Prepared {dest}; no LIVE files changed.')
PYCODE
