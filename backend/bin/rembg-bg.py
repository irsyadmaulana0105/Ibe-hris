#!/usr/bin/env python3
"""Remove background dari foto profil karyawan via rembg + U2Net.
Output: PNG dengan alpha channel.
"""
import sys
from rembg import remove

if len(sys.argv) != 3:
    print("Usage: rembg-bg.py <input> <output>", file=sys.stderr)
    sys.exit(2)

with open(sys.argv[1], "rb") as i, open(sys.argv[2], "wb") as o:
    o.write(remove(i.read()))
print("OK")
