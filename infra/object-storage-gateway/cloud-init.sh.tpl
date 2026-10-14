#!/bin/bash
set -euo pipefail
umask 077

VGW_VERSION="1.8.0"
VGW_SHA256="b34051d33f5a9c457f790896acb7bd7d7e15ad8d92efb70616b924f37e401910"
install -d -m 0700 /etc/servernet-object-gateway
install -d -m 0750 /opt/servernet-object-gateway /var/lib/servernet-object-gateway
id servernet-object >/dev/null 2>&1 || useradd --system --home /var/lib/servernet-object-gateway --shell /usr/sbin/nologin servernet-object

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl nginx python3-venv openssl certbot python3-certbot-nginx dnsutils
curl -fsSL "https://github.com/versity/versitygw/releases/download/v${VGW_VERSION}/versitygw_v${VGW_VERSION}_Linux_arm64.tar.gz" -o /tmp/versitygw.tar.gz
echo "${VGW_SHA256}  /tmp/versitygw.tar.gz" | sha256sum -c -
tar -xzf /tmp/versitygw.tar.gz -C /tmp
install -m 0755 "$(find /tmp -type f -name versitygw -print -quit)" /usr/local/bin/versitygw

base64 -d >/tmp/servernet-object-gateway.tar.gz <<'SOURCE_ARCHIVE'
__SOURCE_ARCHIVE_B64__
SOURCE_ARCHIVE
tar -xzf /tmp/servernet-object-gateway.tar.gz -C /opt/servernet-object-gateway
python3 -m venv /opt/servernet-object-gateway/venv
/opt/servernet-object-gateway/venv/bin/pip install --no-cache-dir 'boto3==1.35.99'

export SN_BOOTSTRAP_S3_ACCESS="__HETZNER_S3_ACCESS__"
export SN_BOOTSTRAP_S3_SECRET="__HETZNER_S3_SECRET__"
/opt/servernet-object-gateway/venv/bin/python - /tmp/meta-buckets <<'PY'
import hashlib
import os
import sys

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError

access = os.environ["SN_BOOTSTRAP_S3_ACCESS"]
secret = os.environ["SN_BOOTSTRAP_S3_SECRET"]
suffix = hashlib.sha256(access.encode()).hexdigest()[:12]
rows = []
for region in ("fsn1", "nbg1", "hel1"):
    bucket = f"sn-vgw-meta-{region}-{suffix}"
    client = boto3.client(
        "s3",
        endpoint_url=f"https://{region}.your-objectstorage.com",
        aws_access_key_id=access,
        aws_secret_access_key=secret,
        region_name=region,
        config=Config(signature_version="s3v4", s3={"addressing_style": "path"}),
    )
    try:
        client.create_bucket(Bucket=bucket, CreateBucketConfiguration={"LocationConstraint": region})
    except ClientError as exc:
        code = str(exc.response.get("Error", {}).get("Code", ""))
        if code not in {"BucketAlreadyExists", "BucketAlreadyOwnedByYou"}:
            raise
    rows.append(f"{region}|{bucket}")
open(sys.argv[1], "w").write("\n".join(rows) + "\n")
PY

for region in fsn1 nbg1 hel1; do
  case "$region" in fsn1) port=9211;; nbg1) port=9212;; hel1) port=9213;; esac
  install -d -o servernet-object -g servernet-object -m 0700 "/var/lib/servernet-object-gateway/iam-${region}"
  meta_bucket="$(awk -F'|' -v region="$region" '$1 == region { print $2 }' /tmp/meta-buckets)"
  [ -n "$meta_bucket" ]
  root_access="$(openssl rand -hex 16)"
  root_secret="$(openssl rand -hex 32)"
  cat >"/etc/servernet-object-gateway/${region}.env" <<EOF
VGW_PORT=${port}
VGW_IAM_DIR=/var/lib/servernet-object-gateway/iam-${region}
ROOT_ACCESS_KEY_ID=${root_access}
ROOT_SECRET_ACCESS_KEY=${root_secret}
VGW_S3_ACCESS_KEY=__HETZNER_S3_ACCESS__
VGW_S3_SECRET_KEY=__HETZNER_S3_SECRET__
VGW_S3_ENDPOINT=https://${region}.your-objectstorage.com
VGW_S3_REGION=${region}
VGW_S3_META_BUCKET=${meta_bucket}
VGW_S3_USE_PATH_STYLE=true
VGW_S3_DISABLE_DATA_INTEGRITY_CHECK=true
AWS_REQUEST_CHECKSUM_CALCULATION=WHEN_REQUIRED
EOF
  printf '%s|%s|%s|%s\n' "$region" "$root_access" "$root_secret" "$meta_bucket" >>/tmp/region-roots
