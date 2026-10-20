#!/bin/bash
# Auto-remove background dari foto profil karyawan. Usage: rembg-bg <input> <output>
export U2NET_HOME=/var/lib/rembg-models
export HOME=/tmp
export NUMBA_CACHE_DIR=/tmp/numba-cache
export NUMBA_DISABLE_JIT=1
mkdir -p /tmp/numba-cache 2>/dev/null
exec /opt/rembg-venv/bin/python /usr/local/bin/rembg-bg.py "$1" "$2"
