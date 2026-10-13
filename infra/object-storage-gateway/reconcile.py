import time

import provider
import storage


def main():
    storage.initialize()
    with storage.connection() as conn:
        rows = conn.execute("SELECT * FROM tenants").fetchall()
    now = int(time.time())
    for item in rows:
        try:
            if item["status"] == "retired" and item["retire_after"] and item["retire_after"] <= now:
                provider.purge_bucket(item["region"], item["bucket"])
                with storage.connection() as conn:
                    conn.execute("DELETE FROM tenants WHERE id=?", (item["id"],))
                continue
            if item["status"] == "retired":
                continue
            used = provider.used_bytes(item["region"], item["bucket"])
            status = item["status"]
            if status == "active" and used >= item["quota_bytes"]:
                provider.delete_user(item["region"], item["access_key"])
                status = "overquota"
            with storage.connection() as conn:
                conn.execute("UPDATE tenants SET used_bytes=?,status=?,updated_at=? WHERE id=?",
                             (used, status, now, item["id"]))
        except Exception as exc:
            print("reconcile failed", item["id"], type(exc).__name__, flush=True)


if __name__ == "__main__":
    main()
