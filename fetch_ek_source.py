#!/usr/bin/env python3
"""Fetch a server file via novamira/execute-php in base64 chunks.

Usage: python3 fetch_ek_source.py <remote-path> <local-out>
"""
import base64
import importlib.util
import json
import os
import sys

_spec = importlib.util.spec_from_file_location(
    "ott_dev", os.path.join(os.path.dirname(os.path.abspath(__file__)), "ott-dev.py")
)
_ott = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_ott)
run_ability = _ott.run_ability

CHUNK = 16000  # bytes of source per request (base64 inflates ~4/3)


def main():
    remote, local = sys.argv[1], sys.argv[2]
    parts = []
    offset = 0
    while True:
        code = (
            "$p = %r; $d = @file_get_contents($p);"
            "if ($d === false) { return ['error' => 'unreadable']; }"
            "$c = substr($d, %d, %d);"
            "return ['len' => strlen($d), 'chunk' => base64_encode($c)];"
            % (remote, offset, CHUNK)
        )
        status, payload = run_ability("novamira/execute-php", {"code": code})
        if status != 200 or not payload.get("success"):
            print("HTTP", status, payload)
            sys.exit(1)
        result = payload.get("return_value")
        if isinstance(result, str):
            result = json.loads(result)
        if not isinstance(result, dict) or "len" not in result:
            print("Unexpected payload:", json.dumps(payload)[:500])
            sys.exit(1)
        total = int(result["len"])
        parts.append(base64.b64decode(result["chunk"]))
        offset += CHUNK
        print(f"  fetched {min(offset, total)}/{total} bytes", file=sys.stderr)
        if offset >= total:
            break
    data = b"".join(parts)
    with open(local, "wb") as f:
        f.write(data)
    print(f"wrote {len(data)} bytes to {local}")


if __name__ == "__main__":
    main()
