#!/usr/bin/env bash
# Restaura un backup en un destino NUEVO. Nunca sobrescribe datos vivos.
set -eu
set -o pipefail

usage() {
  cat <<'USAGE'
Uso: restore.sh <archivo.tar.gz> <destino-nuevo>

Verifica el sha256 si existe, rechaza rutas inseguras, pide confirmación
explícita y extrae en un destino que no debe existir. No toca data/ del proyecto
ni detiene contenedores. La promoción es manual tras parar el proyecto.
USAGE
}

[ $# -eq 2 ] || { usage >&2; exit 2; }
ARCHIVE="$1"
DEST="$2"

[ -f "$ARCHIVE" ] || { echo "ERROR: no existe el archivo $ARCHIVE" >&2; exit 1; }
[ -e "$DEST" ] && { echo "ERROR: el destino ya existe: $DEST. Usa un destino nuevo." >&2; exit 1; }

LIST="$ARCHIVE.entries.$$"
trap 'rm -f "$LIST"' EXIT
if ! tar -tzf "$ARCHIVE" > "$LIST" 2>/dev/null; then
  echo "ERROR: el archivo no es un tar.gz legible." >&2
  exit 1
fi
while IFS= read -r entry; do
  case "$entry" in
    ''|/*|..|../*|*/..|*/../*)
      echo "ERROR: el archivo contiene una ruta insegura: $entry" >&2
      exit 1
      ;;
    data|data/*)
      ;;
    *)
      echo "ERROR: el archivo contiene una ruta fuera de data/: $entry" >&2
      exit 1
      ;;
  esac
done < "$LIST"

SHA_FILE="$ARCHIVE.sha256"
if [ -f "$SHA_FILE" ]; then
  if ( cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$SHA_FILE")" >/dev/null 2>&1 ); then
    echo "Checksum verificado."
  else
    echo "ERROR: el checksum no coincide; no se restaura." >&2
    exit 1
  fi
else
  echo "AVISO: no existe $SHA_FILE; se restaura sin verificación de hash."
fi

echo "Se extraerá: $ARCHIVE"
echo "Destino nuevo: $DEST"
printf 'Escribe RESTORE para confirmar: '
read -r answer
[ "$answer" = "RESTORE" ] || { echo "Cancelado." >&2; exit 1; }

umask 077
mkdir -p "$DEST"
tar -xzf "$ARCHIVE" -C "$DEST" --no-same-owner --no-same-permissions
echo "Restauración completada en $DEST (datos vivos intactos)."
echo "IMPORTANTE: los contenedores corren sin root. Tras colocar los datos en data/,"
echo "ajusta manualmente el propietario a los UID/GID configurados en .env:"
echo "  data/mosquitto -> MOSQUITTO_UID:MOSQUITTO_GID"
echo "  data/zigbee2mqtt -> Z2M_UID:Z2M_GID"
echo "restore.sh NO ejecuta chown ni cambia propietarios automáticamente; el paso lo"
echo "decide y ejecuta el administrador del host antes de arrancar el proyecto."
echo "Siguiente paso manual, cuando toque promover:"
echo "  1) ./rollback.sh --yes   (para el proyecto conservando datos)"
echo "  2) mueve/renombra el data actual y coloca el contenido restaurado"
echo "  3) ajusta propietarios de data/ (ver arriba; requiere administrador)"
echo "  4) ./deploy.sh && ./verify.sh"
echo "No se ha tocado el proyecto en ejecución."
