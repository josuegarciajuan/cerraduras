#!/usr/bin/env bash
# flash-variant.sh — flasheo directo por cable de una variante ESP (F100).
#
# Compila el sketch con su perfil (ota_0/ota_1/otadata), hace un backup del
# flash actual y escribe bootloader + tabla de particiones + boot_app0 + app.
# NO borra la NVS: conserva WiFi, credenciales de fábrica y el marcador de
# build. El binario compilado es el mismo que se publica para OTA.
#
# Uso:
#   ./flash-variant.sh --variant=IMAN|CERRADURA --port=/dev/ttyACM0
#   ./flash-variant.sh --variant=CERRADURA --port=/dev/ttyACM0 --no-backup
#
# Variables útiles (opcionales):
#   ARDUINO_CLI            ruta a arduino-cli (auto: PATH o /tmp/cerraduras-tools)
#   ARDUINO_DIRECTORIES_DATA   p. ej. /home/oficina/.arduino15
#   ARDUINO_DIRECTORIES_USER   p. ej. /home/oficina/Arduino
#   ESPTOOL                ruta a esptool.py para el backup
#   BACKUP_DIR             por defecto /root/onx-backups
set -euo pipefail

VARIANT=""
PORT=""
ARDUINO_CLI="${ARDUINO_CLI:-}"
BACKUP_DIR="${BACKUP_DIR:-/root/onx-backups}"
DO_BACKUP=1
VERIFY=1
PROFILE="${ARDUINO_PROFILE:-esp32s3usbotg}"
ESPTOOL_BAUD="${ESPTOOL_BAUD:-460800}"
FQBN="${ARDUINO_FQBN:-esp32:esp32:esp32s3usbotg}"

usage() {
  cat <<'EOF'
Uso: flash-variant.sh --variant=IMAN|CERRADURA --port=/dev/ttyACM0 [opciones]

  --variant=...        variante a flashear (obligatorio)
  --port=...           puerto serie del ESP (obligatorio)
  --arduino-cli=...    ruta a arduino-cli
  --backup-dir=...     directorio del backup (por defecto /root/onx-backups)
  --no-backup          no leer el flash actual antes de escribir
  --no-verify          no verificar la escritura
  -h, --help           esta ayuda
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --variant=*) VARIANT="${1#*=}" ;;
    --port=*) PORT="${1#*=}" ;;
    --arduino-cli=*) ARDUINO_CLI="${1#*=}" ;;
    --backup-dir=*) BACKUP_DIR="${1#*=}" ;;
    --no-backup) DO_BACKUP=0 ;;
    --no-verify) VERIFY=0 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[flash] argumento desconocido: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$VARIANT" in
  IMAN) SKETCH="esp-iman"; EXPECT_VARIANT="IMAN" ;;
  CERRADURA) SKETCH="esp-cerradura"; EXPECT_VARIANT="CERRADURA" ;;
  *) echo "[flash] --variant debe ser IMAN o CERRADURA" >&2; exit 2 ;;