done

HMAC_SECRET="__CONTROL_HMAC_SECRET__"
STORAGE_SEED="$(openssl rand -hex 32)"
python3 - /tmp/region-roots /etc/servernet-object-gateway/control.env <<'PY'
import json,os,sys
rows={}
for line in open(sys.argv[1]):
    region,access,secret,meta_bucket=line.strip().split('|')
    port={'fsn1':9211,'nbg1':9212,'hel1':9213}[region]
    rows[region]={'local_endpoint':f'http://127.0.0.1:{port}',
      'public_endpoint':f'https://{region}.s3.servernet.cloud',
      'root_access':access,'root_secret':secret,
      'upstream_endpoint':f'https://{region}.your-objectstorage.com',
      'upstream_access':os.environ['SN_BOOTSTRAP_S3_ACCESS'],
      'upstream_secret':os.environ['SN_BOOTSTRAP_S3_SECRET'],
      'meta_bucket':meta_bucket}
open(sys.argv[2],'w').write('SN_OBJECT_API_BIND=127.0.0.1\nSN_OBJECT_API_PORT=9180\nSN_OBJECT_DB=/var/lib/servernet-object-gateway/tenants.sqlite3\nSN_OBJECT_HMAC_SECRET=__CONTROL_HMAC_SECRET__\nSN_OBJECT_CREDENTIAL_SEED=__STORAGE_SEED__\nSN_OBJECT_REGIONS_JSON='+json.dumps(rows,separators=(',',':'))+'\n')
PY
sed -i "s/__CONTROL_HMAC_SECRET__/${HMAC_SECRET}/;s/__STORAGE_SEED__/${STORAGE_SEED}/" /etc/servernet-object-gateway/control.env
rm -f /tmp/region-roots /tmp/meta-buckets
unset SN_BOOTSTRAP_S3_ACCESS SN_BOOTSTRAP_S3_SECRET
chown -R servernet-object:servernet-object /opt/servernet-object-gateway /var/lib/servernet-object-gateway
chmod 0600 /etc/servernet-object-gateway/*.env

install -m 0644 /opt/servernet-object-gateway/servernet-object@.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-api.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-reconcile.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-reconcile.timer /etc/systemd/system/

cat >/etc/nginx/sites-available/servernet-object-gateway <<'NGINX'
server { listen 80; server_name object-api.servernet.cloud; location / { proxy_pass http://127.0.0.1:9180; } }
server { listen 80; server_name fsn1.s3.servernet.cloud; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; location / { proxy_pass http://127.0.0.1:9211; } }
server { listen 80; server_name nbg1.s3.servernet.cloud; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; location / { proxy_pass http://127.0.0.1:9212; } }
server { listen 80; server_name hel1.s3.servernet.cloud; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; location / { proxy_pass http://127.0.0.1:9213; } }
NGINX
ln -sf /etc/nginx/sites-available/servernet-object-gateway /etc/nginx/sites-enabled/servernet-object-gateway
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl daemon-reload
systemctl enable --now servernet-object@fsn1 servernet-object@nbg1 servernet-object@hel1 servernet-object-api servernet-object-reconcile.timer nginx
PUBLIC_IP="$(curl -fsS --max-time 10 http://169.254.169.254/hetzner/v1/metadata/public-ipv4)"
for attempt in $(seq 1 60); do
  ready=1
  for host in object-api.servernet.cloud fsn1.s3.servernet.cloud nbg1.s3.servernet.cloud hel1.s3.servernet.cloud; do
    getent ahostsv4 "$host" | awk '{print $1}' | grep -Fxq "$PUBLIC_IP" || ready=0
  done
  [ "$ready" = 1 ] && break
  sleep 10
done
[ "$ready" = 1 ]
certbot --nginx --non-interactive --agree-tos --redirect --email support@servernet.cloud \
  -d object-api.servernet.cloud -d fsn1.s3.servernet.cloud -d nbg1.s3.servernet.cloud -d hel1.s3.servernet.cloud
touch /var/lib/servernet-object-gateway/cloud-init-ready
