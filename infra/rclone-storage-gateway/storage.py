import hashlib
import hmac
import base64
import os
import sqlite3
import time
from contextlib import contextmanager


DB_PATH = os.environ.get("SN_GATEWAY_DB", "/var/lib/servernet-gateway/tenants.sqlite3")


@contextmanager
def connection():
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
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
                id TEXT PRIMARY KEY,
                username TEXT NOT NULL UNIQUE,
                quota_bytes INTEGER NOT NULL CHECK (quota_bytes > 0),
                used_bytes INTEGER NOT NULL DEFAULT 0 CHECK (used_bytes >= 0),
                pool TEXT NOT NULL,
                customer_ref TEXT NOT NULL,
                s3_access_key TEXT UNIQUE,
                credential_version INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'suspended', 'retired')),
                retire_after INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS request_nonces (
                nonce TEXT PRIMARY KEY,
                seen_at INTEGER NOT NULL
            );
        """)
        columns = {row[1] for row in conn.execute("PRAGMA table_info(tenants)")}
        if "s3_access_key" not in columns:
            conn.execute("ALTER TABLE tenants ADD COLUMN s3_access_key TEXT")
        if "credential_version" not in columns:
            conn.execute("ALTER TABLE tenants ADD COLUMN credential_version INTEGER NOT NULL DEFAULT 1")
        for row in conn.execute("SELECT id FROM tenants WHERE s3_access_key IS NULL"):
            conn.execute("UPDATE tenants SET s3_access_key = ? WHERE id = ?", (make_s3_access_key(row[0]), row[0]))
        conn.execute("CREATE UNIQUE INDEX IF NOT EXISTS tenants_s3_access_key_unique ON tenants(s3_access_key)")


def password_matches(row, password):
    if not isinstance(password, str) or len(password) > 256:
        return False
    return hmac.compare_digest(access_secret(row), password)


def make_s3_access_key(tenant_id):
    # شناسه عمومی و پایدار است؛ هیچ secretای از tenant id مشتق نمی‌شود.
    digest = hashlib.sha256(str(tenant_id).encode()).hexdigest().upper()
    return "SN" + digest[:24]


def access_secret(row):
    seed = os.environ.get("SN_ACCESS_SECRET_SEED", "")
    if len(seed) < 32:
        raise RuntimeError("SN_ACCESS_SECRET_SEED must contain at least 32 characters")
    version = int(row["credential_version"])
    material = f"{row['id']}:{version}".encode()
    digest = hmac.new(seed.encode(), material, hashlib.sha256).digest()
    return base64.urlsafe_b64encode(digest).decode().rstrip("=")


def credential_bundle(row):
    secret = access_secret(row)
    return {
        "username": row["username"],
        "password": secret,
        "s3_access_key": row["s3_access_key"],
        "s3_secret_access_key": secret,
    }


def public_tenant(row):
    return {
        "id": row["id"], "username": row["username"], "quota_bytes": row["quota_bytes"],
        "used_bytes": row["used_bytes"], "pool": row["pool"], "status": row["status"],
        "retire_after": row["retire_after"],
        "endpoint": os.environ.get("SN_SFTP_PUBLIC_HOST", ""),
        "port": int(os.environ.get("SN_SFTP_PUBLIC_PORT", "2022")),
        "webdav_url": os.environ.get("SN_WEBDAV_PUBLIC_URL", ""),
        "s3_endpoint": os.environ.get("SN_S3_PUBLIC_ENDPOINT", ""),
        "s3_access_key": row["s3_access_key"],
        "s3_bucket": os.environ.get("SN_S3_BUCKET", "backup"),
    }


def reserve_nonce(nonce, now, ttl=300):
    with connection() as conn:
        conn.execute("DELETE FROM request_nonces WHERE seen_at < ?", (now - ttl,))
        try:
            conn.execute("INSERT INTO request_nonces(nonce, seen_at) VALUES (?, ?)", (nonce, now))
            return True
        except sqlite3.IntegrityError:
            return False


def remaining_capacity(conn):
    capacity = int(os.environ.get("SN_CAPACITY_BYTES", "0"))
    reserve = int(os.environ.get("SN_RESERVE_BYTES", "0"))
    allocated = conn.execute(
        "SELECT COALESCE(SUM(quota_bytes), 0) FROM tenants WHERE status != 'retired'"
    ).fetchone()[0]
    return max(0, capacity - reserve - int(allocated))
