#!/usr/bin/env python3
"""ott-dev Novamira REST-ability client.

The MCP JSON-RPC endpoint (/wp-json/mcp/novamira) rejects our stored app
password with rest_forbidden, but the REST-first abilities shim
(novamira/v1/abilities/{name}/run, body {"input": {...}}) authenticates
fine with the same credentials. This client wraps that shim.

Usage:
  python3 ott-dev.py php 'return 1+1;'
  python3 ott-dev.py cli '["plugin","list","--format=csv"]'   (JSON array of args)
  python3 ott-dev.py raw novamira/execute-php '{"code":"return 42;"}'

Credentials come from Qoder's mcp.json (novamira-ott-dev-onedog-s entry)
and are never printed.
"""
import base64
import json
import os
import sys
import urllib.error
import urllib.request

MCP_JSON = os.path.expanduser(
    "~/Library/Application Support/Qoder/SharedClientCache/extension/local/mcp.json"
)
SERVER = "novamira-ott-dev-onedog-s"
BASE = "https://ott-dev.onedog.solutions/wp-json"


def load_auth():
    with open(MCP_JSON) as f:
        cfg = json.load(f)
    env = cfg["mcpServers"][SERVER]["env"]
    token = base64.b64encode(
        f"{env['WP_API_USERNAME']}:{env['WP_API_PASSWORD']}".encode()
    ).decode()
    return "Basic " + token


def run_ability(name, ability_input, timeout=120):
    url = f"{BASE}/novamira/v1/abilities/{name}/run"
    body = json.dumps({"input": ability_input}).encode()
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Authorization": load_auth(),
            "Content-Type": "application/json",
            "User-Agent": "ekti-build-client/1.0",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {"raw": "non-JSON error body"}
    except Exception as e:
        return None, {"error": str(e)}


def show(label, status, payload, max_len=6000):
    print(f"===== {label} [HTTP {status}] =====")
    text = json.dumps(payload, indent=1, ensure_ascii=False)
    if len(text) > max_len:
        text = text[:max_len] + "\n... [truncated]"
    print(text)


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 2
    mode, arg = sys.argv[1], sys.argv[2]

    if mode == "php":
        status, payload = run_ability("novamira/execute-php", {"code": arg})
        show("execute-php", status, payload)
        return 0 if status == 200 and payload.get("success") else 1
    if mode == "cli":
        args = json.loads(arg)
        status, payload = run_ability("novamira/run-wp-cli", {"args": args})
        show("run-wp-cli", status, payload)
        return 0 if status == 200 and payload.get("success") else 1
    if mode == "raw":
        name = arg
        ability_input = json.loads(sys.argv[3]) if len(sys.argv) > 3 else {}
        status, payload = run_ability(name, ability_input)
        show(f"raw {name}", status, payload)
        return 0 if status == 200 else 1
    print("unknown mode:", mode)
    return 2


if __name__ == "__main__":
    sys.exit(main())
