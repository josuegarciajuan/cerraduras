#!/usr/bin/env bash
# =============================================================================
# worker-loop.sh — Runner supervisado por systemd para los workers de fondo.
#
# F46: sustituye los wrappers `setsid` + PID-file (frágiles, sin supervisión) por
# un proceso en primer plano que systemd mantiene vivo con Restart=always.
#
# Uso:
#   worker-loop.sh <worker>
#
# El unit `cerraduras-worker@<worker>.service` invoca:
#   /bin/bash /root/cerraduras/api/bin/worker-loop.sh %i
#
# Workers soportados (comando + espera entre ejecuciones):
#   exit-scan        php bin/exit-scan.php        2s
#   overstay-scan    php bin/overstay-scan.php   60s
#   outbox-worker    php bin/outbox-worker.php   30s
#   anomaly-scanner  php bin/anomaly-scanner.php  5s
#
# El poller de presencia NO usa este runner: `presence-poller-manager.sh` ya
# itera internamente y tiene su propio unit (`cerraduras-presence-poller`).
# =============================================================================
set -u

API_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$API_DIR"

worker="${1:-}"
case "$worker" in
  exit-scan)       cmd=(php bin/exit-scan.php);       tick=2 ;;
  overstay-scan)   cmd=(php bin/overstay-scan.php);   tick=60 ;;
  outbox-worker)   cmd=(php bin/outbox-worker.php);   tick=30 ;;
  anomaly-scanner) cmd=(php bin/anomaly-scanner.php); tick=5 ;;
  *)
    echo "[worker-loop] worker desconocido: '${worker}'" >&2
    exit 2
    ;;
esac

# Log por worker (systemd captura stdout/stderr al journal si no hay redirección;
# aquí mantenemos el fichero histórico api/logs/<worker>.log).
mkdir -p "$API_DIR/logs"
exec >>"$API_DIR/logs/${worker}.log" 2>&1

echo "[worker-loop] iniciando $worker (tick ${tick}s) — pid $$"

# Cada iteración ejecuta el script una vez y espera `tick`. Si el script es un
# daemon de larga duración, simplemente no retorna; systemd lo reinicia si cae.
while true; do
  "${cmd[@]}"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    echo "[worker-loop] $worker salió con rc=$rc"
  fi
  sleep "$tick"
done
