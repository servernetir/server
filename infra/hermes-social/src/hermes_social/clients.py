from __future__ import annotations

import hashlib
import io
import json
import re
from urllib.parse import quote

from .policy import Blocked, https_url, identifier, private_file, secret

DRIVE_SCOPES = ["https://www.googleapis.com/auth/drive.readonly",
                "https://www.googleapis.com/auth/drive.file"]
MAX_BYTES = 25 * 1024 * 1024


class Drive:
    def __init__(self, policy, client=None, credentials=None):
        import httpx
        self.policy = policy
        self.http = client or httpx.Client(timeout=45, follow_redirects=False)
        if credentials is None:
            from google.oauth2 import service_account
            from google.oauth2.credentials import Credentials
            path = private_file(policy.data["google_credentials_file"])
            if json.loads(path.read_text()).get("type") == "service_account":
                # A dedicated service account sees only explicitly shared resources;
                # its resource ACL, plus scoped(), bounds the campaign folder.
                credentials = service_account.Credentials.from_service_account_file(
                    str(path), scopes=["https://www.googleapis.com/auth/drive"])
            else:
                credentials = Credentials.from_authorized_user_file(str(path), scopes=DRIVE_SCOPES)
        self.credentials = credentials

    def headers(self):
        from google.auth.transport.requests import Request
        if not self.credentials.valid:
            try:
                self.credentials.refresh(Request())
            except Exception:
                raise Blocked("google_authorization_required") from None
        return {"Authorization": "Bearer " + self.credentials.token}

    def get(self, path, params):
        try:
            response = self.http.get("https://www.googleapis.com/drive/v3/" + path,
                                     params=params, headers=self.headers())
            if response.status_code != 200:
                raise Blocked("drive_read_http_" + str(response.status_code))
            return response.json()
        except Blocked:
            raise
        except Exception:
            raise Blocked("drive_read_failed") from None

    def metadata(self, file_id):
        return self.get("files/" + identifier(file_id),
                        {"fields": "id,name,mimeType,parents,trashed,size", "supportsAllDrives": "true"})

    def scoped(self, file_id):
        # Shortcuts never inherit target authority, and each parent is checked.
        current = file_id
        seen = set()
        first = None
        for _ in range(20):
            if current in seen:
                break
            seen.add(current)
            data = self.metadata(current)
            first = first or data
            if data.get("trashed") or data.get("mimeType") == "application/vnd.google-apps.shortcut":
                raise Blocked("drive_trashed_or_shortcut")
            if current == self.policy.root:
                if data.get("mimeType") != "application/vnd.google-apps.folder":
                    raise Blocked("drive_root_not_folder")
                return first
            parents = data.get("parents", [])
            if len(parents) != 1:
                break
            current = parents[0]
        raise Blocked("drive_file_outside_campaign_folder")

    def list_files(self, folder_id, page_token=None):
        folder_id = folder_id or self.policy.root
        if self.scoped(folder_id).get("mimeType") != "application/vnd.google-apps.folder":
            raise Blocked("not_a_folder")
        params = {"q": "'" + identifier(folder_id) + "' in parents and trashed=false",
                  "fields": "nextPageToken,files(id,name,mimeType,size)", "pageSize": 100,
                  "supportsAllDrives": "true", "includeItemsFromAllDrives": "true"}
        if page_token:
            if len(page_token) > 4096:
                raise Blocked("invalid_page_token")
            params["pageToken"] = page_token
        return self.get("files", params)

    def download(self, asset):
        metadata = self.scoped(asset["drive_id"])
        if metadata.get("mimeType") != asset["mime_type"]:
            raise Blocked("drive_mime_changed")
        if int(metadata.get("size", MAX_BYTES + 1)) > MAX_BYTES:
            raise Blocked("asset_too_large")
        try:
            content = bytearray()
            with self.http.stream("GET", "https://www.googleapis.com/drive/v3/files/" + asset["drive_id"],
                                  params={"alt": "media", "supportsAllDrives": "true"},
                                  headers=self.headers()) as response:
                if response.status_code != 200:
                    raise Blocked("drive_download_http_" + str(response.status_code))
                for chunk in response.iter_bytes():
                    content.extend(chunk)
                    if len(content) > MAX_BYTES:
                        raise Blocked("asset_too_large")
            if hashlib.sha256(content).hexdigest() != asset["sha256"]:
                raise Blocked("asset_changed_since_owner_approval")
            return bytes(content)
        except Blocked:
            raise
        except Exception:
            raise Blocked("drive_download_failed") from None

    def sync_receipts(self, snapshot):
        target = self.policy.data.get("drive_receipt_file_id")
        if not target:
            raise Blocked("dedicated_drive_receipt_file_required")
        if target in {a["drive_id"] for j in self.policy.jobs.values() for a in j["assets"]}:
            raise Blocked("cannot_overwrite_campaign_asset")
        metadata = self.scoped(target)
        if metadata.get("mimeType") != "application/json":
            raise Blocked("receipt_target_must_be_json")
        try:
            response = self.http.patch("https://www.googleapis.com/upload/drive/v3/files/" + identifier(target),
                                       params={"uploadType": "media", "supportsAllDrives": "true"},
                                       headers={**self.headers(), "Content-Type": "application/json"},
                                       content=json.dumps(snapshot, ensure_ascii=False).encode())
            if response.status_code != 200 or response.json().get("id") != target:
                raise Blocked("drive_receipt_write_not_verified")
            readback = self.http.get("https://www.googleapis.com/drive/v3/files/" + target,
                                     params={"alt": "media", "supportsAllDrives": "true"}, headers=self.headers())
            if readback.status_code != 200 or readback.json() != snapshot:
                raise Blocked("drive_receipt_readback_mismatch")
            return {"file_id": target, "synced": True}
        except Blocked:
            raise
        except Exception:
            # A repeat is a replacement of this same designated file, never a create.
            raise Blocked("drive_receipt_sync_unknown_safe_to_replace_same_file") from None


