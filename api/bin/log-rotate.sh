#!/usr/bin/env bash
# =============================================================================
# log-rotate.sh — Rota logs de la API que excedan 50 MB.
#
# Uso:
#   bash /root/cerraduras/api/bin/log-rotate.sh
#
# Crontab sugerido (cada dia a las 03:00):
#   0 3 * * * bash /root/cerraduras/api/bin/log-rotate.sh
# =============================================================================
set -e

LOGDIR="/root/cerraduras/api/logs"
KEEP_DAYS=7

[ -d "$LOGDIR" ] || exit 0

rotated=0

for f in "$LOGDIR"/*.log; do
    [ -f "$f" ] || continue
    size=$(stat -c%s "$f" 2>/dev/null || echo 0)
    if [ "$size" -gt 52428800 ]; then  # 50 MB
        echo "[log-rotate] Rotating $f ($(( size / 1048576 )) MB)"
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
