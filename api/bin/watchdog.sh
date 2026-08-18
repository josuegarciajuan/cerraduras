#!/usr/bin/env bash
# =============================================================================
# watchdog.sh — Monitorea logs en busca de patrones de error graves.
#
# Se ejecuta como daemon ligero. Cada 60s escanea las últimas líneas de
# los logs principales. Si detecta N errores nuevos, escribe una alerta.
#
# Uso:
#   nohup bash /root/cerraduras/api/bin/watchdog.sh >> logs/watchdog.log 2>&1 &
#
# Alertas en: api/logs/watchdog-alerts.log
# =============================================================================
set -e

LOGDIR="/root/cerraduras/api/logs"
ALERT_FILE="$LOGDIR/watchdog-alerts.log"
SCAN_WINDOW=60           # segundos: cuánto del log mirar
ERROR_THRESHOLD=5         # número de errores para disparar alerta

# Patrones que consideramos graves
CRITICAL_PATTERNS=(
  "FATAL"
  "MySQL server has gone away"
  "Circuit breaker OPEN"
  "PDOException"
  "PHP Fatal error"
  "Uncaught "
  "max retries reached"
  "Connection refused"
  "could not find driver"
)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Watchdog started — scanning logs every ${SCAN_WINDOW}s"

mkdir -p "$(dirname "$ALERT_FILE")"

scan_log() {
  local logfile="$1"
  local since_ts="$2"
  local found=0

  [ -f "$logfile" ] || return

  # Extraer líneas desde el timestamp
  local lines
  lines=$(tail -n 200 "$logfile" 2>/dev/null | grep -iE "$(IFS='|'; echo "${CRITICAL_PATTERNS[*]}")" 2>/dev/null || true)

  if [ -n "$lines" ]; then
    local count
    count=$(echo "$lines" | wc -l)
    if [ "$count" -ge "$ERROR_THRESHOLD" ]; then
      {
        echo "========================================"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERT — $count errores detectados en $logfile"
        echo "---"
        echo "$lines" | tail -20
        echo "---"
      } >> "$ALERT_FILE"
    fi
  fi
}

# Main loop
while true; do
  for logfile in "$LOGDIR"/api.log "$LOGDIR"/php-server.log "$LOGDIR"/exit-scan.log "$LOGDIR"/anomaly-scanner.log "$LOGDIR"/overstay-scan.log "$LOGDIR"/outbox-worker.log "$LOGDIR"/pulsar-consumer.log "$LOGDIR"/presence-poller.log; do
    scan_log "$logfile" 2>/dev/null || true
  done
  sleep "$SCAN_WINDOW"
done
