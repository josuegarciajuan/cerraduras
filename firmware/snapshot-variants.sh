#!/usr/bin/env bash
# snapshot-variants.sh — guarda los binarios exactos de ESP-cerradura y ESP-imán
# antes de sustituir el firmware por un sketch de diagnóstico (p. ej. el sondeo
# RFID/NFC del R35D-B).
#
# Por cada variante:
#   - compila el sketch con el perfil OTA (esp32s3usbotg);
#   - verifica que el binario declara la variante y la versión esperadas;
#   - copia el .ino.bin y registra versión, SHA-256, tamaño, perfil y commit.
#
# Opcionalmente (--port) lee la flash completa del equipo y guarda también su
# SHA-256. El respaldo completo es la única forma de restaurar el estado exacto
# (incluida NVS/WiFi); los binarios de variante permiten volver a CERRADURA o
# IMAN sin borrar la NVS.
#
# Uso:
#   ./firmware/snapshot-variants.sh
#   ./firmware/snapshot-variants.sh --out-dir=/root/onx-backups/r35d
#   ./firmware/snapshot-variants.sh --port=/dev/ttyACM0
#
# Variables opcionales:
#   ARDUINO_CLI                ruta a arduino-cli (auto: PATH o /tmp/cerraduras-tools)
#   ARDUINO_DIRECTORIES_DATA   p. ej. /home/oficina/.arduino15
#   ARDUINO_DIRECTORIES_USER   p. ej. /home/oficina/Arduino
#   ESPTOOL                    ruta a esptool.py / esptool
#   ESPTOOL_BAUD               baudios del respaldo (def. 230400; sin stub)
#   ARDUINO_PROFILE            perfil de sketch.yaml (def. esp32s3usbotg)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${SNAPSHOT_DIR:-/root/onx-backups/esp-variants-$STAMP}"
ARDUINO_CLI="${ARDUINO_CLI:-}"
ESPTOOL_BIN="${ESPTOOL:-}"
ESPTOOL_BAUD="${ESPTOOL_BAUD:-230400}"
PROFILE="${ARDUINO_PROFILE:-esp32s3usbotg}"
PORT=""

usage() {
  cat <<'EOF'
Uso: snapshot-variants.sh [opciones]

  --out-dir=DIR        directorio de salida (def. /root/onx-backups/esp-variants-<ts>)
  --port=...           puerto serie; si se indica, respalda la flash completa
  --arduino-cli=...    ruta a arduino-cli
  --esptool=...        ruta a esptool.py
  --baud=...           baudios del respaldo de flash (def. 230400)
  --profile=...        perfil de compilación (def. esp32s3usbotg)
  -h, --help           esta ayuda
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --out-dir=*) OUT_DIR="${1#*=}" ;;
    --port=*) PORT="${1#*=}" ;;
    --arduino-cli=*) ARDUINO_CLI="${1#*=}" ;;
    --esptool=*) ESPTOOL_BIN="${1#*=}" ;;
    --baud=*) ESPTOOL_BAUD="${1#*=}" ;;
    --profile=*) PROFILE="${1#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[snapshot] argumento desconocido: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [ -z "$ARDUINO_CLI" ]; then
  if command -v arduino-cli >/dev/null 2>&1; then
    ARDUINO_CLI="$(command -v arduino-cli)"
  elif [ -x /tmp/cerraduras-tools/arduino-cli ]; then
    ARDUINO_CLI="/tmp/cerraduras-tools/arduino-cli"
  else
    echo "[snapshot] arduino-cli no encontrado (usa --arduino-cli=...)" >&2
    exit 1
  fi
fi

COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
mkdir -p "$OUT_DIR"
MANIFEST="$OUT_DIR/manifest.tsv"
printf 'variant\tversion\tsha256\tbytes\tprofile\tcommit\tbuilt_at\n' > "$MANIFEST"

# variante:sketch:macro de versión
VARIANTS="CERRADURA:esp-cerradura:OTA_FW_VERSION IMAN:esp-iman:IMAN_VERSION"

