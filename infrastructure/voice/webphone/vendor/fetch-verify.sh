#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
# Pinned official browser distribution; no npm lifecycle scripts or runtime CDN.
task_download=$(mktemp)
trap 'rm -f -- "$task_download"' EXIT
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
  --connect-timeout 15 --max-time 120 \
  https://jssip.net/download/releases/jssip-3.10.0.min.js --output "$task_download"
printf '%s  %s\n' '06d3661907f43fd26ced77149bcbbd6d416a728dae46213a1c10ccee8a89698b' "$task_download" | sha256sum --check --status
install -m 0644 -- "$task_download" jssip-3.10.0.min.js
sha256sum --check SHA256SUMS