def publish_bytes(content: bytes, asset: dict, kind: str):
    if asset["mime_type"] == "video/mp4":
        if content[4:8] != b"ftyp":
            raise Blocked("invalid_mp4")
        return content, "video/mp4", ".mp4"
    from PIL import Image, ImageOps
    Image.MAX_IMAGE_PIXELS = 30_000_000
    try:
        with Image.open(io.BytesIO(content)) as source:
            if source.format not in {"PNG", "JPEG"} or getattr(source, "n_frames", 1) != 1:
                raise Blocked("unsupported_image")
            source.load()
            image = ImageOps.exif_transpose(source).convert("RGBA")
            ratio = image.width / image.height
            if not 320 <= image.width <= 1440:
                raise Blocked("image_width_outside_executor_limits")
            if kind == "STORY":
                if abs(ratio - 9 / 16) > 0.02:
                    raise Blocked("story_requires_9_16")
            elif not 0.8 <= ratio <= 1.91:
                raise Blocked("feed_image_aspect_ratio_invalid")
            background = Image.new("RGB", image.size, "white")
            background.paste(image, mask=image.getchannel("A"))
            output = io.BytesIO()
            background.save(output, format="JPEG", quality=94, optimize=True)
            value = output.getvalue()
            if len(value) > 8 * 1024 * 1024:
                raise Blocked("jpeg_too_large")
            return value, "image/jpeg", ".jpg"
    except Blocked:
        raise
    except Exception:
        raise Blocked("image_decode_failed") from None


class MediaStore:
    def __init__(self, policy):
        import boto3
        from botocore.config import Config
        conf = policy.data["media_store"]
        credentials = json.loads(private_file(conf["credentials_file"]).read_text())
        self.bucket = conf["bucket"]
        self.client = boto3.client("s3", endpoint_url=https_url(conf["endpoint_url"]),
                                   region_name=conf.get("region", "us-east-1"),
                                   aws_access_key_id=credentials["access_key_id"],
                                   aws_secret_access_key=credentials["secret_access_key"],
                                   config=Config(signature_version="s3v4", connect_timeout=10, read_timeout=45,
                                                 retries={"total_max_attempts": 2},
                                                 s3={"addressing_style": conf.get("addressing_style", "path")}))

    def stage(self, content: bytes, asset: dict, kind: str):
        content, mime, suffix = publish_bytes(content, asset, kind)
        key = "hermes-social/" + hashlib.sha256(content).hexdigest() + suffix
        try:
            self.client.put_object(Bucket=self.bucket, Key=key, Body=content,
                                   ContentType=mime, CacheControl="private, max-age=3600")
            # Only this approved, hashed object is temporarily readable by Meta.
            url = self.client.generate_presigned_url("get_object", Params={"Bucket": self.bucket, "Key": key},
                                                     ExpiresIn=3600)
            return https_url(url)
        except Exception:
            raise Blocked("media_store_unavailable") from None


