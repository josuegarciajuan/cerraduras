#!/usr/bin/env bash
# =============================================================================
# log-rotate.sh — Rota logs de la API que excedan MAX_MB y purga las
#                 rotaciones de más de KEEP_DAYS días.
#
# Uso:
#   bash /root/cerraduras/api/bin/log-rotate.sh
#
# Parametrizable por entorno:
#   LOGDIR     directorio de logs       (def: /root/cerraduras/api/logs)
#   MAX_MB     umbral de rotación en MB (def: 50)
#   KEEP_DAYS  días de retención        (def: 7)
#
# Ejemplo:
#   LOGDIR=/tmp/x MAX_MB=1 KEEP_DAYS=3 bash api/bin/log-rotate.sh
#
# Programación recomendada mediante timer systemd (ver docs/systemd/):
#   cerraduras-logrotate.timer → diario a las 03:30
# =============================================================================
set -e

LOGDIR="${LOGDIR:-/root/cerraduras/api/logs}"
MAX_MB="${MAX_MB:-50}"
KEEP_DAYS="${KEEP_DAYS:-7}"

# MAX_MB debe ser un entero positivo: abortar con mensaje claro si no lo es.
case "$MAX_MB" in
    ''|*[!0-9]*)
        echo "[log-rotate] ERROR: MAX_MB debe ser un entero positivo (recibido: '$MAX_MB')" >&2
        exit 2
        ;;
esac
if [ "$MAX_MB" -le 0 ]; then
    echo "[log-rotate] ERROR: MAX_MB debe ser mayor que 0 (recibido: '$MAX_MB')" >&2
    exit 2
fi

# KEEP_DAYS alimenta `find -mtime "+N"`: debe ser un entero >= 0.
case "$KEEP_DAYS" in
    ''|*[!0-9]*)
        echo "[log-rotate] ERROR: KEEP_DAYS debe ser un entero >= 0 (recibido: '$KEEP_DAYS')" >&2
        exit 2
        ;;
esac

MAX_BYTES=$(( MAX_MB * 1048576 ))

[ -d "$LOGDIR" ] || exit 0

rotated=0

for f in "$LOGDIR"/*.log; do
    [ -f "$f" ] || continue
    size=$(stat -c%s "$f" 2>/dev/null || echo 0)
    if [ "$size" -gt "$MAX_BYTES" ]; then  # MAX_MB (def 50 MB)
        echo "[log-rotate] Rotating $f ($(( size / 1048576 )) MB > ${MAX_MB} MB)"
        # Shift existing rotations: .1 → .2, .2 → .3, …, .6 → .7
        for i in 6 5 4 3 2 1; do
            [ -f "${f}.${i}" ] && mv "${f}.${i}" "${f}.$((i + 1))"
        done
        cp "$f" "${f}.1"
        :> "$f"   # truncate without breaking in-flight writes
        rotated=$((rotated + 1))
    fi
done

# Purge rotated logs older than KEEP_DAYS
purged=$(find "$LOGDIR" -name "*.log.[0-9]*" -type f -mtime "+${KEEP_DAYS}" -delete -print 2>/dev/null | wc -l)

echo "[log-rotate] Done. Rotated: $rotated, purged: $purged"
