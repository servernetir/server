#!/usr/bin/env python3

import json
import os
import subprocess
import sys

import storage


def backend_for(request):
    username = str(request.get("user", ""))
    password = request.get("pass")
    is_s3 = "pass" not in request and "public_key" not in request
    if "public_key" in request:
        raise PermissionError("public_key_auth_not_enabled")

    storage.initialize()
    with storage.connection() as conn:
        column = "s3_access_key" if is_s3 else "username"
        row = conn.execute(f"SELECT * FROM tenants WHERE {column} = ?", (username,)).fetchone()
        if not row or row["status"] != "active" or row["used_bytes"] >= row["quota_bytes"]:
            raise PermissionError("tenant_unavailable")
        if not is_s3 and not storage.password_matches(row, password):
            raise PermissionError("invalid_credentials")

    remote = os.environ.get("SN_RCLONE_REMOTE", "poolcrypt")
    if not remote.replace("-", "").replace("_", "").isalnum():
        raise RuntimeError("invalid_remote")
    result = subprocess.run(
        ["rclone", "rc", "--loopback", "config/get", "name=" + remote],
        check=True, capture_output=True, text=True, timeout=15,
    )
    config = json.loads(result.stdout)
    # SFTP/WebDAV مستقیماً داخل bucket ثابت backup قرار می‌گیرند. S3 یک سطح
    # بالاتر می‌ایستد تا همان پوشه را به‌عنوان bucket با نام backup ببیند.
    config["_root"] = "tenants/" + row["id"] + ("" if is_s3 else "/backup")
    if is_s3:
        config["_secret_access_key"] = storage.access_secret(row)
    return config


def main():
    try:
        print(json.dumps(backend_for(json.load(sys.stdin)), separators=(",", ":")))
    except Exception:
        sys.exit(1)


if __name__ == "__main__":
    main()