class Instagram:
    def __init__(self, policy, lang, client=None):
        import httpx
        self.policy = policy
        self.account = policy.account(lang)
        version = policy.data.get("graph_version", "")
        if not re.fullmatch(r"v[0-9]+\.0", version):
            raise Blocked("explicit_supported_graph_version_required")
        self.base = "https://graph.instagram.com/" + version
        self.token = secret(self.account["token_file"])
        self.http = client or httpx.Client(timeout=45, follow_redirects=False)

    def call(self, method, path, **values):
        try:
            kwargs = {"headers": {"Authorization": "Bearer " + self.token}}
            kwargs["params" if method == "GET" else "data"] = values
            response = self.http.request(method, self.base + "/" + path, **kwargs)
            if response.status_code < 200 or response.status_code >= 300:
                raise Blocked("instagram_http_" + str(response.status_code))
            result = response.json()
            if not isinstance(result, dict) or "error" in result:
                raise Blocked("instagram_invalid_response")
            return result
        except Blocked:
            raise
        except Exception:
            # Never return raw exceptions/requests: they may contain signed URLs or tokens.
            raise Blocked("instagram_response_unknown") from None

    def verify_account(self):
        result = self.call("GET", self.account["id"], fields="id,username")
        if str(result.get("id")) != self.account["id"] or result.get("username") != self.account["username"]:
            raise Blocked("instagram_live_identity_mismatch")
        return {"username": result["username"], "identity_verified": True}

    def create(self, job, urls):
        account_id = self.account["id"]
        fields = {"caption": job.get("caption", "")}
        if job["kind"] == "CAROUSEL":
            children = []
            for asset, url in zip(job["assets"], urls, strict=True):
                self.policy.enabled()
                response = self.call("POST", account_id + "/media", image_url=url,
                                     is_carousel_item="true", alt_text=asset.get("alt", ""))
                children.append(self.numeric(response.get("id")))
            fields.update(media_type="CAROUSEL", children=",".join(children))
        elif job["kind"] == "REEL":
            fields.update(media_type="REELS", video_url=urls[0])
        elif job["kind"] == "STORY":
            fields = {"media_type": "STORIES", "image_url": urls[0]}
        else:
            fields.update(image_url=urls[0], alt_text=job["assets"][0].get("alt", ""))
        self.policy.enabled()
        return self.numeric(self.call("POST", account_id + "/media", **fields).get("id"))

    @staticmethod
    def numeric(value):
        if not isinstance(value, str) or not value.isdigit():
            raise Blocked("instagram_numeric_response_id_required")
        return value

    def container_status(self, container):
        return self.call("GET", self.numeric(container), fields="status_code").get("status_code")

    def publish(self, container):
        return self.numeric(self.call("POST", self.account["id"] + "/media_publish",
                                      creation_id=self.numeric(container)).get("id"))

    def verify_media(self, media_id, kind):
        # Verify the result against the account's own media edge, not an arbitrary public ID.
        edge = "stories" if kind == "STORY" else "media"
        listing = self.call("GET", self.account["id"] + "/" + edge, fields="id", limit=50)
        if not any(str(x.get("id")) == media_id for x in listing.get("data", [])):
            raise Blocked("published_media_not_yet_visible_on_owner_edge")
        fields = "id,timestamp" if kind == "STORY" else "id,timestamp,permalink,caption"
        result = self.call("GET", self.numeric(media_id), fields=fields)
        if str(result.get("id")) != media_id or not result.get("timestamp"):
            raise Blocked("published_media_not_verified")
        if kind != "STORY":
            https_url(result.get("permalink", ""))
            from urllib.parse import urlsplit
            if urlsplit(result["permalink"]).hostname not in {"www.instagram.com", "instagram.com"}:
                raise Blocked("unexpected_permalink_host")
        return {key: result[key] for key in ("id", "timestamp", "permalink", "caption") if key in result}

    def comments(self, media_id):
        items, seen_ids, seen_cursors = [], set(), set()
        after = None
        for _ in range(20):
            params = {"fields": "id,text,username,timestamp", "limit": 100}
            if after:
                params["after"] = after
            page = self.call("GET", self.numeric(media_id) + "/comments", **params)
            for item in page.get("data", []):
                comment_id = self.numeric(item.get("id"))
                if comment_id not in seen_ids:
                    seen_ids.add(comment_id)
                    items.append(item)
            paging = page.get("paging", {})
            if not paging.get("next"):
                return items
            after = paging.get("cursors", {}).get("after")
            if not isinstance(after, str) or not after or after in seen_cursors:
                raise Blocked("comment_pagination_invalid")
            seen_cursors.add(after)
        # Never silently discard the remaining customer comments or trust a raw next URL.
        raise Blocked("comment_pagination_limit_requires_operator_review")

    def reply(self, comment_id, text):
        return self.numeric(self.call("POST", self.numeric(comment_id) + "/replies", message=text).get("id"))

    def verify_reply(self, reply_id, text):
        result = self.call("GET", self.numeric(reply_id), fields="id,text")
        if result.get("id") != reply_id or result.get("text") != text:
            raise Blocked("reply_not_verified")


