#!/usr/bin/env bash
# One-time Neptune server prerequisites. Run as root on Ubuntu/Debian.
set -euo pipefail
[[ "$EUID" -eq 0 ]] || { echo 'Run this one-time server setup as root.' >&2; exit 1; }
source_dir="$(cd -- "$(dirname -- "$0")" && pwd)"
app_root="$(cd -- "$source_dir/../.." && pwd)"
private_root="$app_root/maintenance-uploads"
venv="$private_root/community-learning-venv"
tools="$private_root/community-learning-tools/bin"
case "$(uname -m)" in x86_64) platform=linux-x86_64;; aarch64|arm64) platform=linux-aarch64;; *) echo 'Unsupported server architecture.' >&2; exit 1;; esac
apt-get update
apt-get install -y --no-install-recommends python3 python3-venv ffmpeg ca-certificates curl libgomp1
python3 -c 'import sys; assert sys.version_info >= (3,11), "Python 3.11+ is required"'
if [[ ! -d "$private_root" ]]; then install -d -o www-data -g www-data -m 750 "$private_root"; fi
mkdir -p "$tools"
if [[ ! -x "$venv/bin/python3" ]]; then python3 -m venv "$venv"; fi
"$venv/bin/python3" -m pip install --no-cache-dir --only-binary=:all: --require-hashes -r "$source_dir/requirements-ocr.txt"
if [[ ! -x "$tools/deno" ]]; then
 read -r deno_url deno_sha < <(python3 - "$source_dir/requirements.json" "$platform" <<'PY'
import json,sys
r={x['id']:x for x in json.load(open(sys.argv[1]))['requirements']}
print(*r['deno']['sources'][sys.argv[2]])
PY
 )
 case "$deno_url" in https://github.com/denoland/deno/releases/download/*) ;; *) echo 'Unexpected video runtime source.' >&2; exit 1;; esac
 temp_dir="$(mktemp -d)";trap 'rm -rf "$temp_dir"' EXIT
 curl --fail --location --proto '=https' --proto-redir '=https' --retry 3 "$deno_url" -o "$temp_dir/deno.zip"
 [[ "$(sha256sum "$temp_dir/deno.zip" | cut -d' ' -f1)" == "$deno_sha" ]] || { echo 'Video runtime checksum failed.' >&2; exit 1; }
 python3 - "$temp_dir/deno.zip" "$tools" <<'PY'
import zipfile,sys
with zipfile.ZipFile(sys.argv[1]) as z:z.extract('deno',sys.argv[2])
PY
 chmod 755 "$tools/deno"
fi
# Dependencies are readable/executable by PHP; its private job/cache folder is writable only by it.
chmod 755 "$tools"
install -d -o www-data -g www-data -m 700 "$private_root/community-learning-work"
rm -f "$private_root/community-server-health.json"
runuser -u www-data -- "$venv/bin/python3" "$source_dir/server_run.py" --check

# Initialize the installed OCR models once, with a blank fixture rather than match data.
# This also catches missing native libraries before Neptune reports itself ready.
systemd-run --wait --pipe --collect --unit=neptune-learning-ocr-check --property=CPUQuota=10% --property=MemoryMax=850M --property=Environment=OMP_NUM_THREADS=1 --property=Environment=OPENBLAS_NUM_THREADS=1 "$venv/bin/python3" - "$source_dir" <<'PYTHON'
import sys,tempfile
from pathlib import Path
sys.path.insert(0,sys.argv[1])
from neptune_ocr import select_backend
with tempfile.TemporaryDirectory() as folder:
 frame=Path(folder)/'blank.ppm';frame.write_bytes(b'P6\n256 128\n255\n'+bytes(256*128*3))
 backend=select_backend();result=backend.recognize([str(frame)])
 print('OCR runtime verified:',backend.name,'fixture frames:',len(result))
PYTHON

# A dedicated service bounds actual memory and CPU, including OCR/video child processes.
# Virtual-address limits would prevent Deno/Node from reserving their normal JS heaps.
cat > /etc/systemd/system/neptune-community-learning.service <<EOF
[Unit]
Description=Neptune background model training
After=network-online.target apache2.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$source_dir
ExecStart=/usr/bin/php $app_root/neptune_secure/community-learning-worker.php --daemon
Environment=NEPTUNE_SERVER_CGROUP=1
Nice=15
CPUQuota=10%
MemoryHigh=650M
MemoryMax=850M
MemorySwapMax=1G
TasksMax=96
KillMode=control-group
Restart=on-failure
RestartSec=5
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now neptune-community-learning.service
systemctl is-active neptune-community-learning.service
