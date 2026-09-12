import hashlib
import hmac
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
                password_salt BLOB NOT NULL,
                password_hash BLOB NOT NULL,
                quota_bytes INTEGER NOT NULL CHECK (quota_bytes > 0),
                used_bytes INTEGER NOT NULL DEFAULT 0 CHECK (used_bytes >= 0),
                pool TEXT NOT NULL,
                customer_ref TEXT NOT NULL,
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


def password_record(password):
    if not isinstance(password, str) or len(password) < 20:
        raise ValueError("password_too_short")
    salt = os.urandom(16)
    digest = hashlib.scrypt(password.encode(), salt=salt, n=2**14, r=8, p=1, dklen=32)
    return salt, digest


def password_matches(row, password):
    if not isinstance(password, str):
        return False
    candidate = hashlib.scrypt(password.encode(), salt=row["password_salt"], n=2**14, r=8, p=1, dklen=32)
    return hmac.compare_digest(candidate, row["password_hash"])


def public_tenant(row):
    return {
        "id": row["id"], "username": row["username"], "quota_bytes": row["quota_bytes"],
        "used_bytes": row["used_bytes"], "pool": row["pool"], "status": row["status"],
        "retire_after": row["retire_after"],
        "endpoint": os.environ.get("SN_SFTP_PUBLIC_HOST", ""),
        "port": int(os.environ.get("SN_SFTP_PUBLIC_PORT", "2022")),
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
