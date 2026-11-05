"""Operator-side verifier for the fixed, non-secret Windows readiness marker."""
from datetime import datetime, timedelta, timezone
import re


def verify_marker(marker, image_key, hostname, licensed, now=None, operation_created_at=None):
    if licensed is not True:
        raise ValueError("Windows license acceptance is required")
    match = re.fullmatch(r"windows-(2019|2022|2025)(?:[.-][a-zA-Z0-9.-]+)?", image_key)
    if not match:
        raise ValueError("Unsupported Windows image key")
    expected_build = {"2019": "17763", "2022": "20348", "2025": "26100"}[match[1]]
    if not isinstance(marker, dict) or type(marker.get("schema_version")) is not int or marker["schema_version"] != 1:
        raise ValueError("Invalid readiness schema")
    if marker.get("image_key") != image_key or str(marker.get("os_build")) != expected_build:
        raise ValueError("Image identity/build differs from accepted template")
    if str(marker.get("hostname", "")).casefold() != hostname.casefold():
        raise ValueError("Hostname is not this reserved VM")
    if marker.get("initialized") is not True or marker.get("credential_setup") is not True:
        raise ValueError("Actual initialization and credential setup are required")
    try:
        timestamp = marker["written_at"]
        if not isinstance(timestamp, str):
            raise ValueError("Timestamp must be an ISO8601 string")
        completed = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
    except (KeyError, TypeError, ValueError) as exc:
        raise ValueError("Initialization timestamp is required") from exc
    now = now or datetime.now(timezone.utc)
    if completed.tzinfo is None or completed < now - timedelta(hours=1) or completed > now + timedelta(seconds=30):
        raise ValueError("Readiness marker is stale or future-dated")
    if operation_created_at is not None and completed < operation_created_at:
        raise ValueError("Readiness marker predates this create/rebuild operation")
    forbidden = {"password", "root_password", "token", "token_secret", "credentials"}
    if forbidden.intersection(marker):
        raise ValueError("Readiness markers must not contain credentials")
    return True