for ENTRY in $VARIANTS; do
  VARIANT="${ENTRY%%:*}"
  REST="${ENTRY#*:}"
  SKETCH="${REST%%:*}"
  MACRO="${REST##*:}"
  SKETCH_DIR="$ROOT/firmware/$SKETCH"
  BUILD_DIR="$OUT_DIR/build-$SKETCH"

  [ -f "$SKETCH_DIR/sketch.yaml" ] || { echo "[snapshot] falta $SKETCH_DIR/sketch.yaml" >&2; exit 1; }

  VERSION="$(sed -n "s/^#define $MACRO[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$SKETCH_DIR/$SKETCH.ino" | head -1)"
  [ -n "$VERSION" ] || { echo "[snapshot] no se pudo leer $MACRO en $SKETCH" >&2; exit 1; }

  echo "[snapshot] compilando $VARIANT ($SKETCH $VERSION) perfil=$PROFILE"
  "$ARDUINO_CLI" compile --profile "$PROFILE" --build-path "$BUILD_DIR" "$SKETCH_DIR"

  BIN_SRC="$BUILD_DIR/$SKETCH.ino.bin"
  [ -f "$BIN_SRC" ] || { echo "[snapshot] no se generó $BIN_SRC" >&2; exit 1; }

  if ! grep -aq "firmware_variant\":\"$VARIANT\"" "$BIN_SRC"; then
    echo "[snapshot] $BIN_SRC no declara firmware_variant=$VARIANT" >&2
    exit 1
  fi
  if ! grep -aq "firmware_version\":\"$VERSION\"" "$BIN_SRC"; then
    echo "[snapshot] $BIN_SRC no declara firmware_version=$VERSION" >&2
    exit 1
  fi

  BIN_DST="$OUT_DIR/$SKETCH-$VERSION.bin"
  cp -f "$BIN_SRC" "$BIN_DST"
  SHA="$(sha256sum "$BIN_DST" | awk '{print $1}')"
  BYTES="$(stat -c %s "$BIN_DST")"
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$VARIANT" "$VERSION" "$SHA" "$BYTES" "$PROFILE" "$COMMIT" "$STAMP" >> "$MANIFEST"
  echo "[snapshot] $VARIANT → $BIN_DST"
  echo "[snapshot]   sha256=$SHA"
done

if [ -n "$PORT" ]; then
  if [ -z "$ESPTOOL_BIN" ]; then
    if command -v esptool.py >/dev/null 2>&1; then
      ESPTOOL_BIN="$(command -v esptool.py)"
    elif command -v esptool >/dev/null 2>&1; then
      ESPTOOL_BIN="$(command -v esptool)"
    else
      echo "[snapshot] esptool no encontrado (usa --esptool=...)" >&2
      exit 1
    fi
  fi

  echo "[snapshot] detectando tamaño de flash en $PORT"
  FLASH_ID="$("$ESPTOOL_BIN" --port "$PORT" flash_id 2>&1)"
  echo "$FLASH_ID" | grep -q "Detected flash size:" \
    || { echo "[snapshot] no se pudo detectar la flash" >&2; echo "$FLASH_ID" >&2; exit 1; }
  SIZE_MB="$(echo "$FLASH_ID" | sed -n 's/.*Detected flash size: \([0-9]*\)MB.*/\1/p' | head -1)"
  [ -n "$SIZE_MB" ] || { echo "[snapshot] tamaño de flash no reconocido" >&2; exit 1; }
  SIZE_HEX="$(printf '0x%X' $((SIZE_MB * 1024 * 1024)))"
  MAC="$(echo "$FLASH_ID" | sed -n 's/^MAC: //p' | head -1)"

  FLASH_BIN="$OUT_DIR/flash-${MAC:-esp32s3}-${SIZE_MB}MB-$STAMP.bin"
  echo "[snapshot] respaldando flash $SIZE_MB MB ($SIZE_HEX) → $FLASH_BIN"
  # Sin stub: en esta base el cambio de baudios del stub corrompe el enlace.
  "$ESPTOOL_BIN" --no-stub --port "$PORT" --baud "$ESPTOOL_BAUD" \
    read_flash 0x0 "$SIZE_HEX" "$FLASH_BIN"
  FLASH_SHA="$(sha256sum "$FLASH_BIN" | awk '{print $1}')"
  printf 'FLASH\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$SIZE_MB" "$FLASH_SHA" "$(stat -c %s "$FLASH_BIN")" "esptool-no-stub" "$COMMIT" "$STAMP" \
    >> "$MANIFEST"
  echo "[snapshot] flash sha256=$FLASH_SHA"
fi

echo
echo "[snapshot] manifiesto: $MANIFEST"
cat "$MANIFEST"
echo
echo "[snapshot] Restaurar una variante (conserva NVS/WiFi):"
echo "  ./firmware/flash-variant.sh --variant=<CERRADURA|IMAN> --port=$PORT"
echo "[snapshot] Restaurar el estado exacto de la flash (incluye NVS):"
echo "  esptool.py --port=<puerto> --baud 230400 write_flash 0x0 <flash-*.bin>"
