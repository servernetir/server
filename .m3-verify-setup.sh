#!/bin/bash
set -e
f="/root/m3-verify/website/database/migrations/2026_08_04_000200_add_domain_id_to_invoices.php"
grep -n "after" "$f"
sed -i 's/->after([^)]*)//' "$f"
echo AFTER_PATCH:
grep -n "nullable()" "$f" | head -3

cat > /root/m3-verify/compose.yml <<'EOF'
services:
  app:
    image: php:8.4-cli
    working_dir: /site
    volumes: ["/root/m3-verify/website:/site"]
    depends_on: ["db"]
    command: ["sleep", "infinity"]
  db:
    image: mariadb:11.8
    environment:
      MARIADB_ALLOW_EMPTY_ROOT_PASSWORD: "yes"
      MARIADB_DATABASE: servernet_stress
      MARIADB_USER: stress
      MARIADB_PASSWORD: stress
EOF
echo COMPOSE_WRITTEN
