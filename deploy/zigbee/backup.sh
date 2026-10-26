#!/usr/bin/env bash
# Backup local de configuración y datos del proyecto. No imprime contenido.
set -eu
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="$ROOT/backups"

usage() {
  cat <<'USAGE'
Uso: backup.sh [--output-dir DIR]

Crea un tar.gz con data/zigbee2mqtt y la configuración/datos de Mosquitto,
más su sha256. El directorio y los ficheros quedan con permisos restrictivos.
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --output-dir)
      [ $# -ge 2 ] || { echo "ERROR: --output-dir requiere una ruta" >&2; exit 2; }
      OUT_DIR="$2"
      shift 2
      ;;
    -h|--help) usage; exit 0 ;;
    *) echo "ERROR: argumento desconocido: $1" >&2; usage >&2; exit 2 ;;
  esac
done

umask 077
install -d -m 0700 "$OUT_DIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ARCHIVE="$OUT_DIR/zigbee-backup-$STAMP.tar.gz"
TMP_ARCHIVE="$ARCHIVE.tmp.$$"
trap 'rm -f "$TMP_ARCHIVE"' EXIT

PATHS=()
for path in data/zigbee2mqtt data/mosquitto/config data/mosquitto/data; do
  [ -e "$ROOT/$path" ] && PATHS+=("$path")
done
if [ "${#PATHS[@]}" -eq 0 ]; then
  echo "ERROR: no hay datos del proyecto en $ROOT/data; nada que respaldar." >&2
  exit 1
fi

tar -czf "$TMP_ARCHIVE" -C "$ROOT" "${PATHS[@]}"
mv "$TMP_ARCHIVE" "$ARCHIVE"
chmod 0600 "$ARCHIVE"
trap - EXIT

(
  cd "$OUT_DIR"
  sha256sum "$(basename "$ARCHIVE")" > "$(basename "$ARCHIVE").sha256"
)
chmod 0600 "$ARCHIVE.sha256"

echo "Backup creado: $ARCHIVE"
echo "Checksum:      $ARCHIVE.sha256"
echo "Incluye:       ${PATHS[*]}"
echo "No se ha mostrado ningún contenido. Mantén backup y .env fuera de Git."
