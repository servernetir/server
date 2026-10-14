import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import provider
import storage


TENANT_PATH = re.compile(r"^/v1/tenants/(sn-svc-[0-9]+)(?:/(suspend|unsuspend|credentials))?$")
REGIONS = {"fsn1", "nbg1", "hel1"}


class Api(BaseHTTPRequestHandler):
    server_version = "ServerNetObjectGateway/1"

    def log_message(self, fmt, *args):
        print("object-gateway", self.address_string(), fmt % args, flush=True)

    def reply(self, status, payload):
        body = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def authenticated_body(self):
        length = int(self.headers.get("Content-Length", "0"))
        if length > 65536:
            return None
        body = self.rfile.read(length)
        secret = os.environ.get("SN_OBJECT_HMAC_SECRET", "")
        stamp = self.headers.get("X-ServerNet-Timestamp", "")
        nonce = self.headers.get("X-ServerNet-Nonce", "")
        supplied = self.headers.get("X-ServerNet-Signature", "")
        if len(secret) < 32 or not stamp.isdigit() or not re.fullmatch(r"[0-9a-f]{24}", nonce):
            return None
        now = int(time.time())
        canonical = b"\n".join([self.command.encode(), self.path.encode(), stamp.encode(), nonce.encode(), body])
        expected = hmac.new(secret.encode(), canonical, hashlib.sha256).hexdigest()
        if abs(now - int(stamp)) > 300 or not hmac.compare_digest(expected, supplied):
            return None
        return body if storage.reserve_nonce(nonce, now) else None

    def dispatch(self):
        body = self.authenticated_body()
        if body is None:
            return self.reply(401, {"error": "unauthorized"})
        try:
            payload = json.loads(body) if body else {}
        except ValueError:
            return self.reply(400, {"error": "invalid_json"})
        endpoints = provider.regions()

        if self.path == "/v1/health" and self.command == "GET":
            states = {}
            for region in sorted(REGIONS):
                try:
                    states[region] = provider.health(region)
                except Exception:
                    states[region] = False
            return self.reply(200, {"ok": True, "ready": all(states.values()), "regions": states})

        if self.path == "/v1/status" and self.command == "GET":
            with storage.connection() as conn:
                rows = conn.execute("SELECT status, COUNT(*) AS total FROM tenants GROUP BY status").fetchall()
            counts = {row["status"]: int(row["total"]) for row in rows}
            return self.reply(200, {"ok": True, "tenant_count": sum(counts.values()), "tenants": counts})

        match = TENANT_PATH.fullmatch(self.path)
        if not match:
            return self.reply(404, {"error": "not_found"})
        tenant_id, action = match.groups()

        with storage.connection() as conn:
            row = conn.execute("SELECT * FROM tenants WHERE id=?", (tenant_id,)).fetchone()
            if self.command == "GET" and action is None:
                return self.reply(200, {"tenant": storage.public_tenant(row, endpoints)}) if row else self.reply(404, {"error": "not_found"})

            if self.command == "PUT" and action is None:
                try:
                    region = str(payload["region"])
                    quota = int(payload["quota_bytes"])
                    customer_ref = str(payload["customer_ref"])
                    if region not in REGIONS or quota <= 0 or not customer_ref:
                        raise ValueError()
                except (KeyError, TypeError, ValueError):
                    return self.reply(422, {"error": "invalid_tenant"})
                now = int(time.time())
                if row:
                    if row["region"] != region or row["customer_ref"] != customer_ref:
                        return self.reply(409, {"error": "tenant_spec_mismatch"})
                    if quota < row["used_bytes"]:
                        return self.reply(409, {"error": "quota_below_usage"})
                    conn.execute("UPDATE tenants SET quota_bytes=?, updated_at=? WHERE id=?", (quota, now, tenant_id))
                    if row["status"] == "overquota" and quota > row["used_bytes"]:
                        conn.execute("UPDATE tenants SET status='active' WHERE id=?", (tenant_id,))
                    row = conn.execute("SELECT * FROM tenants WHERE id=?", (tenant_id,)).fetchone()
                    if row["status"] == "active":
                        provider.create_or_update_user(region, row["access_key"], storage.secret_for(row))
                    conn.commit()
                    return self.reply(200, {"tenant": storage.public_tenant(row, endpoints), "reused": True})

                access = storage.access_key(tenant_id)
                bucket = ("sn-%s-%s" % (region, tenant_id.replace("sn-svc-", "")))[:63]
                try:
                    conn.execute("BEGIN IMMEDIATE")
                    conn.execute("""INSERT INTO tenants
                      (id,region,bucket,access_key,quota_bytes,customer_ref,status,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,'active',?,?)""",
                      (tenant_id, region, bucket, access, quota, customer_ref, now, now))
                    row = conn.execute("SELECT * FROM tenants WHERE id=?", (tenant_id,)).fetchone()
                    secret = storage.secret_for(row)
                    provider.create_or_update_user(region, access, secret)
                    provider.create_or_assign_bucket(region, bucket, access)
                    conn.commit()
                    return self.reply(201, {"tenant": storage.public_tenant(row, endpoints),
                        "credentials": {"access_key": access, "secret_access_key": secret}})
                except (sqlite3.IntegrityError, RuntimeError):
                    conn.rollback()
                    return self.reply(502, {"error": "provider_create_failed"})

            if not row:
                return self.reply(404, {"error": "not_found"})
            now = int(time.time())
            try:
                if self.command == "POST" and action == "suspend":
                    provider.disable_user(row["region"], row["access_key"])
                    conn.execute("UPDATE tenants SET status='suspended',updated_at=? WHERE id=?", (now, tenant_id))
                elif self.command == "POST" and action == "unsuspend":
                    if row["status"] == "retired" or row["used_bytes"] >= row["quota_bytes"]:
                        return self.reply(409, {"error": "tenant_unavailable"})
                    provider.create_or_update_user(row["region"], row["access_key"], storage.secret_for(row))
                    conn.execute("UPDATE tenants SET status='active',retire_after=NULL,updated_at=? WHERE id=?", (now, tenant_id))
                elif self.command == "POST" and action == "credentials":
                    if row["status"] == "retired":
                        return self.reply(409, {"error": "tenant_retired"})
                    conn.execute("UPDATE tenants SET credential_version=credential_version+1,updated_at=? WHERE id=?", (now, tenant_id))
                    row = conn.execute("SELECT * FROM tenants WHERE id=?", (tenant_id,)).fetchone()
                    secret = storage.secret_for(row)
                    if row["status"] == "active":
                        provider.update_user(row["region"], row["access_key"], secret)
                    conn.commit()
                    return self.reply(200, {"tenant": storage.public_tenant(row, endpoints),
                        "credentials": {"access_key": row["access_key"], "secret_access_key": secret}})
                elif self.command == "DELETE" and action is None:
                    days = min(90, max(1, int(payload.get("retention_days", 30))))
                    provider.disable_user(row["region"], row["access_key"])
                    conn.execute("UPDATE tenants SET status='retired',retire_after=?,updated_at=? WHERE id=?", (now + days * 86400, now, tenant_id))
                else:
                    return self.reply(405, {"error": "method_not_allowed"})
                row = conn.execute("SELECT * FROM tenants WHERE id=?", (tenant_id,)).fetchone()
                conn.commit()
                return self.reply(200, {"tenant": storage.public_tenant(row, endpoints)})
            except Exception:
                conn.rollback()
                return self.reply(502, {"error": "provider_operation_failed"})

    do_GET = dispatch
    do_PUT = dispatch
    do_POST = dispatch
    do_DELETE = dispatch


if __name__ == "__main__":
    storage.initialize()
    ThreadingHTTPServer((os.environ.get("SN_OBJECT_API_BIND", "127.0.0.1"),
                         int(os.environ.get("SN_OBJECT_API_PORT", "9180"))), Api).serve_forever()
