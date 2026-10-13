#!/bin/bash
set -euo pipefail
umask 077

VGW_VERSION="1.8.0"
VGW_SHA256="b34051d33f5a9c457f790896acb7bd7d7e15ad8d92efb70616b924f37e401910"
install -d -m 0700 /etc/servernet-object-gateway
install -d -m 0750 /opt/servernet-object-gateway /var/lib/servernet-object-gateway
id servernet-object >/dev/null 2>&1 || useradd --system --home /var/lib/servernet-object-gateway --shell /usr/sbin/nologin servernet-object

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl nginx python3-venv openssl
curl -fsSL "https://github.com/versity/versitygw/releases/download/v${VGW_VERSION}/versitygw-v${VGW_VERSION}-linux-arm64.tar.gz" -o /tmp/versitygw.tar.gz
echo "${VGW_SHA256}  /tmp/versitygw.tar.gz" | sha256sum -c -
tar -xzf /tmp/versitygw.tar.gz -C /tmp
install -m 0755 "$(find /tmp -type f -name versitygw -print -quit)" /usr/local/bin/versitygw

base64 -d >/tmp/servernet-object-gateway.tar.gz <<'SOURCE_ARCHIVE'
__SOURCE_ARCHIVE_B64__
SOURCE_ARCHIVE
tar -xzf /tmp/servernet-object-gateway.tar.gz -C /opt/servernet-object-gateway
python3 -m venv /opt/servernet-object-gateway/venv
/opt/servernet-object-gateway/venv/bin/pip install --no-cache-dir 'boto3==1.35.99'

for region in fsn1 nbg1 hel1; do
  case "$region" in fsn1) port=9211;; nbg1) port=9212;; hel1) port=9213;; esac
  install -d -o servernet-object -g servernet-object -m 0700 "/var/lib/servernet-object-gateway/iam-${region}"
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
VGW_S3_META_BUCKET=sn-gateway-meta-${region}
VGW_S3_USE_PATH_STYLE=true
EOF
  printf '%s|%s|%s\n' "$region" "$root_access" "$root_secret" >>/tmp/region-roots
done

HMAC_SECRET="__CONTROL_HMAC_SECRET__"
STORAGE_SEED="$(openssl rand -hex 32)"
python3 - /tmp/region-roots /etc/servernet-object-gateway/control.env <<'PY'
import json,sys
rows={}
for line in open(sys.argv[1]):
    region,access,secret=line.strip().split('|')
    port={'fsn1':9211,'nbg1':9212,'hel1':9213}[region]
    rows[region]={'local_endpoint':f'http://127.0.0.1:{port}',
      'public_endpoint':f'https://{region}.s3.servernet.cloud',
      'root_access':access,'root_secret':secret}
open(sys.argv[2],'w').write('SN_OBJECT_API_BIND=127.0.0.1\nSN_OBJECT_API_PORT=9180\nSN_OBJECT_DB=/var/lib/servernet-object-gateway/tenants.sqlite3\nSN_OBJECT_HMAC_SECRET=__CONTROL_HMAC_SECRET__\nSN_OBJECT_SECRET_SEED=__STORAGE_SEED__\nSN_OBJECT_REGIONS_JSON='+json.dumps(rows,separators=(',',':'))+'\n')
PY
sed -i "s/__CONTROL_HMAC_SECRET__/${HMAC_SECRET}/;s/__STORAGE_SEED__/${STORAGE_SEED}/" /etc/servernet-object-gateway/control.env
rm -f /tmp/region-roots
chown -R servernet-object:servernet-object /opt/servernet-object-gateway /var/lib/servernet-object-gateway
chmod 0600 /etc/servernet-object-gateway/*.env

install -m 0644 /opt/servernet-object-gateway/servernet-object@.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-api.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-reconcile.service /etc/systemd/system/
install -m 0644 /opt/servernet-object-gateway/servernet-object-reconcile.timer /etc/systemd/system/

openssl req -x509 -newkey rsa:2048 -nodes -days 3 -subj '/CN=object-api.servernet.cloud' \
  -keyout /etc/servernet-object-gateway/test.key -out /etc/servernet-object-gateway/test.crt
cat >/etc/nginx/sites-available/servernet-object-gateway <<'NGINX'
server { listen 9443 ssl; ssl_certificate /etc/servernet-object-gateway/test.crt; ssl_certificate_key /etc/servernet-object-gateway/test.key; location / { proxy_pass http://127.0.0.1:9180; } }
server { listen 9001 ssl; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; ssl_certificate /etc/servernet-object-gateway/test.crt; ssl_certificate_key /etc/servernet-object-gateway/test.key; location / { proxy_pass http://127.0.0.1:9211; } }
server { listen 9002 ssl; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; ssl_certificate /etc/servernet-object-gateway/test.crt; ssl_certificate_key /etc/servernet-object-gateway/test.key; location / { proxy_pass http://127.0.0.1:9212; } }
server { listen 9003 ssl; client_max_body_size 0; proxy_request_buffering off; proxy_buffering off; proxy_read_timeout 3600s; ssl_certificate /etc/servernet-object-gateway/test.crt; ssl_certificate_key /etc/servernet-object-gateway/test.key; location / { proxy_pass http://127.0.0.1:9213; } }
NGINX
ln -sf /etc/nginx/sites-available/servernet-object-gateway /etc/nginx/sites-enabled/servernet-object-gateway
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl daemon-reload
systemctl enable --now servernet-object@fsn1 servernet-object@nbg1 servernet-object@hel1 servernet-object-api servernet-object-reconcile.timer nginx
touch /var/lib/servernet-object-gateway/cloud-init-ready