esac
[ -n "$PORT" ] || { echo "[flash] --port es obligatorio" >&2; exit 2; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKETCH_DIR="$ROOT/$SKETCH"
[ -f "$SKETCH_DIR/sketch.yaml" ] || { echo "[flash] falta $SKETCH_DIR/sketch.yaml" >&2; exit 1; }

if [ -z "$ARDUINO_CLI" ]; then
  if command -v arduino-cli >/dev/null 2>&1; then
    ARDUINO_CLI="$(command -v arduino-cli)"
  elif [ -x /tmp/cerraduras-tools/arduino-cli ]; then
    ARDUINO_CLI="/tmp/cerraduras-tools/arduino-cli"
  else
    echo "[flash] arduino-cli no encontrado (usa --arduino-cli=...)" >&2
    exit 1
  fi
fi

# Versión declarada en el sketch (fuente de verdad para el catálogo OTA).
if [ "$VARIANT" = "IMAN" ]; then
  VERSION="$(sed -n 's/^#define IMAN_VERSION[[:space:]]*"\([^"]*\)".*/\1/p' "$SKETCH_DIR/$SKETCH.ino" | head -1)"
else
  VERSION="$(sed -n 's/^#define OTA_FW_VERSION[[:space:]]*"\([^"]*\)".*/\1/p' "$SKETCH_DIR/$SKETCH.ino" | head -1)"
fi
[ -n "$VERSION" ] || { echo "[flash] no se pudo leer la versión del sketch" >&2; exit 1; }

BUILD="$(mktemp -d "/tmp/fw-${SKETCH}.XXXXXX")"
echo "[flash] variante=$VARIANT version=$VERSION"
echo "[flash] compilando $SKETCH con perfil $PROFILE"
"$ARDUINO_CLI" compile --profile "$PROFILE" --build-path "$BUILD" "$SKETCH_DIR"

BIN="$BUILD/$SKETCH.ino.bin"
[ -f "$BIN" ] || { echo "[flash] no se generó $BIN" >&2; exit 1; }

# El binario debe contener la variante y versión que se van a publicar.
if ! grep -aq "firmware_variant\":\"$EXPECT_VARIANT\"" "$BIN"; then
  echo "[flash] el binario no declara firmware_variant=$EXPECT_VARIANT" >&2
  exit 1
fi
if ! grep -aq "firmware_version\":\"$VERSION\"" "$BIN"; then
  echo "[flash] el binario no declara firmware_version=$VERSION" >&2
  exit 1
fi

SHA="$(sha256sum "$BIN" | awk '{print $1}')"
echo "[flash] bin=$BIN"
echo "[flash] sha256=$SHA"

if [ "$DO_BACKUP" = 1 ]; then
  ESPTOOL_BIN="${ESPTOOL:-}"
  if [ -z "$ESPTOOL_BIN" ]; then
    if command -v esptool.py >/dev/null 2>&1; then
      ESPTOOL_BIN="$(command -v esptool.py)"
    elif command -v esptool >/dev/null 2>&1; then
      ESPTOOL_BIN="$(command -v esptool)"
    fi
  fi
  if [ -z "$ESPTOOL_BIN" ]; then
    echo "[flash] AVISO: esptool no encontrado; no se hace backup (usa --no-backup para silenciarlo)" >&2
  else
    mkdir -p "$BACKUP_DIR"
    BACKUP="$BACKUP_DIR/${SKETCH}-$(date -u +%Y%m%dT%H%M%SZ).bin"
    echo "[flash] backup del flash actual → $BACKUP"
    READ_CMD="read_flash"
    if "$ESPTOOL_BIN" read-flash --help >/dev/null 2>&1; then
      READ_CMD="read-flash"
    fi
    "$ESPTOOL_BIN" --chip esp32s3 --port "$PORT" --baud "$ESPTOOL_BAUD" \
      "$READ_CMD" 0 0x800000 "$BACKUP"
    sha256sum "$BACKUP"
  fi
fi

echo "[flash] escribiendo bootloader + particiones + boot_app0 + app (NVS intacta)"
UPLOAD_ARGS=(-p "$PORT" --fqbn "$FQBN" --input-dir "$BUILD" "$SKETCH_DIR")
if [ "$VERIFY" = 1 ]; then
  UPLOAD_ARGS+=(-t)
fi
"$ARDUINO_CLI" upload "${UPLOAD_ARGS[@]}"

echo "[flash] OK. Espera ~10 s y verifica el heartbeat:"
echo "        firmware_variant=$EXPECT_VARIANT firmware_version=$VERSION ota_capable=true"
echo "[flash] Para publicarlo en el catálogo OTA (desde el repo, con el mismo binario):"
echo "        php api/bin/firmware-publish.php --variant=$EXPECT_VARIANT --version=$VERSION \\"
echo "          --file=$BIN --commit=$(git -C "$ROOT/.." rev-parse --short HEAD 2>/dev/null || echo unknown)"
