import base64
import hashlib
import hmac
import os
import sqlite3
from contextlib import contextmanager


DB_PATH = os.environ.get("SN_OBJECT_DB", "/var/lib/servernet-object-gateway/tenants.sqlite3")


@contextmanager
def connection():
    conn = sqlite3.connect(DB_PATH, timeout=15)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def initialize():
    os.makedirs(os.path.dirname(os.path.abspath(DB_PATH)), mode=0o700, exist_ok=True)
    with connection() as conn:
        conn.executescript("""
        CREATE TABLE IF NOT EXISTS tenants (
          id TEXT PRIMARY KEY, region TEXT NOT NULL, bucket TEXT NOT NULL UNIQUE,
          access_key TEXT NOT NULL UNIQUE, credential_version INTEGER NOT NULL DEFAULT 1,
          quota_bytes INTEGER NOT NULL CHECK(quota_bytes > 0), used_bytes INTEGER NOT NULL DEFAULT 0,
          customer_ref TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active'
            CHECK(status IN ('active','suspended','overquota','retired')),
          retire_after INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS request_nonces (nonce TEXT PRIMARY KEY, seen_at INTEGER NOT NULL);
        """)


def access_key(tenant_id):
    return "SN" + hashlib.sha256(str(tenant_id).encode()).hexdigest().upper()[:24]


def secret_for(row):
    seed = os.environ.get("SN_OBJECT_CREDENTIAL_SEED", "")
    if len(seed) < 32:
        raise RuntimeError("credential seed is not configured")
    raw = hmac.new(seed.encode(), f"{row['id']}:{row['credential_version']}".encode(), hashlib.sha256).digest()
    return base64.urlsafe_b64encode(raw).decode().rstrip("=")


def public_tenant(row, endpoints):
    return {
        "id": row["id"], "region": row["region"], "bucket": row["bucket"],
        "access_key": row["access_key"], "quota_bytes": row["quota_bytes"],
        "used_bytes": row["used_bytes"], "status": row["status"],
        "retire_after": row["retire_after"], "endpoint": endpoints[row["region"]]["public_endpoint"],
    }


def reserve_nonce(nonce, now, ttl=300):
    with connection() as conn:
        conn.execute("DELETE FROM request_nonces WHERE seen_at < ?", (now - ttl,))
        try:
            conn.execute("INSERT INTO request_nonces VALUES (?, ?)", (nonce, now))
            return True
        except sqlite3.IntegrityError:
            return False
