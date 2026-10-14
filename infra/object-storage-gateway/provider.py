import hashlib
import json
import os
import urllib.parse
import xml.etree.ElementTree as ET
from xml.sax.saxutils import escape

import boto3
from botocore.auth import SigV4Auth
from botocore.awsrequest import AWSRequest
from botocore.config import Config
from botocore.credentials import Credentials
from botocore.httpsession import URLLib3Session


def regions():
    data = json.loads(os.environ.get("SN_OBJECT_REGIONS_JSON", "{}"))
    required = {"fsn1", "nbg1", "hel1"}
    if set(data) != required:
        raise RuntimeError("all three object regions must be configured")
    for value in data.values():
        for key in ("local_endpoint", "public_endpoint", "root_access", "root_secret",
                    "upstream_endpoint", "upstream_access", "upstream_secret", "meta_bucket"):
            if not value.get(key):
                raise RuntimeError("incomplete region configuration")
    return data


def _signed(region, method, path, body=b"", headers=None, ok=(200, 201, 204)):
    cfg = regions()[region]
    url = cfg["local_endpoint"].rstrip("/") + path
    request = AWSRequest(method=method, url=url, data=body, headers=headers or {})
    request.headers["X-Amz-Content-Sha256"] = hashlib.sha256(body).hexdigest()
    SigV4Auth(Credentials(cfg["root_access"], cfg["root_secret"]), "s3", "us-east-1").add_auth(request)
    response = URLLib3Session().send(request.prepare())
    if response.status_code not in ok:
        text = response.content.decode("utf-8", "replace")[:500]
        raise RuntimeError(f"gateway admin HTTP {response.status_code}: {text}")
    return response


def create_or_update_user(region, access, secret):
    body = ("<Account><Access>%s</Access><Secret>%s</Secret><Role>user</Role></Account>"
            % (escape(access), escape(secret))).encode()
    try:
        _signed(region, "PATCH", "/create-user", body, {"Content-Type": "application/xml"})
    except RuntimeError as exc:
        if "XAdminUserExists" not in str(exc):
            raise
        update_user(region, access, secret)


def update_user(region, access, secret):
    body = ("<MutableProps><Secret>%s</Secret></MutableProps>" % escape(secret)).encode()
    _signed(region, "PATCH", "/update-user?access=" + urllib.parse.quote(access), body,
            {"Content-Type": "application/xml"})


def delete_user(region, access):
    try:
        _signed(region, "PATCH", "/delete-user?access=" + urllib.parse.quote(access), ok=(200, 204))
    except RuntimeError as exc:
        if "XAdminUserNotFound" not in str(exc):
            raise


def create_or_assign_bucket(region, bucket, access):
    created = False
    try:
        upstream_client(region).create_bucket(
            Bucket=bucket,
            CreateBucketConfiguration={"LocationConstraint": region},
        )
        created = True
    except Exception as exc:
        code = getattr(exc, "response", {}).get("Error", {}).get("Code", "")
        if code not in {"BucketAlreadyExists", "BucketAlreadyOwnedByYou"}:
            raise
        try:
            owner = bucket_owner(region, bucket)
        except RuntimeError:
            owner = None
        if owner not in {None, access}:
            raise RuntimeError("existing bucket has a different owner")
        if owner == access:
            return

    path = "/change-bucket-owner?bucket=%s&owner=%s" % (
        urllib.parse.quote(bucket), urllib.parse.quote(access))
    try:
        _signed(region, "PATCH", path)
    except Exception:
        if created:
            try:
                upstream_client(region).delete_bucket(Bucket=bucket)
            except Exception:
                pass
        raise


def bucket_owner(region, bucket):
    response = _signed(region, "PATCH", "/list-buckets")
    root = ET.fromstring(response.content)
    for item in root.iter():
        if item.tag.rsplit("}", 1)[-1] != "Bucket":
            continue
        values = {child.tag.rsplit("}", 1)[-1]: child.text for child in item}
        if values.get("Name") == bucket:
            return values.get("Owner")
    return None


def root_client(region):
    cfg = regions()[region]
    return boto3.client("s3", endpoint_url=cfg["local_endpoint"],
        aws_access_key_id=cfg["root_access"], aws_secret_access_key=cfg["root_secret"],
        region_name="us-east-1", config=Config(signature_version="s3v4", s3={"addressing_style": "path"}))


def upstream_client(region):
    cfg = regions()[region]
    return boto3.client("s3", endpoint_url=cfg["upstream_endpoint"],
        aws_access_key_id=cfg["upstream_access"], aws_secret_access_key=cfg["upstream_secret"],
        region_name=region, config=Config(signature_version="s3v4", s3={"addressing_style": "path"}))


def used_bytes(region, bucket):
    total = 0
    for page in root_client(region).get_paginator("list_objects_v2").paginate(Bucket=bucket):
        total += sum(int(item.get("Size", 0)) for item in page.get("Contents", []))
    return total


def purge_bucket(region, bucket):
    client = root_client(region)
    while True:
        page = client.list_objects_v2(Bucket=bucket, MaxKeys=1000)
        objects = [{"Key": item["Key"]} for item in page.get("Contents", [])]
        if objects:
            client.delete_objects(Bucket=bucket, Delete={"Objects": objects, "Quiet": True})
        if not page.get("IsTruncated"):
            break
    client.delete_bucket(Bucket=bucket)


def health(region):
    _signed(region, "PATCH", "/list-users")
    return True
