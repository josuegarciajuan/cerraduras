#!/usr/bin/env bash
# =============================================================================
# tuya-start.sh — Arranca la API + consumidor Pulsar Tuya
#
# Uso:
#   bash /root/cerraduras/api/bin/tuya-start.sh
#
# Requisitos:
#   npm install en api/bin/tuya-pulsar-consumer/ ejecutado al menos una vez.
#
# El consumidor se reinicia automáticamente si la conexión Pulsar cae.
# =============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CONSUMER_DIR="$API_DIR/bin/tuya-pulsar-consumer"

echo "=== Tuya — Start All ==="

# --- API ---
echo "[1/3] API on 0.0.0.0:8080..."
pkill -f "php -S.*8080" 2>/dev/null || true
sleep 1
cd "$API_DIR"
php -S 0.0.0.0:8080 -t public public/index.php >> logs/php-server.log 2>&1 &
echo "       PID: $!"

# --- Pulsar consumer (with auto-restart) ---
echo "[2/3] Tuya Pulsar consumer..."
pkill -f "node.*tuya-pulsar-consumer" 2>/dev/null || true
sleep 1

# Wrapper: restart on exit (crash / connection loss)
nohup bash -c "
  cd '$CONSUMER_DIR'
  while true; do
    echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] Starting Tuya Pulsar consumer...\" >> "$API_DIR/logs/pulsar-consumer.log"
    node index.js >> "$API_DIR/logs/pulsar-consumer.log" 2>&1
    echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] Consumer exited — restarting in 3s...\" >> "$API_DIR/logs/pulsar-consumer.log"
    sleep 3
  done
" > /dev/null 2>&1 &
echo "       PID: $!"

sleep 2

# --- Health checks ---
echo "[3/3] Health checks:"
echo -n "  API:     "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/api/v1/health && echo " OK" || echo " FAIL"

echo ""
echo "=== Done ==="
echo "  API:              http://127.0.0.1:8080/api/v1/health"
echo "  Logs API:         $API_DIR/logs/php-server.log"
echo "  Logs Pulsar:      $API_DIR/logs/pulsar-consumer.log"
echo ""
echo "Para parar:"
echo "  pkill -f 'php -S.*8080'"
echo "  pkill -f 'tuya-pulsar-consumer'"
