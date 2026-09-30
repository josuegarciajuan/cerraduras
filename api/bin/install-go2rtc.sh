#!/usr/bin/env bash
# bin/install-go2rtc.sh — install and enable go2rtc for the warehouse live view
# (F61/RF-72.1). Idempotent.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BIN=/usr/local/bin/go2rtc
CONF_DIR=/etc/go2rtc
CONF="$CONF_DIR/go2rtc.yaml"
UNIT_SRC="$REPO_ROOT/docs/systemd/cerraduras-go2rtc.service"
UNIT_DST=/etc/systemd/system/cerraduras-go2rtc.service

case "$(uname -m)" in
  x86_64)  GOARCH=amd64 ;;
  aarch64|arm64) GOARCH=arm64 ;;
  *) echo "Arquitectura no soportada: $(uname -m)"; exit 1 ;;
esac

if [ ! -x "$BIN" ]; then
  echo "[go2rtc] descargando binario…"
  curl -fsSL -o "$BIN" "https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_${GOARCH}"
  chmod +x "$BIN"
else
  echo "[go2rtc] ya instalado en $BIN"
fi

mkdir -p "$CONF_DIR"
if [ ! -f "$CONF" ]; then
  LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
  cat > "$CONF" <<EOF
api:
  listen: "0.0.0.0:1984"
rtsp:
  listen: ":8554"
webrtc:
  listen: ":8555"
  candidates:
    - "${LAN_IP}:8555"
log:
  level: "info"
EOF
  echo "[go2rtc] config creada en $CONF"
else
  echo "[go2rtc] config existente en $CONF"
fi

install -m 0644 "$UNIT_SRC" "$UNIT_DST"
systemctl daemon-reload
systemctl enable --now cerraduras-go2rtc
echo "[go2rtc] servicio activo: $(systemctl is-active cerraduras-go2rtc)"
