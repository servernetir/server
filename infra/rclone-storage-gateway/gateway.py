import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import storage


TENANT_PATH = re.compile(r"^/v1/tenants/(sn-svc-[0-9]+)(?:/(suspend|unsuspend|credentials))?$")


class Api(BaseHTTPRequestHandler):
    server_version = "ServerNetStorageGateway/1"

    def log_message(self, fmt, *args):
        print("gateway", self.address_string(), fmt % args, flush=True)

    def json_response(self, status, payload):
        body = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def read_body(self):
        length = int(self.headers.get("Content-Length", "0"))
        if length > 65536:
            raise ValueError("body_too_large")
        return self.rfile.read(length)

    def authenticated_body(self):
        body = self.read_body()
        secret = os.environ.get("SN_GATEWAY_HMAC_SECRET", "")
        timestamp = self.headers.get("X-ServerNet-Timestamp", "")
        nonce = self.headers.get("X-ServerNet-Nonce", "")
        supplied = self.headers.get("X-ServerNet-Signature", "")
        if len(secret) < 32 or not timestamp.isdigit() or not re.fullmatch(r"[0-9a-f]{24}", nonce):
            return None
        now = int(time.time())
        if abs(now - int(timestamp)) > 300:
            return None
        canonical = b"\n".join([
            self.command.encode(), self.path.encode(), timestamp.encode(), nonce.encode(), body
        ])
        expected = hmac.new(secret.encode(), canonical, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, supplied) or not storage.reserve_nonce(nonce, now):
            return None
        return body

    def dispatch(self):
        body = self.authenticated_body()
        if body is None:
            return self.json_response(401, {"error": "unauthorized"})
        try:
            payload = json.loads(body) if body else {}
        except (ValueError, UnicodeDecodeError):
            return self.json_response(400, {"error": "invalid_json"})

        if self.path == "/v1/health" and self.command == "GET":
            with storage.connection() as conn:
                return self.json_response(200, {
                    "ok": True, "free_bytes": storage.remaining_capacity(conn),
                    "sftp_host": os.environ.get("SN_SFTP_PUBLIC_HOST", ""),
                })

        match = TENANT_PATH.fullmatch(self.path)
        if not match:
            return self.json_response(404, {"error": "not_found"})
        tenant_id, action = match.groups()

        with storage.connection() as conn:
            row = conn.execute("SELECT * FROM tenants WHERE id = ?", (tenant_id,)).fetchone()
            if self.command == "GET" and action is None:
                return self.json_response(200, {"tenant": storage.public_tenant(row)}) if row else self.json_response(404, {"error": "not_found"})

            if self.command == "PUT" and action is None:
                if row:
                    try:
                        expected = (str(payload.get("username", "")), int(payload.get("quota_bytes", 0)), str(payload.get("pool", "")), str(payload.get("customer_ref", "")))
                    except (TypeError, ValueError):
                        return self.json_response(422, {"error": "invalid_tenant"})
                    actual = (row["username"], row["quota_bytes"], row["pool"], row["customer_ref"])
                    if expected != actual:
                        return self.json_response(409, {"error": "tenant_spec_mismatch"})
                    return self.json_response(200, {"tenant": storage.public_tenant(row), "reused": True})
                try:
                    username = str(payload["username"])
                    password = payload["password"]
                    quota = int(payload["quota_bytes"])
                    pool = str(payload["pool"])
                    customer_ref = str(payload["customer_ref"])
                    if not re.fullmatch(r"sn[0-9]+", username) or quota <= 0 or not re.fullmatch(r"[a-z0-9_-]+", pool):
                        raise ValueError("invalid_tenant")
                    salt, digest = storage.password_record(password)
                    conn.execute("BEGIN IMMEDIATE")
                    if quota > storage.remaining_capacity(conn):
                        conn.rollback()
                        return self.json_response(409, {"error": "insufficient_capacity"})
                    now = int(time.time())
                    conn.execute("INSERT INTO tenants VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'active', NULL, ?, ?)",
                                 (tenant_id, username, salt, digest, quota, pool, customer_ref, now, now))
                    row = conn.execute("SELECT * FROM tenants WHERE id = ?", (tenant_id,)).fetchone()
                    # پاسخ موفق فقط پس از durable شدن رکورد؛ وگرنه retry پنل 404
                    # می‌بیند و تلاش دوم می‌تواند tenant دیگری بسازد.
                    conn.commit()
                    return self.json_response(201, {"tenant": storage.public_tenant(row)})
                except (KeyError, TypeError, ValueError, sqlite3.IntegrityError):
                    return self.json_response(422, {"error": "invalid_tenant"})

            if not row:
                return self.json_response(404, {"error": "not_found"})

            now = int(time.time())
            if self.command == "POST" and action in ("suspend", "unsuspend"):
                if row["status"] == "retired":
                    return self.json_response(409, {"error": "tenant_retired"})
                status = "suspended" if action == "suspend" else "active"
                conn.execute("UPDATE tenants SET status = ?, retire_after = NULL, updated_at = ? WHERE id = ?", (status, now, tenant_id))
            elif self.command == "POST" and action == "credentials":
                if row["status"] == "retired":
                    return self.json_response(409, {"error": "tenant_retired"})
                try:
                    salt, digest = storage.password_record(payload["password"])
                except (KeyError, TypeError, ValueError):
                    return self.json_response(422, {"error": "invalid_password"})
                conn.execute("UPDATE tenants SET password_salt = ?, password_hash = ?, updated_at = ? WHERE id = ?", (salt, digest, now, tenant_id))
            elif self.command == "DELETE" and action is None:
                days = min(90, max(1, int(payload.get("retention_days", 30))))
                conn.execute("UPDATE tenants SET status = 'retired', retire_after = ?, updated_at = ? WHERE id = ?", (now + days * 86400, now, tenant_id))
            else:
                return self.json_response(405, {"error": "method_not_allowed"})
            row = conn.execute("SELECT * FROM tenants WHERE id = ?", (tenant_id,)).fetchone()
            conn.commit()
            return self.json_response(200, {"tenant": storage.public_tenant(row)})

    do_GET = dispatch
    do_PUT = dispatch
    do_POST = dispatch
    do_DELETE = dispatch


if __name__ == "__main__":
    storage.initialize()
    host = os.environ.get("SN_API_BIND", "127.0.0.1")
    port = int(os.environ.get("SN_API_PORT", "9080"))
    ThreadingHTTPServer((host, port), Api).serve_forever()
