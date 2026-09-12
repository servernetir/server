import json
import os
import subprocess
import time

import storage


def main():
    storage.initialize()
    remote = os.environ.get("SN_RCLONE_REMOTE", "poolcrypt")
    with storage.connection() as conn:
        tenants = conn.execute("SELECT id FROM tenants WHERE status != 'retired'").fetchall()
    for tenant in tenants:
        try:
            result = subprocess.run(
                ["rclone", "size", "--json", f"{remote}:tenants/{tenant['id']}"],
                check=True, capture_output=True, text=True, timeout=1800,
            )
            used = max(0, int(json.loads(result.stdout).get("bytes", 0)))
            with storage.connection() as conn:
                conn.execute("UPDATE tenants SET used_bytes = ?, updated_at = ? WHERE id = ?",
                             (used, int(time.time()), tenant["id"]))
        except Exception as exc:
            print(f"usage scan failed for {tenant['id']}: {exc}", flush=True)


if __name__ == "__main__":
    main()
