#!/usr/bin/env bash
# F91/TSK-91.13: instala las dependencias Node del ingreso Zigbee.
set -eu
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT/zigbee-ingress"
npm ci --omit=dev --no-audit --no-fund
