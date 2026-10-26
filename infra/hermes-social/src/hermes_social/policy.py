from __future__ import annotations

import hashlib
import json
import os
import re
import stat
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

ACCOUNTS = {"FA": "servernet.ir", "EN": "servernet.cloud", "TR": "servernet.tr"}
ID = re.compile(r"^[A-Za-z0-9_-]{1,160}$")


class Blocked(Exception):
    """Public, credential-free explanation safe to return to an MCP caller."""


def identifier(value: str) -> str:
    if not isinstance(value, str) or not ID.fullmatch(value):
        raise Blocked("invalid_identifier")
    return value


def utc(value: str) -> datetime:
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if result.tzinfo is None:
            raise ValueError()
        return result.astimezone(timezone.utc)
    except (ValueError, TypeError, AttributeError):
        raise Blocked("timestamp_requires_timezone") from None


def digest(value: dict) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False,
                                     separators=(",", ":")).encode()).hexdigest()


def private_file(path: str | Path) -> Path:
    p = Path(path).expanduser().resolve()
    if not p.is_file():
        raise Blocked("private_file_missing")
    if os.name != "nt" and stat.S_IMODE(p.stat().st_mode) & 0o077:
        raise Blocked("private_file_permissions_must_be_0600")
    return p


def secret(path: str) -> str:
    value = private_file(path).read_text().strip()
    if not value or "\n" in value:
        raise Blocked("invalid_secret_file")
    return value


def https_url(value: str) -> str:
    u = urlsplit(value)
    if u.scheme != "https" or not u.hostname or u.username or u.password or u.fragment:
        raise Blocked("https_url_required")
    return value


class Policy:
    def __init__(self, path: str | Path):
        self.path = private_file(path)
        self.data = json.loads(self.path.read_text())
        self.root = identifier(self.data["drive_root_folder_id"])
        self.state = Path(self.data["state_dir"]).expanduser().resolve()
        self.state.mkdir(parents=True, exist_ok=True, mode=0o700)
        if os.name != "nt" and self.state.stat().st_mode & 0o077:
            raise Blocked("state_directory_permissions_must_be_0700")
        self.manifest_path = private_file(self.data["manifest_path"])
        manifest = json.loads(self.manifest_path.read_text())
        if manifest.get("schema_version") != 1 or manifest.get("drive_root_folder_id") != self.root:
            raise Blocked("manifest_scope_mismatch")
        identifier(manifest["campaign_id"])
        self.jobs = {}
        for job in manifest["jobs"]:
            self.validate(job)
            key = identifier(job["key"])
            if key in self.jobs:
                raise Blocked("duplicate_manifest_key")
            self.jobs[key] = job

    def validate(self, j: dict):
        if j.get("lang") not in ACCOUNTS or j.get("username") != ACCOUNTS[j["lang"]]:
            raise Blocked("manifest_account_mismatch")
        if j.get("kind") not in {"IMAGE", "CAROUSEL", "STORY", "REEL"}:
            raise Blocked("unsupported_kind")
        assets = j.get("assets", [])
        if not 1 <= len(assets) <= 10 or (j["kind"] != "CAROUSEL" and len(assets) != 1):
            raise Blocked("invalid_asset_count")
        if j["kind"] == "CAROUSEL" and len(assets) < 2:
            raise Blocked("carousel_requires_multiple_assets")
        if not isinstance(j.get("caption", ""), str) or len(j.get("caption", "")) > 2200:
            raise Blocked("caption_too_long")
        start, end = utc(j["publish_at"]), utc(j["expires_at"])
        if end <= start or (end - start).total_seconds() > 86400:
            raise Blocked("invalid_publication_window")
        for asset in assets:
            identifier(asset["drive_id"])
            if not re.fullmatch(r"[a-f0-9]{64}", asset.get("sha256", "")):
                raise Blocked("asset_digest_required")
            allowed = {"video/mp4"} if j["kind"] == "REEL" else {"image/png", "image/jpeg"}
            if asset.get("mime_type") not in allowed:
                raise Blocked("unsupported_asset_type")

    def job(self, key: str) -> dict:
        if key not in self.jobs:
            raise Blocked("job_not_in_owner_manifest")
        return self.jobs[key]

    def enabled(self, *, replies=False):
        # Re-read the owner's config and pause file before every external mutation.
        current = json.loads(private_file(self.path).read_text())
        flag = "replies_enabled" if replies else "publishing_enabled"
        if current.get(flag) is not True or (self.state / "PAUSED").exists():
            raise Blocked("executor_paused")

    def due(self, job: dict, now: datetime):
        if now < utc(job["publish_at"]):
            raise Blocked("not_due")
        if now >= utc(job["expires_at"]):
            raise Blocked("publication_window_expired")
        if job["kind"] == "STORY" and job.get("native_stickers"):
            raise Blocked("native_story_stickers_require_manual_composer")

    def account(self, lang: str) -> dict:
        account = self.data.get("instagram", {}).get(lang, {})
        if account.get("username") != ACCOUNTS[lang]:
            raise Blocked("account_not_configured")
        if not isinstance(account.get("id"), str) or not account["id"].isdigit():
            raise Blocked("instagram_numeric_id_required")
        return account
