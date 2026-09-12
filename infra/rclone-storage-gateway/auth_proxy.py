#!/usr/bin/env python3

import json
import os
import subprocess
import sys

import storage


def deny():
    sys.exit(1)


try:
    request = json.load(sys.stdin)
    username = str(request.get("user", ""))
    password = request.get("pass")
    storage.initialize()
    with storage.connection() as conn:
        row = conn.execute("SELECT * FROM tenants WHERE username = ?", (username,)).fetchone()
        if not row or row["status"] != "active" or row["used_bytes"] >= row["quota_bytes"]:
            deny()
        if not storage.password_matches(row, password):
            deny()

    remote = os.environ.get("SN_RCLONE_REMOTE", "poolcrypt")
    if not remote.replace("-", "").replace("_", "").isalnum():
        deny()
    result = subprocess.run(
        ["rclone", "rc", "--loopback", "config/get", "name=" + remote],
        check=True, capture_output=True, text=True, timeout=15,
    )
    config = json.loads(result.stdout)
    config["_root"] = "tenants/" + row["id"]
    print(json.dumps(config, separators=(",", ":")))
except Exception:
    deny()
